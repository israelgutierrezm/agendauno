<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\MotivoFalloCobro;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proceso de morosidad (dunning, R10) de una membresía tenant-local: gracia,
 * reintentos y suspensión ante fallo de cobro.
 *
 * `ultimo_motivo` es la frase que se le manda al alumno; `ultimo_motivo_clave` (null si
 * el equipo registró el fallo a mano) y `ultimo_motivo_detalle` (el motivo del banco o
 * el texto del equipo) son para quien administra.
 *
 * @property Carbon $gracia_hasta
 * @property Carbon|null $proximo_intento_en
 * @property MotivoFalloCobro|null $ultimo_motivo_clave
 * @property string|null $ultimo_motivo_detalle
 */
class ProcesoDunningTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'procesos_dunning';

    protected $fillable = [
        'acuerdo_id', 'estado', 'intentos', 'gracia_hasta', 'proximo_intento_en',
        'ultimo_motivo', 'ultimo_motivo_clave', 'ultimo_motivo_detalle', 'suspendido_en', 'regularizado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoDunning::class,
        'intentos' => 'integer',
        'ultimo_motivo_clave' => MotivoFalloCobro::class,
        'gracia_hasta' => 'datetime',
        'proximo_intento_en' => 'datetime',
        'suspendido_en' => 'datetime',
        'regularizado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<AcuerdoTenant, $this>
     */
    public function acuerdo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoTenant::class, 'acuerdo_id');
    }
}
