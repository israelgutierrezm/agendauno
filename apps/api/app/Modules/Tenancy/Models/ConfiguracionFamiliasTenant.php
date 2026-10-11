<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Familias\TextoAutorizacion;
use Illuminate\Database\Eloquent\Model;

/**
 * Si el negocio usa familias y el texto de autorización que acepta el responsable (B05).
 * Con el módulo encendido por el superadministrador (ADR 0123) se usan salvo que el
 * negocio las apague. Sin texto propio aplica el base de la plataforma. Cada cambio del
 * texto sube la versión: una autorización ya aceptada conserva el texto que se leyó.
 *
 * @property bool $activo
 * @property string|null $texto_autorizacion
 * @property int $version
 * @property int|null $actualizado_por
 */
class ConfiguracionFamiliasTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'configuracion_familias';

    protected $fillable = ['activo', 'texto_autorizacion', 'version', 'actualizado_por'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'version' => 'integer',
    ];

    /** La del negocio (en uso y con el texto base si no la ha tocado). */
    public static function actual(): self
    {
        return self::query()->first() ?? new self(['activo' => true, 'texto_autorizacion' => null, 'version' => 1]);
    }

    /** El texto que se acepta hoy. */
    public function texto(): string
    {
        $propio = trim((string) $this->texto_autorizacion);

        return $propio !== '' ? $propio : TextoAutorizacion::BASE;
    }
}
