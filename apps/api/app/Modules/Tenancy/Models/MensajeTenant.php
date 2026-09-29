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
 *
 * @property array{plantilla?: string, valores?: list<string>}|null $parametros
 */
class MensajeTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'mensajes';

    protected $fillable = [
        'persona_id', 'usuario_id', 'plantilla_id', 'difusion_id', 'canal', 'destinatario', 'asunto', 'cuerpo',
        'estado', 'intentos', 'ultimo_error', 'evento_ulid', 'enviado_en', 'clave_envio', 'parametros',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalComunicacion::class,
        'estado' => EstadoMensaje::class,
        'intentos' => 'integer',
        'enviado_en' => 'datetime',
        // WhatsApp: {plantilla, valores} de la plantilla de Meta con que sale.
        'parametros' => 'array',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * El usuario del equipo que lo recibe (avisos al profesional); vacío si va a la
     * persona.
     *
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id')->withTrashed();
    }

    /**
     * @return BelongsTo<PlantillaMensajeTenant, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaMensajeTenant::class, 'plantilla_id')->withTrashed();
    }

    /**
     * @return BelongsTo<DifusionTenant, $this>
     */
    public function difusion(): BelongsTo
    {
        return $this->belongsTo(DifusionTenant::class, 'difusion_id');
    }
}
