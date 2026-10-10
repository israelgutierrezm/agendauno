<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifica un token de reCAPTCHA v3 (Google) para el registro público, la lista de
 * interesados y la verificación de WhatsApp. Con `secret`, valida el token contra
 * Google y exige un puntaje por encima del umbral. Sin `secret`, solo fuera de
 * producción se omite (local, pruebas); en producción se rechaza: una llave olvidada
 * no deja la puerta abierta a bots (`agendauno:verificar-produccion` también la pide).
 */
class VerificarRecaptcha
{
    public function aprobado(?string $token, ?string $ip = null): bool
    {
        $secret = config('agendauno.recaptcha.secret');
        if (! is_string($secret) || $secret === '') {
            if (app()->environment('production')) {
                Log::error('recaptcha.sin_llave', ['detalle' => 'Falta RECAPTCHA_SECRET: se rechazan el registro y la lista de interesados.']);

                return false;
            }

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
