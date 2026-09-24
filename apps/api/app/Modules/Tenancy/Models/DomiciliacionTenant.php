<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago automático (domiciliación) de una membresía: cada renovación se cobra sola al
 * método que el alumno autorizó en la pasarela. Guarda solo referencias de la
 * pasarela (método, y en suscripciones su cliente y la suscripción) y lo necesario
 * para mostrar la tarjeta (marca, últimos 4, vencimiento).
 */
class DomiciliacionTenant extends Model
{
    use HasPublicId;

    public const ACTIVA = 'activa';

    /**
     * Suscripción creada en la pasarela que el cliente aún no autoriza.
     */
    public const PENDIENTE = 'pendiente';

    public const CANCELADA = 'cancelada';

    protected $connection = 'tenant';

    protected $table = 'domiciliaciones';

    protected $fillable = [
        'acuerdo_id', 'persona_id', 'proveedor', 'estado', 'metodo_externo', 'cliente_externo', 'suscripcion_externa', 'marca', 'ultimos4',
        'expira_mes', 'expira_anio', 'ultimo_error', 'ultimo_error_en', 'activada_en', 'cancelada_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expira_mes' => 'integer',
        'expira_anio' => 'integer',
        'ultimo_error_en' => 'datetime',
        'activada_en' => 'datetime',
        'cancelada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<AcuerdoTenant, $this>
     */
    public function acuerdo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoTenant::class, 'acuerdo_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * La tarjeta, para mostrarla: marca, últimos 4 y vencimiento.
     *
     * @return array{marca: string|null, ultimos4: string|null, expira: string|null}
     */
    public function tarjeta(): array
    {
        return [
            'marca' => $this->marca,
            'ultimos4' => $this->ultimos4,
            'expira' => $this->expira_mes !== null && $this->expira_anio !== null
                ? sprintf('%02d/%02d', $this->expira_mes, $this->expira_anio % 100)
                : null,
        ];
    }
}
