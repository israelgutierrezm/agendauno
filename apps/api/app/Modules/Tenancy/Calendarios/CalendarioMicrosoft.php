<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Calendarios;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outlook / Microsoft 365 por Microsoft Graph (ADR 0199). Lo ocupado sale de su
 * calendario principal (eventos que no estén como «libre»); lo nuestro va en un
 * calendario aparte que crea la app.
 */
class CalendarioMicrosoft implements ProveedorCalendario
{
    private const API = 'https://graph.microsoft.com/v1.0';

    private const ALCANCES = 'offline_access openid email User.Read Calendars.ReadWrite';

    public function configurado(): bool
    {
        return (string) config('agendauno.calendarios.microsoft.client_id') !== '' && (string) config('agendauno.calendarios.microsoft.client_secret') !== '';
    }

    public function urlAutorizacion(string $estado, string $vuelta): string
    {
        return $this->autoridad().'/authorize?'.http_build_query([
            'client_id' => (string) config('agendauno.calendarios.microsoft.client_id'),
            'redirect_uri' => $vuelta,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => self::ALCANCES,
            'state' => $estado,
        ]);
    }

    public function canjearCodigo(string $codigo, string $vuelta): array
    {
        $json = $this->token(['code' => $codigo, 'redirect_uri' => $vuelta, 'grant_type' => 'authorization_code']);
        $refresco = (string) ($json['refresh_token'] ?? '');
        $acceso = (string) ($json['access_token'] ?? '');
        if ($refresco === '' || $acceso === '') {
            throw new RuntimeException('Microsoft no dio acceso sin conexión: vuelve a conectar.');
        }
        $yo = (array) $this->api($acceso)->get(self::API.'/me')->json();

        return [
            'refresco' => $refresco,
            'acceso' => $acceso,
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 60),
            'cuenta' => is_string($yo['mail'] ?? null) ? $yo['mail'] : (is_string($yo['userPrincipalName'] ?? null) ? $yo['userPrincipalName'] : null),
        ];
    }

    public function refrescar(string $refresco): array
    {
        $json = $this->token(['refresh_token' => $refresco, 'grant_type' => 'refresh_token']);

        return [
            'acceso' => (string) ($json['access_token'] ?? ''),
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 60),
            // Microsoft rota el token de refresco.
            'refresco' => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
        ];
    }

    public function crearCalendario(string $acceso, string $nombre, string $zona): string
    {
        return (string) $this->api($acceso)->post(self::API.'/me/calendars', ['name' => $nombre])->throw()->json('id');
    }

    public function borrarCalendario(string $acceso, string $calendario): void
    {
        $respuesta = $this->api($acceso)->delete(self::API.'/me/calendars/'.rawurlencode($calendario));
        if (! $respuesta->successful() && $respuesta->status() !== 404) {
            $respuesta->throw();
        }
    }

    public function guardarEvento(string $acceso, string $calendario, ?string $eventoId, array $evento): string
    {
        $cuerpo = [
            'subject' => $evento['titulo'],
            'body' => ['contentType' => 'text', 'content' => $evento['descripcion']],
            'start' => ['dateTime' => $evento['inicio']->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $evento['fin']->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'location' => ['displayName' => $evento['lugar']],
        ];
        $respuesta = $eventoId === null
            ? $this->api($acceso)->post(self::API.'/me/calendars/'.rawurlencode($calendario).'/events', $cuerpo)
            : $this->api($acceso)->patch(self::API.'/me/events/'.rawurlencode($eventoId), $cuerpo);

        return (string) ($respuesta->throw()->json('id') ?? $eventoId);
    }

    public function borrarEvento(string $acceso, string $calendario, string $eventoId): void
    {
        $respuesta = $this->api($acceso)->delete(self::API.'/me/events/'.rawurlencode($eventoId));
        if (! $respuesta->successful() && $respuesta->status() !== 404) {
            $respuesta->throw();
        }
    }

    public function ocupado(string $acceso, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $json = $this->api($acceso)
            ->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])
            ->get(self::API.'/me/calendar/calendarView', [
                'startDateTime' => $desde->utc()->toIso8601ZuluString(),
                'endDateTime' => $hasta->utc()->toIso8601ZuluString(),
                '$select' => 'start,end,showAs',
                '$top' => 500,
            ])->throw()->json();

        $ocupado = [];
        foreach ((array) ($json['value'] ?? []) as $evento) {
            if (! is_array($evento) || ($evento['showAs'] ?? 'busy') === 'free') {
                continue;
            }
            $ocupado[] = [
                'desde' => CarbonImmutable::parse((string) ($evento['start']['dateTime'] ?? ''), 'UTC'),
                'hasta' => CarbonImmutable::parse((string) ($evento['end']['dateTime'] ?? ''), 'UTC'),
            ];
        }

        return $ocupado;
    }

    /**
     * @param  array<string, string>  $datos
     * @return array<string, mixed>
     */
    private function token(array $datos): array
    {
        return (array) Http::asForm()->timeout(15)->post($this->autoridad().'/token', [
            ...$datos,
            'client_id' => (string) config('agendauno.calendarios.microsoft.client_id'),
            'client_secret' => (string) config('agendauno.calendarios.microsoft.client_secret'),
            'scope' => self::ALCANCES,
        ])->throw()->json();
    }

    private function autoridad(): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode((string) config('agendauno.calendarios.microsoft.tenant', 'common')).'/oauth2/v2.0';
    }

    private function api(string $acceso): PendingRequest
    {
        return Http::withToken($acceso)->acceptJson()->timeout(15);
    }
}
