<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\Exceptions\CobroNoAnulable;
use Illuminate\Support\Facades\DB;

/**
 * Anula un cobro en caja registrado por error: el dinero nunca entró (ADR 0087). No es
 * una devolución (que sí saca dinero de la caja): el pago queda `anulado`, la orden
 * vuelve a `pendiente` (por cobrar, se puede cobrar otra vez) y lo que el cobro
 * concedió se retira:
 *
 * - cita o reserva: la reserva sigue confirmada; solo vuelve a estar por cobrar;
 * - venta de un plan o paquete: cada derecho vuelve a 0 con un `reverso` en el ledger
 *   y el acuerdo se cancela ({@see DerechosDeOrdenTenant}); exige que no se hayan
 *   usado.
 *
 * Solo si el negocio lo permite (`pagos.permitir_anular_cobro`, apagado de inicio) y
 * dentro del plazo (`pagos.horas_para_anular`), en un cobro en caja aprobado, sin
 * devoluciones, que no sea de una renovación (su efecto es el ciclo de la membresía) y
 * sin factura timbrada. Todo bajo candado del pago y de la orden. Emite `pago.anulado`
 * (outbox: los puntos de la compra se retiran, webhooks y automatizaciones se
 * enteran) y queda en la bitácora con el motivo.
 */
class AnularCobroTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly DerechosDeOrdenTenant $derechos,
        private readonly RegistrarEventoTenant $eventos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * Por qué no se puede anular este cobro, o null si sí se puede. Sin `completo` no
     * revisa factura ni uso de créditos (para listas: sin consultas por fila); eso se
     * valida al anular.
     */
    public function impedimento(PagoTenant $pago, bool $completo = true): ?string
    {
        if (! $this->parametros->siNo('pagos.permitir_anular_cobro')) {
            return 'Este negocio no permite anular cobros.';
        }
        if ($pago->proveedor !== 'manual') {
            return 'Solo se anula un cobro registrado en caja; un pago en línea se devuelve.';
        }
        if ($pago->estado !== EstadoPago::Aprobado) {
            return 'Este cobro ya tiene devoluciones o ya se anuló.';
        }
        $horas = $this->parametros->entero('pagos.horas_para_anular');
        if ($horas > 0 && $pago->created_at !== null && $pago->created_at->lt(now()->subHours($horas))) {
            return "Un cobro se anula hasta {$horas} h después de registrarlo.";
        }
        $orden = $pago->orden;
        if (! $orden instanceof OrdenTenant) {
            return 'Este cobro no tiene una venta que anular.';
        }
        if ($orden->renueva_acuerdo_id !== null) {
            return 'Un cobro de renovación no se anula: su efecto es el ciclo de la membresía. Usa la devolución.';
        }
        if (! $completo) {
            return null;
        }
        $facturada = FacturaTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('estado', EstadoFactura::Timbrada->value)
            ->exists();
        if ($facturada) {
            return 'La venta ya está facturada: primero se cancela el CFDI.';
        }
        if ($this->derechos->algunoUsado($orden)) {
            return 'Los créditos o la membresía de esta venta ya se usaron; usa la devolución.';
        }

        return null;
    }

    public function anular(PagoTenant $pago, ?Usuario $actor, string $motivo): PagoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($pago, $actor, $motivo): PagoTenant {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            $orden = OrdenTenant::query()->whereKey($bloqueado->orden_id)->lockForUpdate()->firstOrFail();
            $bloqueado->setRelation('orden', $orden);

            $motivoNo = $this->impedimento($bloqueado);
            if ($motivoNo !== null) {
                throw new CobroNoAnulable($motivoNo);
            }

            $antes = [
                'pago' => $bloqueado->estado->value,
                'orden' => $orden->estado->value,
                'metodo' => $orden->metodo_pago,
            ];

            $this->derechos->retirarTodo(
                $orden,
                ContextoMovimiento::para(OrigenMovimiento::Anulacion, 'pago', (string) $bloqueado->ulid, $actor),
                'Cobro anulado',
                $actor,
            );
            $bloqueado->update(['estado' => EstadoPago::Anulado->value]);
            $orden->update([
                'estado' => EstadoOrden::Pendiente->value,
                'pagada_en' => null,
                'metodo_pago' => null,
                'referencia_pago' => null,
            ]);

            $orden->loadMissing('persona');
            $this->eventos->registrar('pago.anulado', 'pago', (string) $bloqueado->ulid, [
                'orden_id' => (string) $orden->ulid,
                'persona_id' => $orden->persona?->ulid,
                'monto_minor' => $bloqueado->monto_minor,
                'moneda' => $bloqueado->moneda,
                'motivo' => $motivo,
            ]);
            $this->auditoria->registrar(
                $actor,
                'pago.anulado',
                'pago',
                (string) $bloqueado->ulid,
                $antes,
                ['pago' => EstadoPago::Anulado->value, 'orden' => EstadoOrden::Pendiente->value, 'monto_minor' => $bloqueado->monto_minor, 'moneda' => $bloqueado->moneda],
                $motivo,
            );

            return $bloqueado->refresh();
        });
    }
}
