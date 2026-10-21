<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bloqueo de agenda (2.2): un profesional, una sede o una sala no está disponible
 * entre `desde` y `hasta` (UTC), con su motivo y quién lo puso. Tenant-local. Los que
 * trae un calendario externo (ADR 0199) llevan `calendario_externo_id` y se reemplazan
 * solos en cada sincronización.
 *
 * @property int|null $calendario_externo_id
 */
class BloqueoAgendaTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'bloqueos_agenda';

    protected $fillable = ['instructor_id', 'sucursal_id', 'recurso_id', 'desde', 'hasta', 'todo_el_dia', 'zona_horaria', 'motivo', 'creado_por', 'calendario_externo_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'desde' => 'datetime',
        'hasta' => 'datetime',
        'todo_el_dia' => 'boolean',
    ];

    /**
     * Los que se cruzan con el intervalo [desde, hasta).
     *
     * @param  Builder<self>  $consulta
     */
    public function scopeEntre(Builder $consulta, CarbonInterface $desde, CarbonInterface $hasta): void
    {
        $consulta->where('desde', '<', $hasta)->where('hasta', '>', $desde);
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'instructor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * @return BelongsTo<RecursoTenant, $this>
     */
    public function recurso(): BelongsTo
    {
        return $this->belongsTo(RecursoTenant::class, 'recurso_id')->withTrashed();
    }

    /**
     * El calendario externo del que vino (ADR 0199), si no lo puso alguien aquí.
     *
     * @return BelongsTo<CalendarioExternoTenant, $this>
     */
    public function calendarioExterno(): BelongsTo
    {
        return $this->belongsTo(CalendarioExternoTenant::class, 'calendario_externo_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por')->withTrashed();
    }
}
