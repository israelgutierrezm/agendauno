<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un extra que se puede agregar a un artículo del mostrador, con su precio (ADR 0139):
 * a una proteína, fresas o leche de soya. No lleva inventario propio.
 *
 * @property int $id
 * @property string $ulid
 * @property int $articulo_id
 * @property string $nombre
 * @property int $precio_minor
 * @property bool $activo
 * @property int $orden
 */
class ExtraArticuloTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'extras_articulo';

    protected $fillable = ['articulo_id', 'nombre', 'precio_minor', 'activo', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'precio_minor' => 'integer',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    /**
     * @return BelongsTo<ArticuloTenant, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(ArticuloTenant::class, 'articulo_id');
    }
}
