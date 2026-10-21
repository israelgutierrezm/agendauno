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
 * @property-read Usuario|null $autor
 */
class MensajeConversacionTenant extends Model
{
    use HasPublicId;

    public const ENTRANTE = 'entrante';

    public const SALIENTE = 'saliente';

    protected $connection = 'tenant';

    protected $table = 'mensajes_conversacion';

    protected $fillable = ['conversacion_id', 'direccion', 'tipo', 'texto', 'id_externo', 'estado', 'enviado_por', 'enviado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enviado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'enviado_por')->withTrashed();
    }
}
