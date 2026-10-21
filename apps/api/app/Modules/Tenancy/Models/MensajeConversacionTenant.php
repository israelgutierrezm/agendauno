<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un mensaje de la bandeja (ADR 0200): entrante (de quien escribe) o saliente (del
 * equipo), con su estado de entrega.
 *
 * @property int $id
 * @property int $conversacion_id
 * @property string $direccion
 * @property string $tipo
 * @property string|null $texto
 * @property string|null $id_externo
 * @property string $estado
 * @property int|null $enviado_por
 * @property Carbon $enviado_en
 * @property string|null $adjunto_ref el id del medio en WhatsApp o la dirección del adjunto
 * @property string|null $adjunto_mime
 * @property string|null $adjunto_nombre
 * @property string|null $archivo_ruta en el disco privado, ya bajado o subido
 * @property int|null $archivo_bytes
 * @property-read Usuario|null $autor
 * @property-read ConversacionTenant|null $conversacion
 */
class MensajeConversacionTenant extends Model
{
    use HasPublicId;

    public const ENTRANTE = 'entrante';

    public const SALIENTE = 'saliente';

    protected $connection = 'tenant';

    protected $table = 'mensajes_conversacion';

    protected $fillable = [
        'conversacion_id', 'direccion', 'tipo', 'texto', 'id_externo', 'estado', 'enviado_por', 'enviado_en',
        'adjunto_ref', 'adjunto_mime', 'adjunto_nombre', 'archivo_ruta', 'archivo_bytes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enviado_en' => 'datetime',
        'archivo_bytes' => 'integer',
    ];

    /**
     * @return BelongsTo<ConversacionTenant, $this>
     */
    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(ConversacionTenant::class, 'conversacion_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'enviado_por')->withTrashed();
    }
}
