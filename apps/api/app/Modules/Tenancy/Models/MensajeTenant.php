<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje tenant-local (R28): una comunicacion concreta generada (normalmente por un
 * evento via plantilla) hacia una persona/destinatario por un canal, con su ciclo de
 * vida (encolado → enviado/fallido). `canal=interno` es la bandeja in-app de la persona.
 */
class MensajeTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'mensajes';

    protected $fillable = [
        'persona_id', 'plantilla_id', 'difusion_id', 'canal', 'destinatario', 'asunto', 'cuerpo',
        'estado', 'intentos', 'ultimo_error', 'evento_ulid', 'enviado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalComunicacion::class,
        'estado' => EstadoMensaje::class,
        'intentos' => 'integer',
        'enviado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * @return BelongsTo<DifusionTenant, $this>
     */
    public function difusion(): BelongsTo
    {
        return $this->belongsTo(DifusionTenant::class, 'difusion_id');
    }
}
