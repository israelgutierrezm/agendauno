<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Ordenes\EstadoOrden;
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
        'sin_avisos', 'referencia_externa',
        'nota_cliente', 'asiste',
        'anticipo_minor', 'saldo_orden_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoReserva::class,
        'lugar' => 'integer',
        'unidades' => 'integer',
        'sin_avisos' => 'boolean',
        'costo_unidades' => 'integer',
        'anticipo_minor' => 'integer',
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
     * Con anticipo (ADR 0161): la orden del resto, que se cobra en el negocio (o en
     * línea si el cliente quiere). `orden` es la del anticipo.
     *
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function saldoOrden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'saldo_orden_id');
    }

    /**
     * La orden que falta cobrar de la cita: la suya si sigue pendiente; si no, la del
     * saldo. Null si ya no falta nada (o se canceló).
     */
    public function ordenPorCobrar(): ?OrdenTenant
    {
        foreach ([$this->orden, $this->saldo_orden_id !== null ? $this->saldoOrden : null] as $orden) {
            if ($orden instanceof OrdenTenant && $orden->estado === EstadoOrden::Pendiente) {
                return $orden;
            }
        }

        return null;
    }

    /**
     * Cuánto falta por cobrar de la cita: el anticipo sin pagar y el resto, juntos
     * (null si no falta nada).
     */
    public function porCobrarMinor(): ?int
    {
        $suma = 0;
        $alguna = false;
        foreach ([$this->orden, $this->saldo_orden_id !== null ? $this->saldoOrden : null] as $orden) {
            if ($orden instanceof OrdenTenant && $orden->estado === EstadoOrden::Pendiente) {
                $suma += (int) $orden->total_minor;
                $alguna = true;
            }
        }

        return $alguna ? $suma : null;
    }

    /**
     * El anticipo ya pagado de una cita que tiene saldo (null si no lleva anticipo o
     * aún no lo paga).
     */
    public function anticipoPagadoMinor(): ?int
    {
        return $this->saldo_orden_id !== null && $this->orden?->estado === EstadoOrden::Pagada
            ? (int) $this->orden->total_minor
            : null;
    }

    /**
     * Lo que cuesta la cita: su orden más la del saldo, si la tiene.
     */
    public function totalCitaMinor(): ?int
    {
        if ($this->orden === null) {
            return null;
        }

        return (int) $this->orden->total_minor + (int) ($this->saldo_orden_id !== null ? $this->saldoOrden?->total_minor : 0);
    }

    /**
     * @return HasOne<AsistenciaTenant, $this>
     */
    public function asistencia(): HasOne
    {
        return $this->hasOne(AsistenciaTenant::class, 'reserva_id');
    }
}
