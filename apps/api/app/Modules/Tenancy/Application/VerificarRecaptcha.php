<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica un token de reCAPTCHA v3 (Google) para el registro público. Si no hay
 * `secret` configurado, la verificación se omite (entornos sin llaves, como local).
 * Con secret, valida el token contra Google y exige un puntaje por encima del umbral.
 */
class VerificarRecaptcha
{
    public function aprobado(?string $token, ?string $ip = null): bool
    {
        $secret = config('agendauno.recaptcha.secret');
        if (! is_string($secret) || $secret === '') {
            // Sin llaves configuradas no se exige captcha (dev/local).
            return true;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $respuesta = Http::asForm()->timeout(5)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                ['secret' => $secret, 'response' => $token, 'remoteip' => $ip],
            );
        } catch (Throwable) {
            return false;
        }

        $datos = $respuesta->json();
        if (! is_array($datos) || ($datos['success'] ?? false) !== true) {
            return false;
        }

        $puntaje = (float) ($datos['score'] ?? 0);
        $minimo = (float) config('agendauno.recaptcha.min_score', 0.5);

        return $puntaje >= $minimo;
    }
}
