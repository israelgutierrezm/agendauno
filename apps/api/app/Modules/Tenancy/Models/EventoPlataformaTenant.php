<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un aviso de Wellhub o TotalPass (ADR 0148 y 0149), tenant-local: la bandeja de entrada
 * de las plataformas (check-ins y reservas). Se guarda al recibirlo (Wellhub espera respuesta en un segundo) y se
 * procesa aparte; el mismo aviso (`proveedor` + `clave`) no se guarda dos veces.
 *
 * Estados: `pendiente` (por procesar o por reintentar), `procesado`, `descartado` (no
 * aplica o ya no se puede validar) y `fallido` (se agotaron los intentos).
 *
 * @property string $proveedor
 * @property string $tipo
 * @property string $clave
 * @property array<string, mixed> $payload
 * @property string $estado
 * @property string|null $resultado
 * @property int $intentos
 * @property Carbon $recibido_en
 * @property Carbon|null $procesado_en
 */
class EventoPlataformaTenant extends Model
{
    use HasPublicId;

    public const PENDIENTE = 'pendiente';

    public const PROCESADO = 'procesado';

    public const DESCARTADO = 'descartado';

    public const FALLIDO = 'fallido';

    protected $connection = 'tenant';

    protected $table = 'eventos_plataforma';

    protected $fillable = [
        'proveedor', 'tipo', 'clave', 'payload', 'estado', 'resultado', 'intentos', 'recibido_en', 'procesado_en', 'integracion_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'intentos' => 'integer',
        'recibido_en' => 'datetime',
        'procesado_en' => 'datetime',
        'integracion_id' => 'integer',
    ];

    /**
     * La conexión (plataforma y sede) de la que es (ADR 0202).
     *
     * @return BelongsTo<IntegracionTenant, $this>
     */
    public function integracion(): BelongsTo
    {
        return $this->belongsTo(IntegracionTenant::class, 'integracion_id');
    }

    /** Lo deja terminado (procesado, descartado o fallido) con lo que pasó. */
    public function terminar(string $estado, string $resultado): void
    {
        $this->fill(['estado' => $estado, 'resultado' => Str::limit($resultado, 290), 'procesado_en' => now()])->save();
    }
}
