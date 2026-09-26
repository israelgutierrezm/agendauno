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
 * Oferta de una actividad (clase vendible/agendable), tenant-local.
 */
class OfertaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ofertas';

    protected $fillable = ['actividad_id', 'nombre', 'modalidad', 'capacidad', 'lugares', 'precio_clase_minor', 'politica_reserva', 'duracion_minutos', 'preparacion_min', 'limpieza_min'];

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
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }
}
