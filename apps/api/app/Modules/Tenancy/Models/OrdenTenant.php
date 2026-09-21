<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Ordenes\EstadoOrden;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de compra tenant-local: congela precios (snapshot) al crearse y, al
 * liquidarse, concede los derechos de sus lineas. La liquidacion (manual/ventanilla)
 * se registra en la propia orden.
 */
class OrdenTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ordenes';

    protected $fillable = [
        'persona_id', 'sucursal_id', 'renueva_acuerdo_id', 'estado', 'total_minor', 'descuento_minor', 'promocion_id', 'moneda',
        'metodo_pago', 'referencia_pago', 'pagada_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoOrden::class,
        'total_minor' => 'integer',
        'descuento_minor' => 'integer',
        'pagada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * @return HasMany<LineaOrdenTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaOrdenTenant::class, 'orden_id');
    }
}
