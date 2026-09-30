<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aviso de la plataforma a un dueño (ADR 0071, control plane): por correo o por
 * WhatsApp, con el texto ya armado. Único por negocio, tipo, referencia y canal.
 *
 * @property int $estudio_id
 * @property string $tipo
 * @property string $referencia
 * @property CanalComunicacion $canal
 * @property string $destinatario
 * @property string $asunto
 * @property string $cuerpo
 * @property array{plantilla?: string, valores?: list<string>}|null $parametros
 * @property EstadoMensaje $estado
 * @property int $intentos
 * @property string|null $ultimo_error
 * @property Carbon|null $enviado_en
 * @property Carbon|null $entregado_en
 * @property Carbon|null $leido_en
 */
class AvisoDueno extends Model
{
    protected $table = 'avisos_duenos';

    protected $fillable = [
        'estudio_id', 'tipo', 'referencia', 'canal', 'destinatario', 'asunto', 'cuerpo', 'parametros',
        'estado', 'intentos', 'ultimo_error', 'enviado_en', 'entregado_en', 'leido_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalComunicacion::class,
        'estado' => EstadoMensaje::class,
        'parametros' => 'array',
        'intentos' => 'integer',
        'enviado_en' => 'datetime',
        'entregado_en' => 'datetime',
        'leido_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }
}
