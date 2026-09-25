<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\MonedaMixta;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\MetodoPago;
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
        private readonly GestionarPromocionesTenant $promociones,
        private readonly CobrarOrdenTenant $cobrar,
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
     * Liquida en caja una orden pendiente (efectivo, transferencia, ventanilla…):
     * registra su PAGO (con quién lo cobró, para el corte de caja) y hace el
     * fulfillment. Si había un pago en línea abierto, se cierra primero (si ya se
     * pagó, no se cobra dos veces). Idempotente: una orden ya pagada no se vuelve a
     * cobrar (devuelve null). La orden cancelada no admite liquidación.
     */
    public function liquidar(OrdenTenant $orden, string $metodo, ?string $referencia = null, ?Usuario $actor = null): ?PagoTenant
    {
        $orden->refresh();
        if ($orden->estado === EstadoOrden::Pagada) {
            return null;
        }
        if ($orden->estado === EstadoOrden::Cancelada) {
            throw new OrdenNoLiquidable('La orden no admite liquidacion.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $metodo, $referencia, $actor): PagoTenant {
            $pago = $this->cobrar->ejecutar($orden, 'manual', self::metodoDeCaja($metodo), null, null, $actor);
            $orden->update(['metodo_pago' => $metodo, 'referencia_pago' => $referencia]);

            return $pago;
        });
    }

    private static function metodoDeCaja(string $metodo): ?MetodoPago
    {
        return match ($metodo) {
            'efectivo' => MetodoPago::Efectivo,
            'transferencia' => MetodoPago::Spei,
            'ventanilla' => MetodoPago::Ventanilla,
            default => null,
        };
    }
}
