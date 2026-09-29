<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Oferta de una actividad (clase vendible/agendable), tenant-local. La descripción
 * la ve quien la elige en línea. Un servicio puede incluir otros (paquete, ADR 0063)
 * y sigue siendo uno: su precio, su duración y una sola cita.
 *
 * @property string|null $descripcion
 */
class OfertaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ofertas';

    protected $fillable = ['actividad_id', 'nombre', 'descripcion', 'modalidad', 'capacidad', 'lugares', 'precio_clase_minor', 'politica_reserva', 'duracion_minutos', 'preparacion_min', 'limpieza_min'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modalidad' => ModalidadOfertaTenant::class,
        'capacidad' => 'integer',
        'lugares' => 'integer',
        'precio_clase_minor' => 'integer',
        'politica_reserva' => PoliticaReservaTenant::class,
        'duracion_minutos' => 'integer',
        'preparacion_min' => 'integer',
        'limpieza_min' => 'integer',
    ];

    /**
     * Espacios o equipos que puede usar el servicio (2.4); vacío = no requiere.
     *
     * @return BelongsToMany<RecursoTenant, $this>
     */
    public function recursos(): BelongsToMany
    {
        return $this->belongsToMany(RecursoTenant::class, 'oferta_recursos', 'oferta_id', 'recurso_id')->withTimestamps();
    }

    /**
     * Servicios que incluye (paquete, ADR 0063), en orden; vacío = servicio simple.
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function incluidas(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'oferta_incluidos', 'oferta_id', 'incluida_id')
            ->withPivot('posicion')
            ->orderByPivot('posicion')
            ->withTimestamps();
    }

    /**
     * Paquetes que incluyen este servicio.
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function incluidaEn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'oferta_incluidos', 'incluida_id', 'oferta_id');
    }

    /**
     * Lo que costarían por separado los servicios que incluye. Null si no incluye
     * ninguno o si alguno no tiene precio: sin eso no hay con qué comparar.
     */
    public function precioPorSeparadoMinor(): ?int
    {
        $incluidas = $this->incluidas;
        if ($incluidas->isEmpty() || $incluidas->contains(static fn (self $o): bool => (int) $o->precio_clase_minor <= 0)) {
            return null;
        }

        return (int) $incluidas->sum('precio_clase_minor');
    }

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }
}
