<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un error agrupado de la API, la web o la app (ADR 0080). Ver
 * {@see ErroresPlataforma}.
 *
 * @property string $ulid
 * @property string $huella
 * @property string $origen
 * @property string $tipo
 * @property string $mensaje
 * @property string|null $lugar
 * @property string|null $traza
 * @property array<string, mixed>|null $contexto
 * @property int $veces
 * @property Carbon $primera_en
 * @property Carbon $ultima_en
 * @property string|null $version_primera
 * @property string|null $version_ultima
 * @property string $estado
 * @property Carbon|null $resuelto_en
 * @property string|null $version_resuelto
 * @property int $regresiones
 */
class ErrorPlataforma extends Model
{
    use HasPublicId;

    public const ABIERTO = 'abierto';

    public const RESUELTO = 'resuelto';

    public const IGNORADO = 'ignorado';

    public const ESTADOS = [self::ABIERTO, self::RESUELTO, self::IGNORADO];

    public const ORIGENES = ['api', 'web', 'app'];

    protected $table = 'errores_plataforma';

    protected $fillable = [
        'huella', 'origen', 'tipo', 'mensaje', 'lugar', 'traza', 'contexto', 'veces', 'primera_en', 'ultima_en',
        'version_primera', 'version_ultima', 'estado', 'resuelto_en', 'version_resuelto', 'regresiones',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'contexto' => 'array',
        'veces' => 'integer',
        'regresiones' => 'integer',
        'primera_en' => 'datetime',
        'ultima_en' => 'datetime',
        'resuelto_en' => 'datetime',
    ];
}
