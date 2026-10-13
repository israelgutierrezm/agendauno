<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un aviso de Wellhub o TotalPass (ADR 0148), tenant-local: la bandeja de entrada de
 * las plataformas. Se guarda al recibirlo (Wellhub espera respuesta en un segundo) y se
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
        'proveedor', 'tipo', 'clave', 'payload', 'estado', 'resultado', 'intentos', 'recibido_en', 'procesado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'intentos' => 'integer',
        'recibido_en' => 'datetime',
        'procesado_en' => 'datetime',
    ];
}
