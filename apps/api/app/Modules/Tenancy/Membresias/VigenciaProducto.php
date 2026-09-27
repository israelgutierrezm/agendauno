<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

use App\Modules\Tenancy\Models\ProductoTenant;
use Carbon\CarbonImmutable;

/**
 * Vigencia de un producto (paquete, membresía, clase suelta): hasta qué día se puede
 * usar lo que se compra en una fecha. Se calcula al vender y queda fija en el derecho
 * (cambiar el producto después no toca lo ya vendido).
 */
final readonly class VigenciaProducto
{
    public function __construct(
        public TipoVigencia $tipo,
        public int $cantidad,
    ) {}

    public static function de(ProductoTenant $producto): ?self
    {
        $tipo = $producto->vigencia_tipo;
        $cantidad = (int) $producto->vigencia_cantidad;

        return $tipo instanceof TipoVigencia && $cantidad > 0 ? new self($tipo, $cantidad) : null;
    }

    /**
     * Último día en que se puede usar (incluido), comprando en `$inicio` (AAAA-MM-DD).
     */
    public function hasta(string $inicio): string
    {
        $dia = CarbonImmutable::parse($inicio)->startOfDay();

        $hasta = match ($this->tipo) {
            TipoVigencia::Dias => $dia->addDays($this->cantidad),
            // Del 31 de enero, 1 mes → 28 (o 29) de febrero: no se desborda a marzo.
            TipoVigencia::Meses => $dia->addMonthsNoOverflow($this->cantidad),
            TipoVigencia::FinDeMes => $dia->startOfMonth()->addMonthsNoOverflow($this->cantidad - 1)->endOfMonth(),
        };

        return $hasta->toDateString();
    }
}
