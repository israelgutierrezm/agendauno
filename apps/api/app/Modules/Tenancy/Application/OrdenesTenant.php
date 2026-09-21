<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Ordenes\EstadoOrden;
use App\Modules\Ordenes\Exceptions\MonedaMixta;
use App\Modules\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Support\Facades\DB;

/**
 * Ordenes tenant-local: crea una orden pendiente con sus lineas (precio congelado)
 * y la liquida manualmente (ventanilla/efectivo/transferencia), haciendo el
 * fulfillment: concede un derecho por cada unidad de cada linea al beneficiario (o
 * comprador). El cobro con pasarela real es un modulo posterior. Comprador !=
 * participante. Todo sobre la BD del tenant resuelto.
 */
class OrdenesTenant
{
    public function __construct(
        private readonly FulfillmentTenant $fulfillment,
        private readonly GestionarPromocionesTenant $promociones,
    ) {}

    /**
     * @param  list<array{producto: ProductoTenant, cantidad: int, beneficiario?: PersonaTenant|null}>  $items
     */
    public function crear(PersonaTenant $comprador, array $items, ?string $codigoPromo = null, ?int $sucursalId = null): OrdenTenant
    {
        $moneda = $items[0]['producto']->moneda; // Una sola moneda por orden.

        foreach ($items as $item) {
            if ($item['producto']->moneda !== $moneda) {
                throw new MonedaMixta('Una orden no puede mezclar monedas.');
            }
        }

        return DB::connection('tenant')->transaction(function () use ($comprador, $items, $moneda, $codigoPromo, $sucursalId): OrdenTenant {
            $subtotal = 0;

            $orden = OrdenTenant::query()->create([
                'persona_id' => $comprador->getKey(),
                // Sucursal (R19): sede del vendedor acotado o, si no, la de casa del comprador.
                'sucursal_id' => $sucursalId,
                'estado' => EstadoOrden::Pendiente->value,
                'total_minor' => 0,
                'moneda' => $moneda,
            ]);

            foreach ($items as $item) {
                $producto = $item['producto'];
                $cantidad = max(1, $item['cantidad']);
                $lineaSubtotal = $producto->precio_minor * $cantidad;
                $subtotal += $lineaSubtotal;

                $orden->lineas()->create([
                    'producto_comercial_id' => $producto->getKey(),
                    'beneficiario_id' => ($item['beneficiario'] ?? null)?->getKey(),
                    'cantidad' => $cantidad,
                    'precio_unitario_minor' => $producto->precio_minor,
                    'subtotal_minor' => $lineaSubtotal,
                ]);
            }

            // Promocion / cupon (R22): valida, consume un uso y descuenta del total.
            $descuento = 0;
            $promocionId = null;
            if ($codigoPromo !== null && $codigoPromo !== '') {
                ['promocion' => $promocion, 'descuento' => $descuento] = $this->promociones->aplicarEnOrden($codigoPromo, $subtotal);
                $promocionId = $promocion->getKey();
            }

            $orden->update([
                'total_minor' => $subtotal - $descuento,
                'descuento_minor' => $descuento,
                'promocion_id' => $promocionId,
            ]);

            return $orden;
        });
    }

    /**
     * Crea una orden PENDIENTE por una SESIÓN (pago-para-reservar, citas): sin líneas de
     * producto. Al pagarla, el fulfillment CONFIRMA la reserva ligada (no concede un
     * derecho). La reserva se crea aparte (ver {@see ReservasTenant::reservarConPago()}).
     */
    public function crearPorSesion(PersonaTenant $comprador, SesionTenant $sesion, int $montoMinor, string $moneda, ?int $sucursalId = null): OrdenTenant
    {
        return OrdenTenant::query()->create([
            'persona_id' => $comprador->getKey(),
            'sucursal_id' => $sucursalId,
            'sesion_id' => $sesion->getKey(),
            'estado' => EstadoOrden::Pendiente->value,
            'total_minor' => $montoMinor,
            'moneda' => $moneda,
        ]);
    }

    /**
     * Liquida una orden pendiente (pago manual/ventanilla) y hace el fulfillment de
     * forma atomica. Idempotente: una orden ya pagada devuelve sin volver a conceder
     * derechos. La orden cancelada no admite liquidacion.
     */
    public function liquidar(OrdenTenant $orden, string $metodo, ?string $referencia = null): OrdenTenant
    {
        return DB::connection('tenant')->transaction(function () use ($orden, $metodo, $referencia): OrdenTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoOrden::Pagada) {
                return $bloqueada;
            }

            if ($bloqueada->estado === EstadoOrden::Cancelada) {
                throw new OrdenNoLiquidable('La orden no admite liquidacion.');
            }

            // Registra el metodo/referencia del pago manual y hace el fulfillment.
            $bloqueada->update(['metodo_pago' => $metodo, 'referencia_pago' => $referencia]);
            $this->fulfillment->cumplir($bloqueada);

            return $bloqueada->refresh();
        });
    }
}
