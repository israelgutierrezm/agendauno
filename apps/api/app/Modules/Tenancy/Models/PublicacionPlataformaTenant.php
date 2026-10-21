<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una clase (oferta) que el negocio publica en Wellhub o TotalPass para que se reserve
 * desde su app (ADR 0149), tenant-local. Lleva el producto (Wellhub) o el plan
 * (TotalPass) con que se ofrece y, en Wellhub, el id de la clase allá.
 *
 * @property string $proveedor
 * @property int $oferta_id
 * @property string|null $producto_externo
 * @property string|null $clase_externa
 * @property bool $activa
 * @property string|null $error
 */
class PublicacionPlataformaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'publicaciones_plataforma';

    protected $fillable = ['proveedor', 'integracion_id', 'oferta_id', 'producto_externo', 'clase_externa', 'activa', 'error', 'oculta_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activa' => 'boolean',
        'oculta_en' => 'datetime',
        'integracion_id' => 'integer',
    ];

    /**
     * @return BelongsTo<OfertaTenant, $this>
     */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(OfertaTenant::class, 'oferta_id');
    }

    /**
     * La conexión (plataforma y sede) de la que es (ADR 0202).
     *
     * @return BelongsTo<IntegracionTenant, $this>
     */
    public function integracion(): BelongsTo
    {
        return $this->belongsTo(IntegracionTenant::class, 'integracion_id');
    }

    /**
     * @return HasMany<HorarioPlataformaTenant, $this>
     */
    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioPlataformaTenant::class, 'publicacion_id');
    }
}
