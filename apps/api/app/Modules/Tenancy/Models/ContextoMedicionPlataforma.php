<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Medicion\ContextoConversion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * De dónde llegó quien registró el negocio en AgendaUno o TurnoUno (control plane,
 * ADR 0142): su primer origen, su último toque y la campaña acreditada. Uno por
 * negocio.
 *
 * @property int $id
 * @property int $estudio_id
 * @property string $producto
 * @property string|null $fuente
 * @property string|null $medio
 * @property string|null $campana
 * @property string|null $referente
 * @property array<string, string>|null $primer_toque
 * @property array<string, string>|null $ultimo_toque
 * @property string|null $visitante
 * @property bool $analitica
 * @property bool $publicidad
 * @property Carbon|null $consentimiento_en
 * @property string|null $ga_client_id
 * @property string|null $ga_session_id
 * @property string|null $fbp
 * @property string|null $fbc
 * @property string|null $agente
 * @property string|null $ip
 * @property string|null $url
 * @property Carbon|null $capturado_en
 */
class ContextoMedicionPlataforma extends Model
{
    protected $table = 'contextos_medicion_plataforma';

    protected $fillable = [
        'estudio_id', 'producto', 'fuente', 'medio', 'campana', 'referente', 'primer_toque', 'ultimo_toque',
        ...IdentificadoresNavegador::COLUMNAS,
    ];

    /** @var list<string> */
    protected $hidden = ['ip', 'agente', 'fbp', 'fbc', 'ga_client_id', 'ga_session_id', 'visitante'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'primer_toque' => 'array',
        'ultimo_toque' => 'array',
        'analitica' => 'boolean',
        'publicidad' => 'boolean',
        'consentimiento_en' => 'datetime',
        'capturado_en' => 'datetime',
    ];

    public function contexto(): ContextoConversion
    {
        return IdentificadoresNavegador::contexto($this);
    }

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }
}
