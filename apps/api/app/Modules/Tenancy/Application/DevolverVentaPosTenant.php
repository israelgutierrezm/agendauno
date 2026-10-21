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
 * - **Por qué forma:** la de la venta. Si fue mixta (ADR 0177), primero la elegida y, si
 *   por ella no alcanza, el resto por las otras formas de la venta. Ninguna pasa de lo
 *   que entró por ella para lo vendido menos lo ya devuelto por ella; el reparto queda
 *   en la devolución y cada parte sale de la caja por su forma.
 * - **Caja:** sale de la caja abierta de la sede en ese momento (ADR 0175); lo cerrado
 *   no cambia.
 * - **Inventario:** las piezas vuelven a la sede donde se vendieron.
 * - **Concurrencia:** se bloquea la venta, después la caja y al final los artículos
 *   (por id), como al vender. Dos devoluciones a la vez no devuelven de más.
 * - **Idempotente:** con la misma llave y las mismas piezas, la misma devolución. La
 *   misma llave con otras piezas se rechaza: no se informa un importe que no salió.
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
                    if (! $this->mismasPiezas($repetida, $cantidades)) {
                        throw new VentaNoDevolvible('Ese intento ya registró una devolución con otras piezas: vuelve a abrir la venta para ver lo que quedó.');
                    }

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
            $partes = $this->formasDeSalida($bloqueada, $monto, $metodo);
            $salePor = count($partes) === 1 ? $partes[0]['metodo'] : PartesCobro::MIXTO;

            // Sale de la caja abierta de la sede, bloqueada antes de los artículos.
            $turno = $this->cajas->turnoParaMovimiento((int) $bloqueada->sucursal_id);
            $devolucion = DevolucionVentaTenant::query()->create([
                'venta_pos_id' => $bloqueada->getKey(),
                'monto_minor' => $monto,
                'metodo' => $salePor,
                'motivo' => mb_substr($motivo, 0, 255),
                'usuario_id' => $actor?->getKey(),
                'llave' => $llave,
                'partes' => $partes,
            ]);
            foreach ($partes as $parte) {
                $this->cajas->asentar($turno, MovimientoCajaTenant::DEVOLUCION, $parte['metodo'], -$parte['monto_minor'], ['venta_pos_id' => $bloqueada->getKey()], $actor, $motivo);
            }

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
                'partes' => $partes,
                'piezas' => array_sum(array_map(static fn (array $r): int => $r[1], $regresan)),
            ], $motivo);

            return $devolucion->refresh();
        });
    }

    /**
     * Por qué formas sale el dinero. De una venta con una sola forma, esa. De una mixta,
     * primero la elegida y el resto por las demás en el orden en que se cobró; ninguna
     * pasa de lo que entró por ella para lo vendido menos lo ya devuelto por ella.
     *
     * @return list<array{metodo: string, monto_minor: int}>
     */
    private function formasDeSalida(VentaPosTenant $venta, int $monto, ?string $metodo): array
    {
        if ($venta->metodo_pago !== PartesCobro::MIXTO) {
            return [['metodo' => CajasTenant::metodo($venta->metodo_pago), 'monto_minor' => $monto]];
        }
        [$deVenta] = PartesCobro::repartir(CajasTenant::partesDeVenta($venta), [(int) $venta->total_minor, (int) $venta->propina_minor]);
        if ($metodo === null || collect($deVenta)->firstWhere('metodo', $metodo) === null) {
            throw new VentaNoDevolvible('Se cobró con varias formas de pago: elige por cuál de ellas sale la devolución.');
        }

        // Lo que aún puede salir por cada forma.
        $disponible = [];
        foreach ($deVenta as $parte) {
            $disponible[$parte['metodo']] = ($disponible[$parte['metodo']] ?? 0) + $parte['monto_minor'];
        }
        foreach (DevolucionVentaTenant::query()->where('venta_pos_id', $venta->getKey())->get() as $previa) {
            foreach ($previa->partesDeSalida() as $parte) {
                if (isset($disponible[$parte['metodo']])) {
                    $disponible[$parte['metodo']] -= $parte['monto_minor'];
                }
            }
        }

        $orden = [$metodo, ...array_values(array_filter(array_keys($disponible), static fn (string $m): bool => $m !== $metodo))];
        $falta = $monto;
        $partes = [];
        foreach ($orden as $forma) {
            $toma = min($falta, max(0, $disponible[$forma]));
            if ($toma > 0) {
                $partes[] = ['metodo' => $forma, 'monto_minor' => $toma];
                $falta -= $toma;
            }
        }
        if ($falta > 0) {
            throw new VentaNoDevolvible('Por las formas de pago de esta venta ya no queda tanto por devolver.');
        }

        return $partes;
    }

    /**
     * ¿La devolución ya registrada es de las mismas piezas que se piden?
     *
     * @param  array<string, int>  $cantidades
     */
    private function mismasPiezas(DevolucionVentaTenant $devolucion, array $cantidades): bool
    {
        $registradas = [];
        foreach ($devolucion->lineas()->with('linea')->get() as $linea) {
            $ulid = (string) $linea->linea?->ulid;
            $registradas[$ulid] = ($registradas[$ulid] ?? 0) + (int) $linea->cantidad;
        }
        $pedidas = array_map('intval', $cantidades);
        ksort($registradas);
        ksort($pedidas);

        return $registradas === $pedidas;
    }
}
