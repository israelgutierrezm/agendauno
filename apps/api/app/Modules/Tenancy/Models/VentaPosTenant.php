<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Venta de punto de venta (ticket de caja) tenant-local (R21): venta minorista en una
 * sucursal, con método de pago manual y sus líneas. Separada de las órdenes de
 * membresía.
 *
 * Se puede anular si se registró por error (ADR 0089): queda con `anulada_en`, quién
 * y el motivo, y no cuenta en el corte ni en los movimientos.
 *
 * @property int $sucursal_id
 * @property int $total_minor
 * @property Carbon|null $anulada_en
 */
class VentaPosTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ventas_pos';

    protected $fillable = ['sucursal_id', 'total_minor', 'moneda', 'metodo_pago', 'usuario_id', 'anulada_en', 'anulada_por', 'motivo_anulacion'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_minor' => 'integer',
        'anulada_en' => 'datetime',
    ];

    /**
     * @return HasMany<LineaVentaPosTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaVentaPosTenant::class, 'venta_pos_id');
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
