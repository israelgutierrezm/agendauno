<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta de una actividad (clase vendible/agendable), tenant-local.
 */
class OfertaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ofertas';

    protected $fillable = ['actividad_id', 'nombre', 'modalidad', 'capacidad', 'lugares', 'precio_clase_minor', 'politica_reserva', 'duracion_minutos'];

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
    ];

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }
}
