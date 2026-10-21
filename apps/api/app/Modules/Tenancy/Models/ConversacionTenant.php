<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una conversación de la bandeja (ADR 0200): quien escribió por WhatsApp o Instagram,
 * con su ficha si el equipo la vinculó.
 *
 * @property int $id
 * @property string $canal
 * @property string $contacto_id
 * @property string|null $contacto_nombre
 * @property int|null $persona_id
 * @property string $estado
 * @property int $no_leidos
 * @property string|null $ultimo_texto
 * @property Carbon|null $ultimo_mensaje_en
 * @property Carbon|null $ultimo_entrante_en
 * @property-read PersonaTenant|null $persona
 */
class ConversacionTenant extends Model
{
    use HasPublicId;

    public const ABIERTA = 'abierta';

    public const ARCHIVADA = 'archivada';

    /** Las horas en que Meta deja contestar con texto libre tras el último mensaje suyo. */
    public const HORAS_PARA_CONTESTAR = 24;

    protected $connection = 'tenant';

    protected $table = 'conversaciones';

    protected $fillable = ['canal', 'contacto_id', 'contacto_nombre', 'persona_id', 'estado', 'no_leidos', 'ultimo_texto', 'ultimo_mensaje_en', 'ultimo_entrante_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'no_leidos' => 'integer',
        'ultimo_mensaje_en' => 'datetime',
        'ultimo_entrante_en' => 'datetime',
    ];

    /** ¿Aún se le puede contestar con texto libre? */
    public function puedeContestar(): bool
    {
        return $this->ultimo_entrante_en !== null
            && $this->ultimo_entrante_en->copy()->addHours(self::HORAS_PARA_CONTESTAR)->isFuture();
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * @return HasMany<MensajeConversacionTenant, $this>
     */
    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeConversacionTenant::class, 'conversacion_id');
    }
}
