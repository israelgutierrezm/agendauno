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
 * Se le vende a una persona del negocio (alumno, instructor o alguien del equipo) o a
 * público en general, sin persona (ADR 0145).
 *
 * @property int $sucursal_id
 * @property int $total_minor
 * @property int $propina_minor aparte de lo vendido: no es ingreso del negocio (ADR 0139)
 * @property int|null $persona_id a quién se le vendió; null = público en general
 * @property int|null $recibido_minor lo que entregó en efectivo (para el cambio)
 * @property Carbon|null $anulada_en
 * @property Carbon|null $ticket_enviado_en
 */
class VentaPosTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ventas_pos';

    protected $fillable = [
        'sucursal_id', 'total_minor', 'moneda', 'metodo_pago', 'usuario_id', 'anulada_en', 'anulada_por', 'motivo_anulacion',
        'propina_minor', 'recibido_minor', 'persona_id', 'ticket_enviado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_minor' => 'integer',
        'propina_minor' => 'integer',
        'recibido_minor' => 'integer',
        'anulada_en' => 'datetime',
        'ticket_enviado_en' => 'datetime',
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

    /**
     * A quién se le vendió (ADR 0145); sin persona, a público en general. Con las dadas
     * de baja: la venta no cambia de comprador.
     *
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /** Lo que se cobró en total: lo vendido más la propina. */
    public function cobradoMinor(): int
    {
        return $this->total_minor + $this->propina_minor;
    }
}
