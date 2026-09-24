<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una orden tenant-local: un producto, su cantidad y el precio unitario
 * congelado. El beneficiario (participante) puede diferir del comprador.
 */
class LineaOrdenTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'lineas_orden';

    protected $fillable = [
        'orden_id', 'producto_comercial_id', 'beneficiario_id',
        'cantidad', 'precio_unitario_minor', 'subtotal_minor',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario_minor' => 'integer',
        'subtotal_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return BelongsTo<ProductoTenant, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoTenant::class, 'producto_comercial_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function beneficiario(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'beneficiario_id')->withTrashed();
    }
}
