<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Recursos\ModoRecurso;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recurso reservable tenant-local (R3): sala/cancha/carril/equipo de una sucursal.
 * `modo` UNIDAD (exclusivo, 1 a la vez) o POOL (hasta `capacidad` simultaneas). Reusa
 * el enum {@see ModoRecurso} del modulo Recursos (puro).
 */
class RecursoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'recursos';

    protected $fillable = ['sucursal_id', 'nombre', 'tipo', 'modo', 'capacidad', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modo' => ModoRecurso::class,
        'capacidad' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * Cupo simultaneo del recurso: UNIDAD = 1; POOL = su capacidad (>= 1).
     */
    public function cupoSimultaneo(): int
    {
        return $this->modo === ModoRecurso::Pool ? max(1, (int) $this->capacidad) : 1;
    }
}
