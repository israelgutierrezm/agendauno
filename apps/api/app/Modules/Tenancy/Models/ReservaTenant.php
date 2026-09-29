<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Reserva tenant-local de una persona en una sesion, respaldada por un derecho y
 * (si es limitado) una retencion de credito.
 */
class ReservaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'reservas';

    protected $fillable = [
        'sesion_id', 'persona_id', 'derecho_id', 'retencion_id', 'orden_id',
        'estado', 'canal', 'lugar', 'unidades', 'costo_unidades', 'idempotency_key',
        'horas_limite', 'penaliza_tarde', 'penaliza_no_show', 'oferta_expira_en',
        'motivo_cancelacion', 'cancelada_en', 'cancelada_por', 'cancelada_por_usuario_id', 'reprogramaciones_cliente',
        'nota_cliente',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoReserva::class,
        'lugar' => 'integer',
        'unidades' => 'integer',
        'costo_unidades' => 'integer',
        'horas_limite' => 'integer',
        'penaliza_tarde' => 'boolean',
        'penaliza_no_show' => 'boolean',
        'oferta_expira_en' => 'datetime',
        'cancelada_en' => 'datetime',
        'recordatorio_24h_en' => 'datetime',
        'recordatorio_2h_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<DerechoTenant, $this>
     */
    public function derecho(): BelongsTo
    {
        return $this->belongsTo(DerechoTenant::class, 'derecho_id');
    }

    /**
     * @return BelongsTo<RetencionCreditoTenant, $this>
     */
    public function retencion(): BelongsTo
    {
        return $this->belongsTo(RetencionCreditoTenant::class, 'retencion_id');
    }

    /**
     * Orden de pago-para-reservar (citas) ligada a la reserva pendiente de pago.
     *
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return HasOne<AsistenciaTenant, $this>
     */
    public function asistencia(): HasOne
    {
        return $this->hasOne(AsistenciaTenant::class, 'reserva_id');
    }
}
