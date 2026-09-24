<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de horario recurrente tenant-local (R5): una oferta impartida en una
 * sucursal ciertos `dias_semana` (ISO 1..7) a `hora_local`. De aqui se materializan
 * sesiones (con `serie_id` = esta plantilla).
 */
class PlantillaHorarioTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'plantillas_horario';

    protected $fillable = [
        'oferta_id', 'sucursal_id', 'instructor_id', 'recurso_id', 'dias_semana', 'hora_local',
        'duracion_minutos', 'capacidad', 'activo', 'vigente_desde', 'vigente_hasta',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'dias_semana' => 'array',
        'duracion_minutos' => 'integer',
        'capacidad' => 'integer',
        'activo' => 'boolean',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    /**
     * @return BelongsTo<OfertaTenant, $this>
     */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(OfertaTenant::class, 'oferta_id');
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'instructor_id')->withTrashed();
    }
}
