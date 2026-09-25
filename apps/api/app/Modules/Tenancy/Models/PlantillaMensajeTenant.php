<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plantilla de comunicacion tenant-local (R28): asunto + cuerpo con marcadores
 * {{...}} que se disparan ante un evento (`clave` = tipo del evento) por un `canal`,
 * a la persona del evento o al profesional de la cita (`destinatario`). Unica por
 * (clave, canal, destinatario).
 */
class PlantillaMensajeTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'plantillas_mensaje';

    protected $fillable = ['clave', 'canal', 'destinatario', 'asunto', 'cuerpo', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalComunicacion::class,
        'destinatario' => DestinatarioMensaje::class,
        'activo' => 'boolean',
    ];
}
