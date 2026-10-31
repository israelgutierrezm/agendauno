<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Condonar la mora de una membresía (ADR 0231): el negocio perdona ese periodo sin cobrar
 * nada, a diferencia de «Registrar pago», que cobra la renovación por caja. Anula la
 * renovación pendiente, avanza la fecha, cierra la mora y deja en la bitácora quién y por
 * qué. No toca la caja. Un cobro en línea abierto de esa renovación se cierra; si alcanza
 * a pagarse, se atiende como pago tardío.
 */
class CondonarAdeudoTenant
{
    public function __construct(
        private readonly RenovacionPagadaTenant $renovacion,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly CerrarIntentosPagoTenant $intentos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function ejecutar(AcuerdoTenant $acuerdo, string $motivo, ?Usuario $actor): ProcesoDunningTenant
    {
        $ordenes = collect();
        $proceso = DB::connection('tenant')->transaction(function () use ($acuerdo, $motivo, $actor, &$ordenes): ProcesoDunningTenant {
            // Las órdenes antes que la membresía: como al pagarla (ADR 0230).
            $ordenes = OrdenTenant::query()
                ->where('renueva_acuerdo_id', $acuerdo->getKey())
                ->where('estado', EstadoOrden::Pendiente->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $bloqueado = AcuerdoTenant::query()->whereKey($acuerdo->getKey())->lockForUpdate()->firstOrFail();

            $abierto = ProcesoDunningTenant::query()
                ->where('acuerdo_id', $bloqueado->getKey())
                ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
                ->lockForUpdate()
                ->first();
            if (! $abierto instanceof ProcesoDunningTenant) {
                throw ValidationException::withMessages(['acuerdo' => ['No está en mora: no hay nada que condonar.']]);
            }

            $venciaEl = $bloqueado->proxima_cobro_en?->toDateString();
            $estadoMora = $abierto->estado->value;
            $this->deudas->anular([$bloqueado->getKey()], $actor);
            $this->renovacion->registrar($bloqueado);
            $this->auditoria->registrar($actor, 'membresia.adeudo_condonado', 'acuerdo', (string) $bloqueado->ulid,
                ['vence_el' => $venciaEl, 'estado_mora' => $estadoMora],
                [
                    'proxima_cobro_en' => $bloqueado->proxima_cobro_en?->toDateString(),
                    'ordenes' => $ordenes->pluck('ulid')->all(),
                    'total_minor' => (int) $ordenes->sum('total_minor'),
                ],
                $motivo,
            );

            return $abierto->refresh();
        });

        foreach ($ordenes as $orden) {
            $this->intentos->deOrden((int) $orden->getKey());
        }

        return $proceso;
    }
}
