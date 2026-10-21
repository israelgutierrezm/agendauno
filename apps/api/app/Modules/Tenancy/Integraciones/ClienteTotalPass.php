<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * La API nueva de TotalPass para el check-in automático (ADR 0148):
 *
 * - Sesión: con la llave de AgendaUno como integrador (`partner_api_key`, de la
 *   plataforma) y la de la sede (`place_api_key`, del negocio), `POST /partner/auth` da un
 *   JWT de 24 horas. Se guarda en caché por sede.
 * - Aviso: `POST /partner/webhook/create` registra la dirección donde TotalPass avisa de
 *   cada check-in (`CHECKIN`).
 * - Confirmación: cada aviso trae un token; confirmarlo
 *   (`POST {admin}/api/v1/webhook_confirmations/{token}`) es lo que valida la visita. La
 *   dirección se arma aquí con el host de TotalPass, nunca con la que venga en el aviso.
 *
 * @see https://dev.totalpass.com/docs/getting-started
 */
class ClienteTotalPass
{
    /** Lo que TotalPass contesta cuando el check-in ya no se puede confirmar. */
    private const MOTIVOS = [
        'check_in_expired_alert' => 'El check-in venció en TotalPass: la persona tiene que hacerlo de nuevo en su app.',
        'check_in_not_available' => 'El check-in ya no está disponible en TotalPass.',
    ];

    /** Un token de confirmación: base64 seguro para URL, con relleno «=». */
    public const TOKEN = '/^[A-Za-z0-9_\-=+.~]{6,300}$/';

    /** Si AgendaUno ya tiene su llave de integrador de TotalPass. */
    public function disponible(): bool
    {
        return trim((string) config('agendauno.integraciones.totalpass_partner_api_key')) !== '';
    }

    /** El JWT de la sede; null sin llave de integrador o si TotalPass no lo da. */
    public function sesion(string $placeApiKey): ?string
    {
        if (! $this->disponible() || trim($placeApiKey) === '') {
            return null;
        }
        $clave = 'totalpass:sesion:'.hash('sha256', $placeApiKey);
        $guardada = Cache::get($clave);
        if (is_string($guardada) && $guardada !== '') {
            return $guardada;
        }
        try {
            $respuesta = Http::acceptJson()->asJson()->timeout(10)
                ->post($this->gym('/partner/auth'), [
                    'partner_api_key' => (string) config('agendauno.integraciones.totalpass_partner_api_key'),
                    'place_api_key' => trim($placeApiKey),
                ]);
        } catch (Throwable) {
            return null;
        }
        $token = $respuesta->successful() ? (string) $respuesta->json('token', '') : '';
        if ($token === '') {
            return null;
        }
        // Dura 24 h: se renueva antes.
        Cache::put($clave, $token, now()->addHours(23));

        return $token;
    }

    /**
     * Registra la dirección donde TotalPass avisa de cada check-in de la sede. Si la
     * sede ya tenía una (de otro sistema o de antes), se cambia por la nuestra.
     */
    public function registrarAviso(string $placeApiKey, string $url): bool
    {
        $token = $this->sesion($placeApiKey);
        if ($token === null) {
            return false;
        }
        try {
            $http = Http::withToken($token)->acceptJson()->asJson()->timeout(10);
            $registrados = $http->get($this->gym('/partner/webhook/get'));
            $actual = collect((array) ($registrados->successful() ? $registrados->json('webhooks', []) : []))
                ->first(fn ($aviso): bool => is_array($aviso) && ($aviso['webhook_type'] ?? null) === 'CHECKIN');
            if (is_array($actual) && ($actual['webhook_url'] ?? null) === $url) {
                return true;
            }
            $cuerpo = ['webhook_url' => $url, 'webhook_type' => 'CHECKIN'];
            $respuesta = is_array($actual)
                ? $http->put($this->gym('/partner/webhook/update'), $cuerpo)
                : $http->post($this->gym('/partner/webhook/create'), $cuerpo);
            $this->olvidarSiVencio($respuesta, $placeApiKey);

            return $respuesta->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** Deja de recibir los avisos de check-in de la sede (al apagar la integración). */
    public function quitarAviso(string $placeApiKey): bool
    {
        $token = $this->sesion($placeApiKey);
        if ($token === null) {
            return false;
        }
        try {
            $respuesta = Http::withToken($token)->acceptJson()->timeout(10)->delete($this->gym('/partner/webhook/delete/CHECKIN'));

            return $respuesta->successful() || $respuesta->status() === 404;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Confirma un check-in avisado por TotalPass: eso lo valida. Aprobado, rechazado (ya
     * venció o no existe) o no verificable (sin conexión, error de su lado o sin sesión).
     */
    public function confirmar(string $placeApiKey, string $token, string $referencia): ResultadoCheckin
    {
        // El token viene en base64 (puede terminar en «=»).
        if (preg_match(self::TOKEN, $token) !== 1) {
            return ResultadoCheckin::invalido('El aviso de TotalPass no trae un token de confirmación válido.');
        }
        $sesion = $this->sesion($placeApiKey);
        $url = rtrim((string) config('agendauno.integraciones.totalpass_admin_url'), '/').'/api/v1/webhook_confirmations/'.$token;
        try {
            $peticion = Http::acceptJson()->timeout(10);
            if ($sesion !== null) {
                $peticion = $peticion->withToken($sesion);
            }
            $respuesta = $peticion->post($url);
        } catch (Throwable) {
            return ResultadoCheckin::noVerificable('No se pudo contactar a TotalPass: no respondió a tiempo o no hubo conexión.');
        }
        if ($respuesta->successful()) {
            return ResultadoCheckin::valido($referencia);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            $this->olvidarSiVencio($respuesta, $placeApiKey);

            return ResultadoCheckin::noVerificable('TotalPass no aceptó la sesión de la integración: revisa la llave de tu sede.');
        }
        if ($respuesta->status() === 429 || $respuesta->serverError()) {
            return ResultadoCheckin::noVerificable('TotalPass no está respondiendo (error de su lado). Se reintenta en unos minutos.');
        }
        if ($respuesta->status() === 404) {
            return ResultadoCheckin::invalido('TotalPass no encontró ese check-in.');
        }

        return ResultadoCheckin::invalido(self::MOTIVOS[self::etiqueta($respuesta)] ?? 'TotalPass no validó la visita.');
    }

    /**
     * El `label` de un error de TotalPass. Viene en `errors[0].source` (un objeto al
     * confirmar un check-in, una lista al validar un token) o suelto.
     */
    public static function etiqueta(Response $respuesta): string
    {
        foreach (['errors.0.source.label', 'errors.0.source.0.label', 'errors.0.label', 'label'] as $ruta) {
            $valor = $respuesta->json($ruta);
            if (is_string($valor) && $valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    /** La sesión pudo vencer antes de tiempo: la siguiente vez se pide otra. */
    private function olvidarSiVencio(Response $respuesta, string $placeApiKey): void
    {
        if ($respuesta->status() === 401) {
            Cache::forget('totalpass:sesion:'.hash('sha256', $placeApiKey));
        }
    }

    private function gym(string $ruta): string
    {
        return rtrim((string) config('agendauno.integraciones.totalpass_gym_url'), '/').$ruta;
    }
}
