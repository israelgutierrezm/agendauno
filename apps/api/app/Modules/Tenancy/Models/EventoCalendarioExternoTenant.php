<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una clase o cita ya publicada en el calendario externo de quien la atiende (ADR 0199):
 * el id del evento allá y la huella de lo que se mandó, para no volver a mandarlo si no
 * cambió.
 *
 * @property int $calendario_externo_id
 * @property int $sesion_id
 * @property string $evento_id
 * @property string $huella
 */
class EventoCalendarioExternoTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'eventos_calendario_externo';

    protected $fillable = ['calendario_externo_id', 'sesion_id', 'evento_id', 'huella'];
}
