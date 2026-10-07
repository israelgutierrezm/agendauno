<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\TokenAccesoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Carbon;
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

        return $token;
    }
}
