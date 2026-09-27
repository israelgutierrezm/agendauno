<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una alerta agrupada de la plataforma (tipo + clave): qué falló, dónde, cuántas
 * veces y si falta avisarla. Ver {@see AlertasPlataforma}.
 *
 * @property string $tipo
 * @property string $clave
 * @property string|null $estudio
 * @property string $mensaje
 * @property int $veces
 * @property Carbon $primera_en
 * @property Carbon $ultima_en
 * @property bool $pendiente
 * @property Carbon|null $notificada_en
 */
class AlertaPlataforma extends Model
{
    protected $table = 'alertas_plataforma';

    protected $fillable = ['tipo', 'clave', 'estudio', 'mensaje', 'veces', 'primera_en', 'ultima_en', 'pendiente', 'notificada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'veces' => 'integer',
        'primera_en' => 'datetime',
        'ultima_en' => 'datetime',
        'pendiente' => 'boolean',
        'notificada_en' => 'datetime',
    ];
}
