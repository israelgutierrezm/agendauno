<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\CambioCorreoInvalido;
use App\Modules\Tenancy\Mail\CorreoConfirmarCorreo;
use App\Modules\Tenancy\Mail\CorreoCorreoCambiado;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cambio del correo con el que entra una cuenta (dueño, equipo o alumno). El correo
 * nuevo queda pendiente hasta que su dueño abre el enlace que le llega (sirve una vez
 * y vence en 24 h); entonces se aplica, también a su ficha de persona, y se avisa al
 * correo anterior. El token viaja solo en el correo; aquí se guarda su hash.
 */
class CambiarCorreoTenant
{
    private const HORAS = 24;

    public function solicitar(Estudio $estudio, Usuario $usuario, string $email): void
    {
        $email = trim($email);

        if (strcasecmp($email, (string) $usuario->email) === 0) {
            throw ValidationException::withMessages(['email' => ['Ese ya es tu correo.']]);
        }
        if ($this->enUso($email, $usuario)) {
            throw ValidationException::withMessages(['email' => ['Ya hay una cuenta con ese correo en este negocio.']]);
        }

        $token = Str::random(48);
        $usuario->forceFill([
            'email_nuevo' => $email,
            'email_nuevo_token' => hash('sha256', $token),
            'email_nuevo_expira_en' => now()->addHours(self::HORAS),
        ])->save();

        Mail::to($email)->queue(new CorreoConfirmarCorreo((string) $estudio->nombre, (string) $estudio->slug, $token));
    }

    /**
     * Aplica el correo nuevo si el enlace es válido y vigente.
     */
    public function confirmar(Estudio $estudio, string $token): Usuario
    {
        $usuario = Usuario::query()->where('email_nuevo_token', hash('sha256', $token))->first();

        if (! $usuario instanceof Usuario
            || ! is_string($usuario->email_nuevo)
            || $usuario->email_nuevo_expira_en === null
            || now()->greaterThan($usuario->email_nuevo_expira_en)) {
            throw new CambioCorreoInvalido('El enlace para confirmar tu correo no es válido o ya venció. Pide el cambio otra vez.');
        }

        $nuevo = $usuario->email_nuevo;
        if ($this->enUso($nuevo, $usuario)) {
            throw new CambioCorreoInvalido('Ese correo ya lo usa otra cuenta de este negocio.');
        }

        $anterior = (string) $usuario->email;

        DB::connection('tenant')->transaction(function () use ($usuario, $nuevo): void {
            $usuario->forceFill([
                'email' => $nuevo,
                'email_verified_at' => now(),
                'email_nuevo' => null,
                'email_nuevo_token' => null,
                'email_nuevo_expira_en' => null,
            ])->save();

            // Su ficha de persona (recibos, recordatorios) sigue al correo nuevo, si
            // ninguna otra persona lo tiene ya.
            $libre = ! PersonaTenant::withTrashed()
                ->where('email', $nuevo)
                ->where(fn ($q) => $q->whereNull('usuario_id')->orWhere('usuario_id', '!=', $usuario->getKey()))
                ->exists();
            if ($libre) {
                PersonaTenant::query()->where('usuario_id', $usuario->getKey())->update(['email' => $nuevo]);
            }
        });

        Mail::to($anterior)->queue(new CorreoCorreoCambiado((string) $estudio->nombre, $nuevo));

        return $usuario;
    }

    public function cancelar(Usuario $usuario): void
    {
        $usuario->forceFill([
            'email_nuevo' => null,
            'email_nuevo_token' => null,
            'email_nuevo_expira_en' => null,
        ])->save();
    }

    private function enUso(string $email, Usuario $usuario): bool
    {
        return Usuario::withTrashed()->where('email', $email)->whereKeyNot($usuario->getKey())->exists();
    }
}
