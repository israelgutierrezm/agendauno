<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\ExtraArticuloTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Punto de venta minorista tenant-local (R21): registra un ticket de caja y descuenta
 * stock (movimientos de salida) de forma atómica. Bloquea el artículo (lockForUpdate)
 * para serializar ventas concurrentes del mismo artículo y no vender de más.
 *
 * Se le vende a una persona del negocio o, sin ella, a público en general (ADR 0145).
 */
class PuntoDeVentaTenant
{
    public function __construct(
        private readonly InventarioTenant $inventario,
        private readonly RegionNegocioTenant $region,
    ) {}

    /**
     * Lo pedido (`articulo_id`, `cantidad`, `extras`, `precio_minor`) con sus artículos y
     * extras resueltos. Un extra que ya no es de ese artículo o está apagado: 422.
     *
     * @param  array<int, array<string, mixed>>  $pedidos
     * @return list<array{articulo: ArticuloTenant, cantidad: int, extras: list<ExtraArticuloTenant>, precio_minor: int|null}>
     */
    public function lineasPedidas(array $pedidos, string $campo = 'items'): array
    {
        $items = [];
        foreach (array_values($pedidos) as $n => $item) {
            $articulo = ArticuloTenant::query()->where('ulid', (string) $item['articulo_id'])->firstOrFail();
            $extrasPedidos = array_values(array_unique(array_map('strval', (array) ($item['extras'] ?? []))));
            $extras = $extrasPedidos === [] ? [] : ExtraArticuloTenant::query()
                ->where('articulo_id', $articulo->getKey())->where('activo', true)->whereIn('ulid', $extrasPedidos)->get()->all();
            if (count($extras) !== count($extrasPedidos)) {
                throw ValidationException::withMessages(["{$campo}.{$n}.extras" => ['Uno de los extras ya no está disponible para este producto.']]);
            }
            $items[] = [
                'articulo' => $articulo, 'cantidad' => (int) $item['cantidad'], 'extras' => array_values($extras),
                'precio_minor' => isset($item['precio_minor']) ? (int) $item['precio_minor'] : null,
            ];
        }

        return $items;
    }

    /**
     * Con `precio_minor` en un artículo (lo que mostró el mostrador por pieza, con sus
     * extras), si el precio cambió con el carrito abierto no se cobra: se pide revisar
     * el total. Un artículo que ya no está a la venta tampoco se vende. La clave del
     * intento queda en la venta (única): un reintento devuelve la misma.
     *
     * Al terminar una cita ({@see CobroDeCitaTenant}) la venta va ligada a su orden y
     * guarda cuánto fue del servicio (`$servicioMinor`): lo recibido alcanza para todo y
     * el ticket es de todo. Ahí puede no llevar productos (solo la propina, o solo el
     * servicio: todo cobro de cita deja su venta y su comprobante, R04/R06).
     *
     * La clave del intento va con la huella de lo pedido ({@see self::huella()}) y, en
     * una cita, con su reserva: así un reintento se reconoce y otra operación con la
     * misma clave es un conflicto (R05).
     *
     * @param  list<array{articulo: ArticuloTenant, cantidad: int, extras?: list<ExtraArticuloTenant>, precio_minor?: int|null}>  $items
     */
    public function vender(SucursalTenant $sucursal, array $items, string $metodo, ?Usuario $actor = null, int $propinaMinor = 0, ?PersonaTenant $comprador = null, ?int $recibidoMinor = null, ?string $claveIntento = null, ?OrdenTenant $orden = null, int $servicioMinor = 0, ?int $reservaId = null, ?string $huella = null): VentaPosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sucursal, $items, $metodo, $actor, $propinaMinor, $comprador, $recibidoMinor, $claveIntento, $orden, $servicioMinor, $reservaId, $huella): VentaPosTenant {
            $moneda = $items !== [] ? $items[0]['articulo']->moneda : ($orden->moneda ?? $this->region->moneda());

            // La propina va aparte de lo vendido: no es ingreso del negocio (ADR 0139).
            $venta = VentaPosTenant::query()->create([
                'sucursal_id' => $sucursal->getKey(),
                'total_minor' => 0,
                'moneda' => $moneda,
                'metodo_pago' => $metodo,
                'usuario_id' => $actor?->getKey(),
                'propina_minor' => max(0, $propinaMinor),
                'persona_id' => $comprador?->getKey(),
                'clave_intento' => $claveIntento,
                'orden_id' => $orden?->getKey(),
                'servicio_minor' => max(0, $servicioMinor),
                'reserva_id' => $reservaId,
                'huella_intento' => $huella,
            ]);

            $total = 0;
            foreach ($items as $n => $item) {
                // Serializa ventas concurrentes del mismo articulo (no-sobreventa).
                $articulo = ArticuloTenant::query()->whereKey($item['articulo']->getKey())->lockForUpdate()->firstOrFail();
                $cantidad = max(1, $item['cantidad']);
                // Se desactivó con el carrito abierto: ya no se vende.
                if (! $articulo->activo) {
                    throw ValidationException::withMessages(["items.{$n}.articulo_id" => ["«{$articulo->nombre}» ya no está a la venta: quítalo de la venta."]]);
                }

                $stock = $this->inventario->stock($articulo->getKey(), $sucursal->getKey());
                if ($stock < $cantidad) {
                    throw new StockInsuficiente("Stock insuficiente de {$articulo->nombre} en la sucursal.");
                }

                // Sus extras, con el precio de hoy (congelados en la línea; ADR 0139).
                $extras = array_map(static fn (ExtraArticuloTenant $e): array => [
                    'id' => $e->ulid, 'nombre' => $e->nombre, 'precio_minor' => $e->precio_minor,
                ], $item['extras'] ?? []);
                $extrasMinor = array_sum(array_column($extras, 'precio_minor'));
                // Cambió su precio (o el de un extra) con el carrito abierto: se revisa.
                $esperado = $item['precio_minor'] ?? null;
                if ($esperado !== null && $esperado !== $articulo->precio_minor + $extrasMinor) {
                    throw ValidationException::withMessages(["items.{$n}.precio_minor" => ["Cambió el precio de «{$articulo->nombre}»: revisa el total y vuelve a cobrar."]]);
                }
                $subtotal = ($articulo->precio_minor + $extrasMinor) * $cantidad;
                $total += $subtotal;

                $venta->lineas()->create([
                    'articulo_id' => $articulo->getKey(),
                    'cantidad' => $cantidad,
                    'precio_unitario_minor' => $articulo->precio_minor,
                    'subtotal_minor' => $subtotal,
                    'extras' => $extras === [] ? null : $extras,
                    'extras_minor' => $extrasMinor,
                ]);

                $this->inventario->registrar(
                    $articulo->getKey(),
                    $sucursal->getKey(),
                    -$cantidad,
                    TipoMovimientoInventario::Salida,
                    'Venta POS',
                    $actor,
                    $venta->getKey(),
                );
            }

            // En efectivo: lo que entregó alcanza para lo vendido y la propina (el cambio).
            if ($recibidoMinor !== null && $recibidoMinor < $total + $venta->propina_minor + $venta->servicio_minor) {
                throw ValidationException::withMessages(['recibido_minor' => ['Lo que recibiste no alcanza para cobrar la venta.']]);
            }
            $venta->update(['total_minor' => $total, 'recibido_minor' => $metodo === 'efectivo' ? $recibidoMinor : null]);

            return $venta->refresh();
        });
    }

    /**
     * La huella de lo que se pidió cobrar (R05): con la misma clave del intento, solo
     * lo mismo recupera la venta; otra operación (otra cita, otros importes, el
     * mostrador) es un conflicto.
     *
     * @param  array<string, mixed>  $pedido
     */
    public static function huella(string $operacion, array $pedido): string
    {
        return hash('sha256', $operacion.'|'.json_encode($pedido, JSON_UNESCAPED_UNICODE));
    }

    /**
     * ¿La venta registrada con esa clave es esta misma operación? Si no, la clave ya se
     * usó para otra y se responde 409 sin tocar nada.
     */
    public static function exigirMismaOperacion(VentaPosTenant $previa, ?int $reservaId, string $huella): void
    {
        $misma = ($previa->reserva_id === null ? null : (int) $previa->reserva_id) === $reservaId
            && ($previa->huella_intento === null || hash_equals((string) $previa->huella_intento, $huella));
        if (! $misma) {
            throw new ConflictHttpException('Esa clave de cobro ya se usó en otra operación: no se cobró nada. Vuelve a cobrar desde el principio.');
        }
    }
}
