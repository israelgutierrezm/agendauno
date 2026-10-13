<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Artículo minorista tenant-local (R21): producto de venta en caja (agua, ropa,
 * suplementos…), con stock por sucursal en el ledger de inventario. Separado de los
 * productos comerciales (membresías/paquetes). Puede tener una categoría del negocio
 * y su foto (mostrador).
 *
 * @property int $precio_minor
 * @property string $moneda
 * @property int|null $categoria_id
 * @property string|null $foto_ruta
 */
class ArticuloTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'articulos';

    protected $fillable = ['nombre', 'sku', 'precio_minor', 'moneda', 'activo', 'categoria_id', 'foto_ruta'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'precio_minor' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * @return BelongsTo<CategoriaArticuloTenant, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaArticuloTenant::class, 'categoria_id');
    }

    /** La URL pública de su foto, o null si no tiene. */
    public function fotoUrl(): ?string
    {
        return $this->foto_ruta !== null && $this->foto_ruta !== ''
            ? Storage::disk('public')->url($this->foto_ruta)
            : null;
    }

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
