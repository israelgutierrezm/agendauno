<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de los productos del mostrador (bebidas, ropa, suplementos…), propia de
 * cada negocio: ordena y filtra los productos al vender. Quitarla deja sus productos
 * sin categoría; nunca los borra.
 *
 * @property string $nombre
 * @property int $orden
 */
class CategoriaArticuloTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'categorias_articulo';

    protected $fillable = ['nombre', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'orden' => 'integer',
    ];

    /**
     * @return HasMany<ArticuloTenant, $this>
     */
    public function articulos(): HasMany
    {
        return $this->hasMany(ArticuloTenant::class, 'categoria_id');
    }
}
