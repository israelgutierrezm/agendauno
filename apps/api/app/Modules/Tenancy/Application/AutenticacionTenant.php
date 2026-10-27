<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\TokenAccesoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Emisión y verificación de tokens de acceso tenant-local. El token viaja como
 * `{id}|{secreto}`; en la BD del tenant solo se guarda su hash. La resolución
 * ocurre SIEMPRE sobre la conexión `tenant` ya activa, por lo que un token de un
 * estudio no autentica en otro.
 *
 * Cada token lleva el rol ACTIVO de esa sesión (quien tiene varios roles entra con
 * uno): al emitirlo, el de la última vez o el principal; al resolverlo, el usuario
 * queda trabajando con ese rol y solo con sus permisos.
 */
class AutenticacionTenant
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    /**
     * Crea un token para el usuario y devuelve el valor en claro (solo una vez).
     */
    /**
     * `$rol`: con el que entra (si aún lo tiene); si no, el de la última vez.
     */
    public function emitir(Usuario $usuario, string $nombre = 'app', ?string $rol = null): string
    {
        $secreto = Str::random(48);

        $token = TokenAccesoTenant::create([
            'tokenable_type' => Usuario::class,
            'tokenable_id' => $usuario->getKey(),
            'name' => $nombre,
            'rol_activo' => $usuario->usarRol($rol),
            'token' => hash('sha256', $secreto),
        ]);

        return $token->getKey().'|'.$secreto;
    }

    /**
     * Resuelve el usuario tenant-local a partir de un token en claro, o null. El
     * usuario queda trabajando con el rol de esa sesión.
     */
    public function resolver(#[\SensitiveParameter] string $valor): ?Usuario
    {
        $token = $this->token($valor);
        if ($token === null) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()]);
        $usuario = Usuario::query()->find($token->tokenable_id);
        if ($usuario instanceof Usuario) {
            // Si le quitaron el rol de esta sesión, sigue con otro y se anota.
            $token->forceFill(['rol_activo' => $usuario->usarRol($token->rol_activo)]);
        }
        $token->save();

        return $usuario;
    }

    /**
     * Cambia el rol de la sesión de este token (solo a uno que la persona tiene) y lo
     * recuerda como el de la última vez. Devuelve false si no se pudo.
     */
    public function cambiarRol(#[\SensitiveParameter] string $valor, Usuario $usuario, string $rol): bool
    {
        if (! in_array($rol, $usuario->rolesEfectivos(), true)) {
            return false;
        }
        $token = $this->token($valor);
        if ($token === null || (int) $token->tokenable_id !== (int) $usuario->getKey()) {
            return false;
        }

        $token->forceFill(['rol_activo' => $rol])->save();
        $usuario->forceFill(['ultimo_rol' => $rol])->save();
        $usuario->usarRol($rol);

        return true;
    }

    /**
     * Revoca todos los tokens del usuario (logout global).
     */
    public function revocarTodos(Usuario $usuario): void
    {
        TokenAccesoTenant::query()
            ->where('tokenable_type', Usuario::class)
            ->where('tokenable_id', $usuario->getKey())
            ->delete();
    }

    /** Minutos que vale un enlace para abrir la web desde la app (ADR 0211). */
    public const MINUTOS_ENLACE_WEB = 2;

    /**
     * Un código de un solo uso para abrir la web con la sesión de la app (ADR 0211):
     * lo que se hace en la web (pago automático con OpenPay, conectar un calendario)
     * sin volver a escribir la contraseña. Vale pocos minutos y solo se guarda su hash.
     * Queda ligado a la sesión que lo pidió (ADR 0227): si se cierra o se revoca, ya no
     * sirve.
     */
    public function crearEnlaceWeb(string $estudio, Usuario $usuario, string $destino, ?int $tokenId = null): string
    {
        $codigo = Str::random(48);
        Cache::put(self::claveEnlace($estudio, $codigo), [
            'usuario' => $usuario->getKey(),
            'rol' => $usuario->rolActivo(),
            'destino' => $destino,
            'token' => $tokenId,
        ], now()->addMinutes(self::MINUTOS_ENLACE_WEB));

        return $codigo;
    }

    /** El id de la sesión del bearer (`{id}|{secreto}`), o null. */
    public static function idDeToken(?string $bearer): ?int
    {
        if (! is_string($bearer) || ! str_contains($bearer, '|')) {
            return null;
        }
        $id = explode('|', $bearer, 2)[0];

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Canjea el código (una sola vez): el usuario, el rol con que estaba y a dónde iba.
     *
     * @return array{usuario: Usuario, rol: string|null, destino: string}|null
     */
    public function canjearEnlaceWeb(string $estudio, #[\SensitiveParameter] string $codigo): ?array
    {
        // Una sola vez aunque lleguen dos a la vez (ADR 0227): solo quien logra borrarlo lo
        // canjea (borrar es atómico en el almacén; leer y borrar por separado, no).
        $clave = self::claveEnlace($estudio, $codigo);
        $datos = Cache::get($clave);
        if (! is_array($datos) || ! Cache::forget($clave)) {
            return null;
        }
        $usuario = Usuario::query()->find($datos['usuario'] ?? null);
        if (! $usuario instanceof Usuario || ! $usuario->activo) {
            return null;
        }
        // La sesión de la app que lo pidió debe seguir abierta.
        if (isset($datos['token']) && ! TokenAccesoTenant::query()->whereKey((int) $datos['token'])
            ->where('tokenable_type', Usuario::class)->where('tokenable_id', $usuario->getKey())->exists()) {
            return null;
        }

        return ['usuario' => $usuario, 'rol' => isset($datos['rol']) ? (string) $datos['rol'] : null, 'destino' => (string) ($datos['destino'] ?? '/')];
    }

    private static function claveEnlace(string $estudio, string $codigo): string
    {
        return 'enlace-web:'.$estudio.':'.hash('sha256', $codigo);
    }

    /** El token vigente que corresponde al valor en claro, o null. */
    private function token(string $valor): ?TokenAccesoTenant
    {
        if (! str_contains($valor, '|')) {
            return null;
        }

        [$id, $secreto] = explode('|', $valor, 2);

        if (! ctype_digit($id) || $secreto === '') {
            return null;
        }

        $token = TokenAccesoTenant::query()->find((int) $id);

        if ($token === null || ! hash_equals((string) $token->token, hash('sha256', $secreto))) {
            return null;
        }

        if ($token->expires_at instanceof Carbon && $token->expires_at->isPast()) {
            return null;
        }

        // Sin usarse en el plazo del negocio, la sesión vence (y se borra).
        $ultimoUso = $token->last_used_at ?? $token->created_at;
        $dias = $this->parametros->entero('sesion.dias_inactividad');
        if ($ultimoUso instanceof Carbon && $ultimoUso->lessThan(now()->subDays($dias))) {
            $token->delete();

            return null;
        }

        return $token;
    }
}
