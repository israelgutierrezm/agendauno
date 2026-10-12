<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una conversión del embudo del SaaS para un destino (control plane, ADR 0143). Se
 * crea pendiente cuando el sistema confirma el resultado y se manda aparte: registrar
 * un negocio o pagar su suscripción nunca esperan a Google ni a Meta. `id_evento` es
 * único por destino: la misma conversión no se crea ni se manda dos veces.
 *
 * @property int $id
 * @property string $ulid
 * @property int $destino_id
 * @property int $estudio_id
 * @property string $evento
 * @property string $nombre
 * @property string $id_evento
 * @property Carbon $ocurrido_en
 * @property string $accion
 * @property bool $personalizado
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
class EnvioMedicionPlataforma extends Model
{
    use HasPublicId;

    protected $table = 'envios_medicion_plataforma';

    protected $fillable = [
        'destino_id', 'estudio_id', 'evento', 'nombre', 'id_evento', 'ocurrido_en', 'accion', 'personalizado',
        'valor_minor', 'moneda', 'transaccion', 'items', 'parametros', 'estado', 'motivo', 'intentos',
        'proximo_intento_en', 'enviado_en', 'http_status', 'ultimo_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ocurrido_en' => 'datetime',
        'personalizado' => 'boolean',
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
     * @return BelongsTo<DestinoMedicionPlataforma, $this>
     */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(DestinoMedicionPlataforma::class, 'destino_id');
    }

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }
}
