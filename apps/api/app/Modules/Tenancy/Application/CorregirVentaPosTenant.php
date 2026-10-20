<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Pagos\Exceptions\CobroNoAnulable;
use App\Modules\Tenancy\Pagos\Exceptions\CobroNoCorregible;
use Illuminate\Support\Facades\DB;

/**
 * Corregir o anular una venta de mostrador registrada por error (ADR 0089), con las
 * mismas reglas que un cobro en caja (ADR 0086/0087):
 *
 * - **Forma de pago:** si el negocio lo permite (`pagos.permitir_corregir_metodo`) y
 *   dentro del plazo (`pagos.horas_para_corregir`). El total no cambia.
 * - **Anular:** si el negocio lo permite (`pagos.permitir_anular_cobro`) y dentro del
 *   plazo (`pagos.horas_para_anular`), con motivo. La venta queda anulada (no cuenta
 *   en el corte ni en los movimientos) y lo vendido regresa al inventario de su sede.
 *
 * Ambas quedan en la bitácora con quién, cuándo, el antes y el motivo.
 */
final class CorregirVentaPosTenant
{
    /** Formas de pago del mostrador. */
    public const METODOS = ['efectivo', 'tarjeta', 'transferencia'];

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly InventarioTenant $inventario,
        private readonly RegistrarAuditoria $auditoria,
        private readonly CajasTenant $cajas,
    ) {}

    /** Por qué no se puede corregir su forma de pago, o null si sí. */
    public function impedimentoMetodo(VentaPosTenant $venta): ?string
    {
        if (! $this->parametros->siNo('pagos.permitir_corregir_metodo')) {
            return 'Este negocio no permite corregir la forma de pago de un cobro.';
        }
        if ($venta->anulada_en !== null) {
            return 'Esta venta está anulada.';
        }
        $horas = $this->parametros->entero('pagos.horas_para_corregir');
        if ($horas > 0 && $venta->created_at !== null && $venta->created_at->lt(now()->subHours($horas))) {
            return "La forma de pago se corrige hasta {$horas} h después de la venta.";
        }

        return null;
    }

    /** Por qué no se puede anular, o null si sí. */
    public function impedimentoAnular(VentaPosTenant $venta): ?string
    {
        if (! $this->parametros->siNo('pagos.permitir_anular_cobro')) {
            return 'Este negocio no permite anular cobros.';
        }
        if ($venta->anulada_en !== null) {
            return 'Esta venta ya está anulada.';
        }
        $horas = $this->parametros->entero('pagos.horas_para_anular');
        if ($horas > 0 && $venta->created_at !== null && $venta->created_at->lt(now()->subHours($horas))) {
            return "Una venta se anula hasta {$horas} h después de registrarla.";
        }

        return null;
    }

    public function corregirMetodo(VentaPosTenant $venta, string $metodo, ?Usuario $actor, ?string $motivo = null): VentaPosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($venta, $metodo, $actor, $motivo): VentaPosTenant {
            $bloqueada = VentaPosTenant::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();
            $impedimento = $this->impedimentoMetodo($bloqueada);
            if ($impedimento !== null) {
                throw new CobroNoCorregible($impedimento);
            }
            $antes = (string) $bloqueada->metodo_pago;
            if ($antes === $metodo) {
                return $bloqueada;
            }
            // En la caja abierta de hoy (ADR 0175): sale de una forma de pago y entra en otra.
            if (CajasTenant::metodo($antes) !== CajasTenant::metodo($metodo)) {
                $turno = $this->cajas->turnoParaMovimiento((int) $bloqueada->sucursal_id);
                $monto = (int) $bloqueada->total_minor + (int) $bloqueada->propina_minor;
                $origen = ['venta_pos_id' => $bloqueada->getKey()];
                $this->cajas->asentar($turno, MovimientoCajaTenant::CORRECCION, CajasTenant::metodo($antes), -$monto, $origen, $actor, $motivo);
                $this->cajas->asentar($turno, MovimientoCajaTenant::CORRECCION, CajasTenant::metodo($metodo), $monto, $origen, $actor, $motivo);
            }
            $bloqueada->update(['metodo_pago' => $metodo]);
            $this->auditoria->registrar($actor, 'pos.metodo_corregido', 'venta_pos', (string) $bloqueada->ulid,
                ['metodo' => $antes],
                ['metodo' => $metodo, 'total_minor' => $bloqueada->total_minor, 'moneda' => $bloqueada->moneda],
                $motivo,
            );

            return $bloqueada->refresh();
        });
    }

    public function anular(VentaPosTenant $venta, string $motivo, ?Usuario $actor): VentaPosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($venta, $motivo, $actor): VentaPosTenant {
            $bloqueada = VentaPosTenant::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();
            $impedimento = $this->impedimentoAnular($bloqueada);
            if ($impedimento !== null) {
                throw new CobroNoAnulable($impedimento);
            }
            // Sale de la caja abierta de hoy (ADR 0175), bloqueada antes del inventario.
            $turno = $this->cajas->turnoParaMovimiento((int) $bloqueada->sucursal_id);
            $this->cajas->asentar($turno, MovimientoCajaTenant::ANULACION, CajasTenant::metodo((string) $bloqueada->metodo_pago),
                -((int) $bloqueada->total_minor + (int) $bloqueada->propina_minor), ['venta_pos_id' => $bloqueada->getKey()], $actor, $motivo);
            // Lo vendido regresa al inventario de la sede donde se vendió.
            foreach ($bloqueada->lineas as $linea) {
                $this->inventario->registrar(
                    (int) $linea->articulo_id,
                    (int) $bloqueada->sucursal_id,
                    (int) $linea->cantidad,
                    TipoMovimientoInventario::Entrada,
                    'Venta anulada',
                    $actor,
                    (int) $bloqueada->getKey(),
                );
            }
            $bloqueada->update([
                'anulada_en' => now(),
                'anulada_por' => $actor?->getKey(),
                'motivo_anulacion' => $motivo,
            ]);
            $this->auditoria->registrar($actor, 'pos.venta_anulada', 'venta_pos', (string) $bloqueada->ulid,
                ['total_minor' => $bloqueada->total_minor, 'metodo' => $bloqueada->metodo_pago],
                ['anulada' => true],
                $motivo,
            );

            return $bloqueada->refresh();
        });
    }
}
