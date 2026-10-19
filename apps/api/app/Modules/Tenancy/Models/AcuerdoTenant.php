<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Membresias\BajaAnticipada;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Acuerdo tenant-local: la compra de un producto comercial por una persona.
 *
 * Con permanencia (B04) congela lo pactado: hasta cuándo, qué paga si da de baja antes
 * y las condiciones que aceptó. `baja_efectiva_el`: su último día, si se dio de baja.
 * `origen` = `apertura`: lo traía de otro sistema al migrar (C07), sin venta ni pago aquí.
 *
 * @property array<string, mixed>|null $condiciones
 * @property bool|null $cobra_anualidad si su plan cobraba la anualidad al contratarlo (ADR 0166)
 */
class AcuerdoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'acuerdos';

    protected $fillable = [
        'persona_id', 'producto_comercial_id', 'linea_orden_id', 'fecha_inicio', 'proxima_cobro_en', 'dia_ancla', 'estado',
        'permanencia_hasta', 'baja_anticipada', 'penalizacion_minor', 'penalizacion_porcentaje', 'condiciones', 'baja_efectiva_el',
        'origen', 'referencia_apertura',
        // Si su plan cobraba la anualidad al contratarlo (ADR 0166; null = antes).
        'cobra_anualidad',
    ];

    /** Saldo de apertura: cargado al migrar, no vendido aquí (C07). */
    public const ORIGEN_APERTURA = 'apertura';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cobra_anualidad' => 'boolean',
        'fecha_inicio' => 'date',
        'proxima_cobro_en' => 'date',
        // Día del mes de sus aniversarios (ciclos y cobro), ver Aniversario.
        'dia_ancla' => 'integer',
        'estado' => EstadoAcuerdo::class,
        'permanencia_hasta' => 'date',
        'baja_anticipada' => BajaAnticipada::class,
        'penalizacion_minor' => 'integer',
        'penalizacion_porcentaje' => 'integer',
        'condiciones' => 'array',
        'baja_efectiva_el' => 'date',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
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

    /**
     * El pago automático vigente de la membresía, si lo tiene.
     *
     * @return HasOne<DomiciliacionTenant, $this>
     */
    public function domiciliacion(): HasOne
    {
        return $this->hasOne(DomiciliacionTenant::class, 'acuerdo_id')
            ->where('estado', DomiciliacionTenant::ACTIVA)
            ->latest('id');
    }

    /**
     * La pausa en curso (a lo más una a la vez).
     *
     * @return HasOne<PausaAcuerdoTenant, $this>
     */
    public function pausaAbierta(): HasOne
    {
        return $this->hasOne(PausaAcuerdoTenant::class, 'acuerdo_id')->whereNull('reanudada_en');
    }
}
