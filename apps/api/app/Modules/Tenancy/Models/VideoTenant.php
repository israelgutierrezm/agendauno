<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un video de la biblioteca del negocio (ADR 0184), tenant-local. Es un enlace (YouTube,
 * Vimeo u otro sitio con https): lo ve quien tiene un plan vigente de los que lo
 * incluyen (sin planes elegidos, cualquiera).
 *
 * @property string $titulo
 * @property string|null $descripcion
 * @property string $url
 * @property int|null $duracion_minutos
 * @property string|null $categoria
 * @property bool $publicado
 * @property int $orden
 */
class VideoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'videos';

    protected $fillable = ['titulo', 'descripcion', 'url', 'duracion_minutos', 'categoria', 'publicado', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'duracion_minutos' => 'integer',
        'publicado' => 'boolean',
        'orden' => 'integer',
    ];

    /**
     * @return BelongsToMany<ProductoTenant, $this>
     */
    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(ProductoTenant::class, 'video_producto', 'video_id', 'producto_id');
    }
}
