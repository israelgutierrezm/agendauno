<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Calendarios;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Calendar (ADR 0199). Pide lo mínimo: manejar los calendarios que crea la app
 * (`calendar.app.created`) y ver lo ocupado (`calendar.freebusy`), no leer sus eventos.
 */
class CalendarioGoogle implements ProveedorCalendario
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private const ALCANCES = 'openid email https://www.googleapis.com/auth/calendar.app.created https://www.googleapis.com/auth/calendar.freebusy';

    public function configurado(): bool
    {
        return (string) config('agendauno.calendarios.google.client_id') !== '' && (string) config('agendauno.calendarios.google.client_secret') !== '';
    }

    public function urlAutorizacion(string $estado, string $vuelta): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) config('agendauno.calendarios.google.client_id'),
            'redirect_uri' => $vuelta,
            'response_type' => 'code',
            'scope' => self::ALCANCES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $estado,
        ]);
    }

    public function canjearCodigo(string $codigo, string $vuelta): array
    {
        $json = $this->token(['code' => $codigo, 'redirect_uri' => $vuelta, 'grant_type' => 'authorization_code']);
        $refresco = (string) ($json['refresh_token'] ?? '');
        if ($refresco === '') {
            throw new RuntimeException('Google no dio acceso sin conexión: vuelve a conectar.');
        }

        return [
            'refresco' => $refresco,
            'acceso' => (string) ($json['access_token'] ?? ''),
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 60),
            'cuenta' => self::correoDelIdToken((string) ($json['id_token'] ?? '')),
        ];
    }

    public function refrescar(string $refresco): array
    {
        $json = $this->token(['refresh_token' => $refresco, 'grant_type' => 'refresh_token']);

        return [
            'acceso' => (string) ($json['access_token'] ?? ''),
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 60),
            'refresco' => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
        ];
    }

    public function crearCalendario(string $acceso, string $nombre, string $zona): string
    {
        return (string) $this->api($acceso)->post(self::API.'/calendars', ['summary' => $nombre, 'timeZone' => $zona])->throw()->json('id');
    }

    public function borrarCalendario(string $acceso, string $calendario): void
    {
        $respuesta = $this->api($acceso)->delete(self::API.'/calendars/'.rawurlencode($calendario));
        if (! $respuesta->successful() && ! in_array($respuesta->status(), [404, 410], true)) {
            $respuesta->throw();
        }
    }

    public function guardarEvento(string $acceso, string $calendario, ?string $eventoId, array $evento): string
    {
        $cuerpo = [
            'summary' => $evento['titulo'],
            'location' => $evento['lugar'],
            'description' => $evento['descripcion'],
            'start' => ['dateTime' => $evento['inicio']->utc()->toIso8601ZuluString()],
            'end' => ['dateTime' => $evento['fin']->utc()->toIso8601ZuluString()],
        ];
        $base = self::API.'/calendars/'.rawurlencode($calendario).'/events';
        $respuesta = $eventoId === null
            ? $this->api($acceso)->post($base, $cuerpo)
            : $this->api($acceso)->put($base.'/'.rawurlencode($eventoId), $cuerpo);

        return (string) $respuesta->throw()->json('id');
    }

    public function borrarEvento(string $acceso, string $calendario, string $eventoId): void
    {
        $respuesta = $this->api($acceso)->delete(self::API.'/calendars/'.rawurlencode($calendario).'/events/'.rawurlencode($eventoId));
        if (! $respuesta->successful() && ! in_array($respuesta->status(), [404, 410], true)) {
            $respuesta->throw();
        }
    }

    public function ocupado(string $acceso, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $json = $this->api($acceso)->post(self::API.'/freeBusy', [
            'timeMin' => $desde->utc()->toIso8601ZuluString(),
            'timeMax' => $hasta->utc()->toIso8601ZuluString(),
            'items' => [['id' => 'primary']],
        ])->throw()->json();

        // Google puede contestar 200 con `errors` (p. ej. backendError) y `busy` vacío: eso
        // no es «libre». Sin una respuesta completa no se toca nada (se lanza).
        $primario = is_array($json) ? ($json['calendars']['primary'] ?? null) : null;
        if (! is_array($primario) || ! empty($primario['errors']) || ! is_array($primario['busy'] ?? null)) {
            throw new RuntimeException('Google no dio lo ocupado del calendario: '.self::motivo($primario));
        }

        $ocupado = [];
        foreach ($primario['busy'] as $rango) {
            if (! is_array($rango) || ! is_string($rango['start'] ?? null) || ! is_string($rango['end'] ?? null)) {
                throw new RuntimeException('Google dio un horario ocupado incompleto.');
            }
            $ocupado[] = ['desde' => CarbonImmutable::parse($rango['start'])->utc(), 'hasta' => CarbonImmutable::parse($rango['end'])->utc()];
        }

        return $ocupado;
    }

    /**
     * @param  array<string, string>  $datos
     * @return array<string, mixed>
     */
    private function token(array $datos): array
    {
        return (array) Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            ...$datos,
            'client_id' => (string) config('agendauno.calendarios.google.client_id'),
            'client_secret' => (string) config('agendauno.calendarios.google.client_secret'),
        ])->throw()->json();
    }

    private function api(string $acceso): PendingRequest
    {
        return Http::withToken($acceso)->acceptJson()->timeout(15);
    }

    /** Lo que dijo Google del error del calendario, para la conexión. */
    private static function motivo(mixed $primario): string
    {
        $errores = is_array($primario) && is_array($primario['errors'] ?? null) ? $primario['errors'] : [];
        $razon = is_array($errores[0] ?? null) ? ($errores[0]['reason'] ?? null) : null;

        return is_string($razon) ? $razon : 'respuesta incompleta';
    }

    /** El correo del id_token (solo para mostrar qué cuenta se conectó). */
    private static function correoDelIdToken(string $idToken): ?string
    {
        $partes = explode('.', $idToken);
        if (count($partes) !== 3) {
            return null;
        }
        $datos = json_decode((string) base64_decode(strtr($partes[1], '-_', '+/')), true);

        return is_array($datos) && is_string($datos['email'] ?? null) ? $datos['email'] : null;
    }
}
