<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de un derecho ARCO (hoy: cancelación / baja de datos) que el alumno hace
 * al negocio. Estado: pendiente → atendida | rechazada.
 */
class SolicitudPrivacidadTenant extends Model
{
    use HasPublicId;

    public const PENDIENTE = 'pendiente';

    public const ATENDIDA = 'atendida';

    public const RECHAZADA = 'rechazada';

    protected $connection = 'tenant';

    protected $table = 'solicitudes_privacidad';

    protected $fillable = ['persona_id', 'tipo', 'estado', 'motivo', 'respuesta', 'atendida_por', 'atendida_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'atendida_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }
}
