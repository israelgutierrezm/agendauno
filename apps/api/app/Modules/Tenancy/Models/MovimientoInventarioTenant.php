<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del ledger de inventario tenant-local (R21). El stock por (artículo,
 * sucursal) es la SUMA de `cantidad` (delta con signo) de sus movimientos.
 *
 * @property int $articulo_id
 * @property int $sucursal_id
 * @property int $cantidad
 */
class MovimientoInventarioTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'movimientos_inventario';

    protected $fillable = ['articulo_id', 'sucursal_id', 'tipo', 'cantidad', 'motivo', 'venta_pos_id', 'usuario_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoMovimientoInventario::class,
        'cantidad' => 'integer',
    ];
}
