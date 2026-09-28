<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Configuración global de la plataforma (control plane, BD compartida): clave-valor
 * con el valor cifrado y oculto. Guarda secretos de plataforma como la llave maestra
 * de la cuenta FacturAPI.
 *
 * @property string|null $valor
 */
class ConfiguracionPlataforma extends Model
{
    protected $table = 'configuracion_plataforma';

    protected $fillable = ['clave', 'valor'];

    /**
     * @var list<string>
     */
    protected $hidden = ['valor'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valor' => 'encrypted',
    ];

    public static function obtener(string $clave): ?string
    {
        return static::query()->where('clave', $clave)->first()?->valor;
    }

    public static function establecer(string $clave, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            static::query()->where('clave', $clave)->delete();

            return;
        }

        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }

    /**
     * Llave de la cuenta FacturAPI: la config de plataforma (BD) tiene prioridad;
     * si no, cae a la variable de entorno. Tolera que la tabla aún no exista.
     */
    public static function llaveFacturapi(): ?string
    {
        try {
            $valor = static::obtener('facturapi_llave');
            if (is_string($valor) && $valor !== '') {
                return $valor;
            }
        } catch (Throwable) {
            // Tabla ausente (migraciones no corridas): usa el respaldo de entorno.
        }

        $env = config('agendauno.facturapi.llave');

        return is_string($env) && $env !== '' ? $env : null;
    }
}
