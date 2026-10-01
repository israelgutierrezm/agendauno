<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un WhatsApp que salió por el número de la plataforma (ADR 0074, control plane): de
 * qué negocio y de qué mensaje es su wamid, para ubicarlo cuando Meta avisa si se
 * entregó, se leyó o falló.
 *
 * @property string $wamid
 * @property int|null $estudio_id
 * @property string $origen mensaje | aviso_dueno
 * @property int $referencia_id
 * @property string $estado enviado | entregado | leido | fallido
 * @property string|null $error
 * @property string|null $telefono_huella HMAC del número, para saber a qué aviso contesta alguien (ADR 0083)
 */
class WhatsAppEnvio extends Model
{
    public const ORIGEN_MENSAJE = 'mensaje';

    public const ORIGEN_AVISO_DUENO = 'aviso_dueno';

    protected $table = 'whatsapp_envios';

    protected $fillable = ['wamid', 'estudio_id', 'origen', 'referencia_id', 'estado', 'error', 'telefono_huella'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estudio_id' => 'integer',
        'referencia_id' => 'integer',
    ];
}
