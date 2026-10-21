<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\PasarelaStripePlataforma;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirma un cargo de renta pendiente a partir de la referencia del intento (la que
 * envia el webhook de la pasarela de la plataforma) y lo marca `pagado`.
 * Idempotente: un cargo que no esta pendiente no se reprocesa. Si el negocio estaba
 * suspendido por renta y ya no debe, se reactiva al momento (ADR 0073).
 */
class ConfirmarCargoRenta
{
    public function __construct(
        private readonly SuspensionPorRenta $suspension,
        private readonly AcreditarTimbresPagados $timbres,
        private readonly AcreditarMensajesWhatsAppPagados $whatsapp,
        private readonly DomiciliacionRenta $domiciliacion,
        private readonly AlertasPlataforma $alertas,
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly PasarelaStripePlataforma $stripe,
    ) {}

    /**
     * `$cargoUlid` (el `metadata.cargo_renta` del aviso de Stripe): si ningún cargo
     * tiene esa referencia (p. ej. Stripe cobró pero no se alcanzó a guardar), se busca
     * el cargo por su ULID; con `$montoMinor`, solo si el importe coincide.
     */
    public function porReferencia(string $referencia, string $proveedor, ?string $cargoUlid = null, ?int $montoMinor = null): void
    {
        if ($referencia === '') {
            return;
        }

        $abierta = null;
        $pagado = DB::transaction(function () use ($referencia, $proveedor, $cargoUlid, $montoMinor, &$abierta): ?CargoRenta {
            $cargo = CargoRenta::query()
                ->where('referencia_pago', $referencia)
                ->lockForUpdate()
                ->first();

            if (! $cargo instanceof CargoRenta && $cargoUlid !== null && $cargoUlid !== '') {
                $cargo = CargoRenta::query()->where('ulid', $cargoUlid)->lockForUpdate()->first();
                if ($cargo instanceof CargoRenta && $montoMinor !== null && $montoMinor !== $cargo->monto_minor) {
                    Log::warning('Aviso de pago de renta con un importe distinto al del cargo: no se aplica.', [
                        'cargo' => $cargo->ulid, 'referencia' => $referencia, 'monto_minor' => $montoMinor,
                    ]);

                    return null;
                }
                if ($cargo instanceof CargoRenta && $cargo->estado !== EstadoCargoRenta::Pendiente
                    && (string) $cargo->referencia_pago !== $referencia) {
                    // Otro pago del mismo cargo: se cobró dos veces y hay que devolverlo.
                    Log::warning('Pago de renta de un cargo que ya no está pendiente.', [
                        'cargo' => $cargo->ulid, 'referencia' => $referencia, 'estado' => $cargo->estado->value,
                    ]);
                    $this->alertas->registrar(
                        'renta_cobrada_dos_veces',
                        $cargo->ulid.':'.$referencia,
                        "La renta {$cargo->periodo} se cobró dos veces (ya estaba {$cargo->estado->value}; el otro cobro es {$referencia}). Devuélvelo desde el panel de {$proveedor}.",
                        $cargo->estudio?->slug,
                    );
                }
            }

            if (! $cargo instanceof CargoRenta || $cargo->estado !== EstadoCargoRenta::Pendiente) {
                return null;
            }

            // Se pagó con otro intento: la sesión que seguía abierta se vence después.
            $previa = (string) $cargo->referencia_pago;
            if ($previa !== '' && $previa !== $referencia && str_starts_with($previa, 'cs_')) {
                $abierta = $previa;
            }
            $cargo->update([
                'estado' => EstadoCargoRenta::Pagado->value,
                'pagado_en' => Carbon::now(),
                'metodo_pago' => $proveedor,
                'referencia_pago' => $referencia,
            ]);

            return $cargo;
        });

        if ($abierta !== null) {
            try {
                $this->stripe->cancelarIntento($abierta, $this->registro->llaves('stripe'));
            } catch (Throwable $e) {
                // Si no se vence y se paga, su aviso alerta del cobro doble.
                ReporteDeErrores::reportarAtrapado($e);
            }
        }

        $this->suspension->reactivarSiPago($pagado?->estudio);
        // Una compra de timbres o de mensajes: se suman al saldo del negocio.
        $this->timbres->aplicar($pagado);
        $this->whatsapp->aplicar($pagado);
        // El primer mes pagado de un negocio invitado: un mes gratis para los dos (ADR 0188).
        app(ReferidosSaas::class)->alPagar($pagado);
    }

    /**
     * El intento de pago de la renta ya no se puede pagar (sesión vencida, pago en
     * tienda no completado, cobro a la tarjeta que falló): el cargo sigue pendiente,
     * sin intento en curso. Una compra de timbres, en cambio, se cancela (se compra
     * otra cuando haga falta). Un cobro a la tarjeta domiciliada (`pi_…`) que quedó en
     * proceso y falló cuenta como un rechazo: el siguiente intento va con otra llave
     * de idempotencia y a su día de reintento (`$codigo`: el motivo de Stripe).
     */
    public function intentoTerminado(string $referencia, ?string $codigo = null): void
    {
        if ($referencia === '') {
            return;
        }

        DB::transaction(function () use ($referencia, $codigo): void {
            $cargos = CargoRenta::query()
                ->where('referencia_pago', $referencia)
                ->where('estado', EstadoCargoRenta::Pendiente->value)
                ->lockForUpdate()
                ->get();
            foreach ($cargos as $cargo) {
                if (in_array($cargo->concepto, ['timbres', 'whatsapp'], true)) {
                    $cargo->update(['estado' => EstadoCargoRenta::Cancelado->value]);

                    continue;
                }
                $cargo->update(['referencia_pago' => null]);
                if (str_starts_with($referencia, 'pi_')) {
                    $this->domiciliacion->registrarRechazo($cargo, $codigo ?? 'rechazado');
                }
            }
        });
    }
}
