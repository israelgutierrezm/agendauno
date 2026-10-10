<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ProductoComercial;
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
    /**
     * ¿Se puede facturar (CFDI)? Con llave de FacturAPI, sí. Sin llave, solo fuera de
     * producción (con el proveedor falso de desarrollo y pruebas): en producción la
     * facturación queda apagada en lugar de entregar CFDI simulados.
     */
    public static function facturacionDisponible(): bool
    {
        return static::llaveFacturapi() !== null || ! app()->environment('production');
    }

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

    /**
     * Correo del superadministrador: ahí llegan las alertas de la operación y las
     * rentas vencidas. El que capturó en su panel tiene prioridad; si no, el de
     * ALERTAS_CORREO. Tolera que la tabla aún no exista.
     */
    public static function correoAlertas(): ?string
    {
        try {
            $valor = static::obtener('correo_alertas');
            if (is_string($valor) && filter_var($valor, FILTER_VALIDATE_EMAIL) !== false) {
                return $valor;
            }
        } catch (Throwable) {
            // Tabla ausente: usa el respaldo de entorno.
        }

        $env = config('agendauno.alertas.correo');

        return is_string($env) && filter_var($env, FILTER_VALIDATE_EMAIL) !== false ? $env : null;
    }

    /**
     * Correo de ventas para cotizaciones (más de 20 profesionales o de 1,000 alumnos,
     * ADR 0107): el que capturó el superadmin; si no, el de VENTAS_CORREO. Con un
     * producto (ADR 0108), el propio de esa marca si lo tiene; si no, el general.
     */
    public static function ventasCorreo(?ProductoComercial $producto = null): ?string
    {
        $propio = $producto !== null ? self::ventasCorreoPropio($producto) : null;
        if ($propio !== null) {
            return $propio;
        }
        $valor = self::leer('ventas_correo') ?? config('agendauno.ventas.correo');

        return is_string($valor) && filter_var($valor, FILTER_VALIDATE_EMAIL) !== false ? $valor : null;
    }

    /**
     * El correo de ventas propio de una marca (`ventas_correo_{producto}` del superadmin
     * o `productos.{producto}.ventas_correo`), sin el general. AgendaUno usa el general.
     */
    public static function ventasCorreoPropio(ProductoComercial $producto): ?string
    {
        if ($producto === ProductoComercial::AgendaUno) {
            return null;
        }
        $valor = self::leer("ventas_correo_{$producto->value}") ?? config("agendauno.productos.{$producto->value}.ventas_correo");

        return is_string($valor) && filter_var($valor, FILTER_VALIDATE_EMAIL) !== false ? $valor : null;
    }

    /** WhatsApp de ventas (solo dígitos, con lada): el del superadmin o VENTAS_WHATSAPP. */
    public static function ventasWhatsApp(): ?string
    {
        $valor = preg_replace('/\D/', '', (string) (self::leer('ventas_whatsapp') ?? config('agendauno.ventas.whatsapp') ?? ''));

        return $valor !== '' ? $valor : null;
    }

    /** Token de la API del Banco de México (tipo de cambio): el del superadmin o BANXICO_TOKEN. */
    public static function tokenBanxico(): ?string
    {
        $valor = self::leer('banxico_token') ?? config('agendauno.banxico.token');

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /** Paquetes de timbres que se venden por omisión (ADR 0107). */
    public const PAQUETES_TIMBRES = [50, 100, 200, 350, 500];

    /**
     * Paquetes de timbres que se venden (cuántos timbres trae cada uno): los que fijó el
     * superadmin o los de siempre.
     *
     * @return list<int>
     */
    public static function paquetesTimbres(): array
    {
        $guardados = json_decode((string) (self::leer('timbres_paquetes') ?? ''), true);
        if (! is_array($guardados)) {
            return self::PAQUETES_TIMBRES;
        }
        $paquetes = array_values(array_unique(array_filter(array_map('intval', $guardados), static fn (int $n): bool => $n > 0)));
        sort($paquetes);

        return $paquetes !== [] ? $paquetes : self::PAQUETES_TIMBRES;
    }

    /** Lee una clave tolerando que la tabla aún no exista. */
    private static function leer(string $clave): ?string
    {
        try {
            $valor = static::obtener($clave);
        } catch (Throwable) {
            return null;
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
