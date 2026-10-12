<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Artículo minorista tenant-local (R21): producto de venta en caja (agua, ropa,
 * suplementos…), con stock por sucursal en el ledger de inventario. Separado de los
 * productos comerciales (membresías/paquetes).
 *
 * @property int $precio_minor
 * @property string $moneda
 */
class ArticuloTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'articulos';

    protected $fillable = ['nombre', 'sku', 'precio_minor', 'moneda', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'precio_minor' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * Lo que se le puede agregar, con su precio (ADR 0139).
     *
     * @return HasMany<ExtraArticuloTenant, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(ExtraArticuloTenant::class, 'articulo_id')->orderBy('orden')->orderBy('id');
    }
}
