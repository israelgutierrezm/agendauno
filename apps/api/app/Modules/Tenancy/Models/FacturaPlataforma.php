<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoFactura;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CFDI que LA PLATAFORMA emite al dueño por la renta del SaaS (control plane). Reusa
 * el enum de estado del CFDI tenant. Un CFDI por cargo (unique cargo_renta_id).
 * Espejo, a nivel plataforma, de {@see FacturaTenant}.
 *
 * @property int $cargo_renta_id
 * @property int $subtotal_minor
 * @property int $impuesto_minor
 * @property int $total_minor
 * @property string|null $forma_pago la del SAT con que se timbró (ADR 0214)
 * @property string|null $cancelacion_estado cómo va su cancelación, si se pidió
 * @property string|null $cancelacion_motivo el motivo del SAT (`02` o `03`)
 * @property Carbon|null $cancelada_en
 */
class FacturaPlataforma extends Model
{
    use HasPublicId;

    protected $table = 'facturas_plataforma';

    protected $fillable = [
        'estudio_id', 'cargo_renta_id', 'receptor_nombre', 'receptor_rfc', 'receptor_email',
        'receptor_regimen', 'receptor_cp', 'uso_cfdi', 'moneda', 'subtotal_minor',
        'impuesto_minor', 'total_minor', 'estado', 'facturapi_id', 'uuid', 'motivo_error',
        'timbrada_en', 'forma_pago', 'cancelacion_estado', 'cancelacion_motivo', 'cancelada_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoFactura::class,
        'subtotal_minor' => 'integer',
        'impuesto_minor' => 'integer',
        'total_minor' => 'integer',
        'timbrada_en' => 'datetime',
        'cancelada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<CargoRenta, $this>
     */
    public function cargo(): BelongsTo
    {
        return $this->belongsTo(CargoRenta::class, 'cargo_renta_id');
    }

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
