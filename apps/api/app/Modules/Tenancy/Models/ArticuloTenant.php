<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Support\DiscosDeArchivos;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property bool $ofrecer_al_reservar
 * @property string $iva cómo se factura: general, exento o cero (ADR 0212)
 * @property string|null $clave_sat su clave de producto o servicio del SAT (ADR 0212)
 */
class ArticuloTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'articulos';

    protected $fillable = ['nombre', 'sku', 'precio_minor', 'moneda', 'activo', 'categoria_id', 'foto_ruta', 'ofrecer_al_reservar', 'iva', 'clave_sat'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'precio_minor' => 'integer',
        'activo' => 'boolean',
        // Se ofrece para apartar al reservar (ADR 0194).
        'ofrecer_al_reservar' => 'boolean',
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
            ? DiscosDeArchivos::publico()->url($this->foto_ruta)
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
