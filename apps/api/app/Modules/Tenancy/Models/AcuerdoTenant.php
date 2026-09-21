<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Membresias\EstadoAcuerdo;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acuerdo tenant-local: la compra de un producto comercial por una persona.
 */
class AcuerdoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'acuerdos';

    protected $fillable = ['persona_id', 'producto_comercial_id', 'linea_orden_id', 'fecha_inicio', 'proxima_cobro_en', 'estado'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha_inicio' => 'date',
        'proxima_cobro_en' => 'date',
        'estado' => EstadoAcuerdo::class,
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * @return BelongsTo<ProductoTenant, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoTenant::class, 'producto_comercial_id');
    }

    /**
     * @return HasMany<DerechoTenant, $this>
     */
    public function derechos(): HasMany
    {
        return $this->hasMany(DerechoTenant::class, 'acuerdo_id');
    }
}
