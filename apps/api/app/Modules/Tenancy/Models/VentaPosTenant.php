<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $orden_id la cita cobrada junto con esta venta (al terminarla)
 * @property int $servicio_minor lo que se cobró de esa cita en el mismo cobro
 * @property Carbon|null $anulada_en
 * @property Carbon|null $ticket_enviado_en
 * @property Carbon|null $ticket_envio_fallido_en
 * @property Carbon|null $ticket_solicitado_en cuándo se pidió mandarlo (sale por la cola)
 */
class VentaPosTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ventas_pos';

    protected $fillable = [
        'sucursal_id', 'total_minor', 'moneda', 'metodo_pago', 'usuario_id', 'anulada_en', 'anulada_por', 'motivo_anulacion',
        'propina_minor', 'recibido_minor', 'persona_id', 'ticket_enviado_en', 'clave_intento',
        'orden_id', 'servicio_minor', 'reserva_id', 'huella_intento', 'ticket_envio_fallido_en',
        'ticket_solicitado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_minor' => 'integer',
        'propina_minor' => 'integer',
        'recibido_minor' => 'integer',
        'servicio_minor' => 'integer',
        'anulada_en' => 'datetime',
        'ticket_enviado_en' => 'datetime',
        'ticket_envio_fallido_en' => 'datetime',
        'ticket_solicitado_en' => 'datetime',
    ];

    /**
     * Cómo va el correo de su ticket (R06): `enviado` (la cola lo mandó), `fallido`
     * (agotó sus intentos o no se pudo encolar; se reenvía), `pendiente` (pedido, aún en
     * la cola) o null si nunca se pidió.
     */
    public function estadoTicket(): ?string
    {
        return match (true) {
            $this->ticket_envio_fallido_en !== null => 'fallido',
            $this->ticket_enviado_en !== null => 'enviado',
            $this->ticket_solicitado_en !== null => 'pendiente',
            default => null,
        };
    }

    /**
     * @return HasMany<LineaVentaPosTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaVentaPosTenant::class, 'venta_pos_id');
    }

    /**
     * Sus partes, si se cobró con varias formas (ADR 0177): lo vendido y la propina.
     *
     * @return HasMany<ParteCobroTenant, $this>
     */
    public function partes(): HasMany
    {
        return $this->hasMany(ParteCobroTenant::class, 'venta_pos_id');
    }

    /**
     * Sus devoluciones parciales (ADR 0178).
     *
     * @return HasMany<DevolucionVentaTenant, $this>
     */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(DevolucionVentaTenant::class, 'venta_pos_id');
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

    /**
     * La cita que se cobró junto con esta venta (al terminarla), si fue así.
     *
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * Lo que se cobró en total: lo vendido, la propina y, si se cobró junto, el
     * servicio de la cita (cuyo pago es de su orden: los reportes no lo suman aquí).
     */
    public function cobradoMinor(): int
    {
        return $this->total_minor + $this->propina_minor + $this->servicio_minor;
    }

    /**
     * Las que llevan algo del mostrador (productos o propina). El cobro de solo el
     * servicio de una cita también deja su venta (su comprobante, R04/R06), pero su
     * dinero es el pago de la orden: no es una venta del mostrador ni un movimiento
     * aparte de la caja.
     *
     * @param  Builder<VentaPosTenant>  $consulta
     */
    public function scopeDelMostrador(Builder $consulta): void
    {
        $consulta->where(fn (Builder $w) => $w->where('total_minor', '>', 0)->orWhere('propina_minor', '>', 0));
    }
}
