<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\DevolucionVentaTenant;
use App\Modules\Tenancy\Models\LineaVentaPosTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Pagos\Exceptions\VentaNoDevolvible;
use App\Modules\Tenancy\Pagos\PartesCobro;
use Illuminate\Support\Facades\DB;

/**
 * Devolución parcial en el mostrador (ADR 0178): algunos artículos o algunas piezas de
 * una venta regresan.
 *
 * - **Cuánto:** cada pieza vale lo que se cobró por ella ese día (su precio con
 *   extras). La propina no se devuelve aquí; se devuelve anulando la venta completa
 *   mientras no tenga devoluciones.
 * - **Por qué forma:** la de la venta. Si fue mixta (ADR 0177), una de sus formas, sin
 *   pasar de lo que entró por ella para lo vendido menos lo ya devuelto por ella.
 * - **Caja:** sale de la caja abierta de la sede en ese momento (ADR 0175); lo cerrado
 *   no cambia.
 * - **Inventario:** las piezas vuelven a la sede donde se vendieron.
 * - **Concurrencia:** se bloquea la venta, después la caja y al final los artículos
 *   (por id), como al vender. Dos devoluciones a la vez no devuelven de más.
 * - **Idempotente:** con la misma llave, la misma devolución.
 */
class DevolverVentaPosTenant
{
    public function __construct(
        private readonly CajasTenant $cajas,
        private readonly InventarioTenant $inventario,
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** Por qué no se puede devolver nada de esta venta, o null si sí. */
    public function impedimento(VentaPosTenant $venta): ?string
    {
        if ($venta->anulada_en !== null) {
            return 'Esta venta está anulada.';
        }
        if ((int) $venta->total_minor <= 0) {
            return 'Esta venta no tiene productos que devolver.';
        }
        $dias = $this->parametros->entero('pos.dias_para_devolver');
        if ($dias > 0 && $venta->created_at !== null && $venta->created_at->lt(now()->subDays($dias))) {
            return "Un producto se devuelve hasta {$dias} días después de la venta.";
        }

        return null;
    }

    /**
     * @param  array<string, int>  $cantidades  ulid de la línea => piezas que regresan
     */
    public function devolver(VentaPosTenant $venta, array $cantidades, string $motivo, ?string $metodo, ?Usuario $actor, ?string $llave = null): DevolucionVentaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($venta, $cantidades, $motivo, $metodo, $actor, $llave): DevolucionVentaTenant {
            $bloqueada = VentaPosTenant::query()->whereKey($venta->getKey())->lockForUpdate()->firstOrFail();
            if ($llave !== null) {
                $repetida = DevolucionVentaTenant::query()->where('venta_pos_id', $bloqueada->getKey())->where('llave', $llave)->first();
                if ($repetida instanceof DevolucionVentaTenant) {
                    return $repetida;
                }
            }
            $impedimento = $this->impedimento($bloqueada);
            if ($impedimento !== null) {
                throw new VentaNoDevolvible($impedimento);
            }

            // Lo que regresa de cada línea: no más de lo que queda sin devolver.
            $lineas = LineaVentaPosTenant::query()->where('venta_pos_id', $bloqueada->getKey())->get()->keyBy('ulid');
            $regresan = [];
            $monto = 0;
            foreach ($cantidades as $ulid => $cantidad) {
                $linea = $lineas->get((string) $ulid);
                if (! $linea instanceof LineaVentaPosTenant) {
                    throw new VentaNoDevolvible('Uno de los artículos no es de esta venta.');
                }
                $quedan = (int) $linea->cantidad - (int) $linea->cantidad_devuelta;
                if ($cantidad < 1 || $cantidad > $quedan) {
                    throw new VentaNoDevolvible("De «{$linea->articulo?->nombre}» quedan {$quedan} por devolver.");
                }
                // Su precio por pieza (con extras) es exacto: el subtotal es precio × cantidad.
                $importe = intdiv((int) $linea->subtotal_minor, max(1, (int) $linea->cantidad)) * $cantidad;
                $regresan[] = [$linea, $cantidad, $importe];
                $monto += $importe;
            }
            if ($regresan === []) {
                throw new VentaNoDevolvible('Elige qué artículos regresan.');
            }
            $salePor = $this->formaDeSalida($bloqueada, $monto, $metodo);

            // Sale de la caja abierta de la sede, bloqueada antes de los artículos.
            $turno = $this->cajas->turnoParaMovimiento((int) $bloqueada->sucursal_id);
            $devolucion = DevolucionVentaTenant::query()->create([
                'venta_pos_id' => $bloqueada->getKey(),
                'monto_minor' => $monto,
                'metodo' => $salePor,
                'motivo' => mb_substr($motivo, 0, 255),
                'usuario_id' => $actor?->getKey(),
                'llave' => $llave,
            ]);
            $this->cajas->asentar($turno, MovimientoCajaTenant::DEVOLUCION, $salePor, -$monto, ['venta_pos_id' => $bloqueada->getKey()], $actor, $motivo);

            // Las piezas vuelven al inventario de la sede, con los artículos bloqueados en
            // el mismo orden que al vender (por id).
            ArticuloTenant::query()
                ->whereIn('id', array_map(static fn (array $r): int => (int) $r[0]->articulo_id, $regresan))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($regresan as [$linea, $cantidad, $importe]) {
                $devolucion->lineas()->create(['linea_venta_pos_id' => $linea->getKey(), 'cantidad' => $cantidad, 'monto_minor' => $importe]);
                $linea->update(['cantidad_devuelta' => (int) $linea->cantidad_devuelta + $cantidad]);
                $this->inventario->registrar(
                    (int) $linea->articulo_id,
                    (int) $bloqueada->sucursal_id,
                    $cantidad,
                    TipoMovimientoInventario::Entrada,
                    'Devolución de venta',
                    $actor,
                    (int) $bloqueada->getKey(),
                );
            }

            $this->auditoria->registrar($actor, 'pos.venta_devuelta', 'venta_pos', (string) $bloqueada->ulid, null, [
                'devolucion' => (string) $devolucion->ulid,
                'monto_minor' => $monto,
                'moneda' => $bloqueada->moneda,
                'metodo' => $salePor,
                'piezas' => array_sum(array_map(static fn (array $r): int => $r[1], $regresan)),
            ], $motivo);

            return $devolucion->refresh();
        });
    }

    /**
     * Por qué forma sale el dinero: la de la venta. De una venta mixta, la elegida; no
     * puede pasar de lo que entró por ella para lo vendido menos lo ya devuelto por ella.
     */
    private function formaDeSalida(VentaPosTenant $venta, int $monto, ?string $metodo): string
    {
        if ($venta->metodo_pago !== PartesCobro::MIXTO) {
            return CajasTenant::metodo($venta->metodo_pago);
        }
        [$deVenta] = PartesCobro::repartir(CajasTenant::partesDeVenta($venta), [(int) $venta->total_minor, (int) $venta->propina_minor]);
        $parte = collect($deVenta)->firstWhere('metodo', $metodo);
        if ($metodo === null || $parte === null) {
            throw new VentaNoDevolvible('Se cobró con varias formas de pago: elige por cuál de ellas sale la devolución.');
        }
        $devuelto = (int) DevolucionVentaTenant::query()->where('venta_pos_id', $venta->getKey())->where('metodo', $metodo)->sum('monto_minor');
        if ($monto > $parte['monto_minor'] - $devuelto) {
            throw new VentaNoDevolvible('Por esa forma de pago no entró tanto: elige otra o devuelve menos.');
        }

        return $metodo;
    }
}
