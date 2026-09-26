<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
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
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->first();
            if (! $bloqueado instanceof PagoTenant
                || ! in_array($bloqueado->estado, [EstadoPago::Pendiente, EstadoPago::Rechazado], true)) {
                return [];
            }
            $orden = OrdenTenant::query()->whereKey($bloqueado->orden_id)->lockForUpdate()->first();

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
