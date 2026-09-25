<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plantilla de comunicacion tenant-local (R28): asunto + cuerpo con marcadores
 * {{...}} que se disparan ante un evento (`clave` = tipo del evento) por un `canal`.
 * Unica por (clave, canal).
 */
class PlantillaMensajeTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'plantillas_mensaje';

    protected $fillable = ['clave', 'canal', 'asunto', 'cuerpo', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalComunicacion::class,
        'activo' => 'boolean',
    ];
}
