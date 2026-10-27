<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\DominioEstudio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifica un token de reCAPTCHA v3 (Google) para el registro público, la lista de
 * interesados y la verificación de WhatsApp. Con `secret`, valida el token contra
 * Google y exige un puntaje por encima del umbral. Sin `secret`, solo fuera de
 * producción se omite (local, pruebas); en producción se rechaza: una llave olvidada
 * no deja la puerta abierta a bots (`agendauno:verificar-produccion` también la pide).
 *
 * Además (ADR 0224): el token debe venir de un dominio de la plataforma o del dominio
 * propio activo de un negocio (la consola de reCAPTCHA no revisa el dominio, para que
 * sirva en los dominios propios; lo revisa aquí) y, si se dice, de la misma acción.
 */
class VerificarRecaptcha
{
    public function aprobado(?string $token, ?string $ip = null, ?string $accion = null): bool
    {
        $secret = config('agendauno.recaptcha.secret');
        if (! is_string($secret) || $secret === '') {
            if (! app()->environment(['local', 'testing'])) {
                Log::error('recaptcha.sin_llave', ['detalle' => 'Falta RECAPTCHA_SECRET: se rechazan los formularios públicos (registro, interesados y captación).']);

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

        if (isset($datos['hostname']) && ! $this->hostPermitido(strtolower((string) $datos['hostname']))) {
            return false;
        }
        if ($accion !== null && isset($datos['action']) && $datos['action'] !== $accion) {
            return false;
        }

        $puntaje = (float) ($datos['score'] ?? 0);
        $minimo = (float) config('agendauno.recaptcha.min_score', 0.5);

        return $puntaje >= $minimo;
    }

    /** Un dominio de la plataforma (o sus subdominios) o el dominio propio activo de un negocio. */
    private function hostPermitido(string $host): bool
    {
        foreach (ProductoComercial::cases() as $producto) {
            $dominio = strtolower($producto->dominio());
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }
        if (! app()->environment('production') && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || $host === '127.0.0.1')) {
            return true;
        }

        return DominioEstudio::query()->where('dominio', $host)
            ->whereNotNull('propiedad_verificada_en')->whereNotNull('apunta_desde')
            ->exists();
    }
}
