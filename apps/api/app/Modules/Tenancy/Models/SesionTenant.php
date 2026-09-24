<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesión de la agenda (oferta materializada en una sucursal), tenant-local. Horas
 * en UTC + snapshot de zona horaria.
 */
class SesionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'sesiones';

    protected $fillable = ['oferta_id', 'sucursal_id', 'serie_id', 'recurso_id', 'instructor_id', 'inicia_en', 'termina_en', 'zona_horaria', 'capacidad', 'estado', 'tipo'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tipo' => 'clase',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'inicia_en' => 'datetime',
        'termina_en' => 'datetime',
        'capacidad' => 'integer',
        'estado' => EstadoSesionTenant::class,
        'tipo' => TipoSesionTenant::class,
    ];

    /**
     * ¿Es una cita privada (materializada para una persona)?
     */
    public function esCita(): bool
    {
        return $this->tipo === TipoSesionTenant::Cita;
    }

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
     * Recurso/sala asignado a la sesión (opcional).
     *
     * @return BelongsTo<RecursoTenant, $this>
     */
    public function recurso(): BelongsTo
    {
        return $this->belongsTo(RecursoTenant::class, 'recurso_id');
    }

    /**
     * Instructor asignado (usuario tenant-local), opcional.
     *
     * @return BelongsTo<Usuario, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'instructor_id')->withTrashed();
    }

    /**
     * @return HasMany<ReservaTenant, $this>
     */
    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaTenant::class, 'sesion_id');
    }
}
