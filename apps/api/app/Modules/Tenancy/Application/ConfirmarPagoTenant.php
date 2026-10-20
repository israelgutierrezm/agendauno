<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use Illuminate\Support\Facades\DB;

/**
 * Confirma un pago tenant-local a partir de la referencia del intento (la que envía
 * el webhook de la pasarela). El dinero cobrado queda SIEMPRE registrado (pago
 * aprobado); qué pasa con su compra depende de cómo esté:
 * - pendiente → se entrega (fulfillment) y se cierran los demás intentos abiertos;
 * - ya pagada con otro intento → cobro doble: queda por conciliar para devolverlo;
 * - cancelada (p. ej. venció el apartado de la cita) → pago tardío: se reconfirma si
 *   el horario sigue libre o queda por conciliar ({@see PagoTardioTenant}). Una
 *   orden cancelada nunca pasa a pagada sin revalidar.
 * Idempotente: solo se procesa un intento pendiente o cerrado (rechazado); uno ya
 * aprobado o devuelto no se reabre aunque el aviso se repita.
 */
class ConfirmarPagoTenant
{
    public function __construct(
        private readonly FulfillmentTenant $fulfillment,
        private readonly CerrarIntentosPagoTenant $intentos,
        private readonly PagoTardioTenant $tardio,
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * La pasarela confirma que ESTE pago se cobró (con la referencia de su cobro).
     */
    public function aprobar(PagoTenant $pago, string $referencia): void
    {
        $abiertos = DB::connection('tenant')->transaction(function () use ($pago, $referencia): array {
            // Primero la orden y después el pago, como el cobro en caja: en orden inverso,
            // el aviso y la caja con la misma orden se bloquearían entre sí (deadlock).
            $ordenId = PagoTenant::query()->whereKey($pago->getKey())->value('orden_id');
            $orden = $ordenId !== null ? OrdenTenant::query()->whereKey($ordenId)->lockForUpdate()->first() : null;
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->first();
            if (! $bloqueado instanceof PagoTenant
                || ! in_array($bloqueado->estado, [EstadoPago::Pendiente, EstadoPago::Rechazado], true)) {
                return [];
            }

            $bloqueado->update([
                'estado' => EstadoPago::Aprobado->value,
                'referencia_externa' => $referencia !== '' ? $referencia : $bloqueado->referencia_externa,
                'aprobado_en' => now(),
            ]);
            if (! $orden instanceof OrdenTenant) {
                return [];
            }

            if ($orden->estado === EstadoOrden::Pendiente) {
                // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
                $this->fulfillment->cumplir($orden);
                // Se cobró más de lo que ya debe (p. ej. se exentó la inscripción después de
                // abrir el pago): lo de más queda por conciliar para devolverlo.
                $excedente = (int) $bloqueado->monto_minor - (int) $orden->total_minor;
                if ($excedente > 0) {
                    $this->incidencias->porPago(
                        IncidenciaCobroTenant::MONTO_DISTINTO,
                        $bloqueado,
                        'Se cobró '.DatosDeOrden::dinero($excedente, (string) ($bloqueado->moneda ?: 'MXN')).' de más sobre lo que debía la compra. Devuelve la diferencia.',
                        ['excedente_minor' => $excedente],
                    );
                }

                return PagoTenant::query()
                    ->where('orden_id', $orden->getKey())
                    ->whereKeyNot($bloqueado->getKey())
                    ->where('estado', EstadoPago::Pendiente->value)
                    ->get()
                    ->all();
            }

            if ($orden->estado === EstadoOrden::Pagada) {
                $this->cobroDoble($bloqueado, $orden);

                return [];
            }

            $this->tardio->atender($bloqueado, $orden);

            return [];
        });

        foreach ($abiertos as $abierto) {
            $this->intentos->cerrar($abierto);
        }
    }

    public function porReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        $pago = PagoTenant::query()->where('referencia_externa', $referencia)->first();
        if ($pago instanceof PagoTenant) {
            $this->aprobar($pago, $referencia);
        }
    }

    /**
     * Otro cobro aprobado del mismo intento, que ya quedó pagado con el suyo (Mercado
     * Pago: el cliente pagó con tarjeta y después también el ticket de OXXO de la misma
     * preferencia). Ese dinero se registra como un pago aparte de la misma orden y se
     * atiende como cualquier confirmación: con la orden ya pagada, es un cobro doble por
     * devolver. Idempotente: el aviso repetido encuentra el pago por su referencia.
     */
    public function otroCobro(PagoTenant $intento, string $referencia, int $montoMinor, string $moneda, MetodoPago $metodo): void
    {
        if ($referencia === '' || $montoMinor <= 0) {
            return;
        }

        $pago = DB::connection('tenant')->transaction(function () use ($intento, $referencia, $montoMinor, $moneda, $metodo): PagoTenant {
            // El candado del intento ordena los avisos repetidos del mismo cobro.
            PagoTenant::query()->whereKey($intento->getKey())->lockForUpdate()->first();
            $registrado = PagoTenant::query()
                ->where('proveedor', $intento->proveedor)
                ->where('referencia_externa', $referencia)
                ->first();

            return $registrado ?? PagoTenant::query()->create([
                'orden_id' => $intento->orden_id,
                'proveedor' => $intento->proveedor,
                'metodo' => $metodo->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $montoMinor,
                'moneda' => strtoupper($moneda),
                'referencia_externa' => $referencia,
            ]);
        });

        $this->aprobar($pago, $referencia);
    }

    /**
     * El aviso de un cargo automático (pago domiciliado): si su pago no quedó registrado
     * porque la llamada a la pasarela no respondió (el intento se deshizo, pero el banco
     * sí cobró), se registra con su orden: la misma domiciliación y el mismo importe y
     * moneda que debe la compra. Si ya estaba pagada, queda como cobro doble por
     * devolver. Idempotente: el segundo aviso encuentra el pago por su referencia.
     */
    public function porCargoDomiciliado(string $referencia, string $ordenUlid, string $domiciliacionUlid, int $montoMinor, string $moneda): void
    {
        if ($referencia === '') {
            return;
        }

        $pago = DB::connection('tenant')->transaction(function () use ($referencia, $ordenUlid, $domiciliacionUlid, $montoMinor, $moneda): ?PagoTenant {
            // El candado de la orden espera al cargo que la está cobrando (lo registra
            // con la orden bloqueada): sin él, el aviso que llega antes de que termine no
            // vería su pago y registraría otro con la misma referencia.
            $orden = $ordenUlid !== '' ? OrdenTenant::query()->where('ulid', $ordenUlid)->lockForUpdate()->first() : null;
            $registrado = PagoTenant::query()->where('referencia_externa', $referencia)->first();
            if ($registrado instanceof PagoTenant) {
                return $registrado;
            }
            $domiciliacion = $domiciliacionUlid !== '' ? DomiciliacionTenant::query()->where('ulid', $domiciliacionUlid)->first() : null;
            if (! $orden instanceof OrdenTenant || ! $domiciliacion instanceof DomiciliacionTenant
                || (int) $orden->total_minor !== $montoMinor || strtoupper((string) $orden->moneda) !== strtoupper($moneda)) {
                return null;
            }

            return PagoTenant::query()->create([
                'orden_id' => $orden->getKey(),
                'proveedor' => $domiciliacion->proveedor,
                'metodo' => MetodoPago::Tarjeta->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $montoMinor,
                'moneda' => strtoupper($moneda),
                'domiciliacion_id' => $domiciliacion->getKey(),
                'referencia_externa' => $referencia,
            ]);
        });

        if ($pago instanceof PagoTenant) {
            $this->aprobar($pago, $referencia);
        }
    }

    /**
     * El intento ya no se puede pagar (la sesión venció o el pago en tienda no se
     * completó): queda rechazado. La orden sigue pendiente y se puede reintentar.
     * Idempotente.
     */
    public function rechazarPorReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        PagoTenant::query()
            ->where('referencia_externa', $referencia)
            ->where('estado', EstadoPago::Pendiente->value)
            ->update(['estado' => EstadoPago::Rechazado->value]);
    }

    /**
     * La compra ya estaba pagada con otro intento: este cobro sobra. Queda por
     * conciliar para devolverlo, y el equipo que ve la facturación se entera.
     */
    private function cobroDoble(PagoTenant $pago, OrdenTenant $orden): void
    {
        $monto = DatosDeOrden::dinero((int) $pago->monto_minor, (string) ($pago->moneda ?: 'MXN'));

        $this->incidencias->porPago(
            IncidenciaCobroTenant::PAGO_DUPLICADO,
            $pago,
            "Se cobró dos veces la misma compra ({$monto}). Devuelve este cobro.",
        );
        $orden->loadMissing('persona');
        $this->eventos->registrar('pago.duplicado', 'pago', (string) $pago->ulid, [
            'persona_id' => $orden->persona?->ulid,
            'monto' => $monto,
            'orden_id' => (string) $orden->ulid,
        ]);
    }
}
