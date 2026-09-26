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
 *
 * `inicia_en`/`termina_en` son la atención (lo que se le dice al cliente);
 * `ocupa_desde`/`ocupa_hasta` es lo que ocupa en la agenda con la preparación y la
 * limpieza del servicio (2.3), que se congelan al crearla.
 */
class SesionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'sesiones';

    protected $fillable = ['oferta_id', 'sucursal_id', 'serie_id', 'recurso_id', 'instructor_id', 'inicia_en', 'termina_en', 'zona_horaria', 'capacidad', 'estado', 'tipo', 'margen_antes_min', 'margen_despues_min'];

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
        'ocupa_desde' => 'datetime',
        'ocupa_hasta' => 'datetime',
        'margen_antes_min' => 'integer',
        'margen_despues_min' => 'integer',
        'capacidad' => 'integer',
        'estado' => EstadoSesionTenant::class,
        'tipo' => TipoSesionTenant::class,
    ];

    protected static function booted(): void
    {
        // Al crearse congela los márgenes de su servicio; lo que ocupa se recalcula
        // cada que se guarda (si cambia el horario, cambia con él).
        static::saving(function (self $sesion): void {
            if (! $sesion->exists) {
                $margenes = OfertaTenant::query()->find($sesion->oferta_id);
                $sesion->margen_antes_min ??= (int) ($margenes->preparacion_min ?? 0);
                $sesion->margen_despues_min ??= (int) ($margenes->limpieza_min ?? 0);
            }
            $sesion->ocupa_desde = $sesion->inicia_en->copy()->subMinutes((int) $sesion->margen_antes_min);
            $sesion->ocupa_hasta = $sesion->termina_en->copy()->addMinutes((int) $sesion->margen_despues_min);
        });
    }

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
        return $this->belongsTo(RecursoTenant::class, 'recurso_id')->withTrashed();
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
