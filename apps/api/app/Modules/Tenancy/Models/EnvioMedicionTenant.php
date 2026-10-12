<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una conversión del negocio para uno de sus destinos (su base, ADR 0143). Se crea
 * pendiente desde el evento de dominio (outbox), después de que la operación quedó
 * confirmada, y se manda aparte. `id_evento` es único por destino.
 *
 * @property int $id
 * @property string $ulid
 * @property int $destino_id
 * @property string $evento
 * @property string $nombre
 * @property string $id_evento
 * @property string|null $evento_ulid
 * @property int|null $persona_id
 * @property int|null $contexto_medicion_id
 * @property Carbon $ocurrido_en
 * @property string $accion
 * @property bool $personalizado
 * @property bool $sensible
 * @property int|null $valor_minor
 * @property string|null $moneda
 * @property string|null $transaccion
 * @property list<array{id: string, nombre?: string|null, precio_minor?: int|null, cantidad: int}>|null $items
 * @property array<string, string|int|bool>|null $parametros
 * @property EstadoEnvioMedicion $estado
 * @property string|null $motivo
 * @property int $intentos
 * @property Carbon|null $proximo_intento_en
 * @property Carbon|null $enviado_en
 * @property int|null $http_status
 * @property string|null $ultimo_error
 */
class EnvioMedicionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'envios_medicion';

    protected $fillable = [
        'destino_id', 'evento', 'nombre', 'id_evento', 'evento_ulid', 'persona_id', 'contexto_medicion_id', 'ocurrido_en',
        'accion', 'personalizado', 'sensible', 'valor_minor', 'moneda', 'transaccion', 'items', 'parametros', 'estado',
        'motivo', 'intentos', 'proximo_intento_en', 'enviado_en', 'http_status', 'ultimo_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ocurrido_en' => 'datetime',
        'personalizado' => 'boolean',
        'sensible' => 'boolean',
        'valor_minor' => 'integer',
        'items' => 'array',
        'parametros' => 'array',
        'estado' => EstadoEnvioMedicion::class,
        'intentos' => 'integer',
        'proximo_intento_en' => 'datetime',
        'enviado_en' => 'datetime',
        'http_status' => 'integer',
    ];

    /**
     * @return BelongsTo<DestinoMedicionTenant, $this>
     */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(DestinoMedicionTenant::class, 'destino_id');
    }

    /**
     * @return BelongsTo<ContextoMedicionTenant, $this>
     */
    public function contexto(): BelongsTo
    {
        return $this->belongsTo(ContextoMedicionTenant::class, 'contexto_medicion_id');
    }
}
