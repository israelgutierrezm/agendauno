<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * La Booking API de TotalPass (ADR 0149), con la llave de AgendaUno como integrador
 * (`partner_api_key`) y la de la sede (`place_api_key`):
 *
 * - Sesión: `POST /partner/auth` da un JWT de 24 horas (se guarda en caché por sede).
 * - Planes: `GET /partner/plans`, con qué plan se ofrece cada clase.
 * - Ocurrencias: cada sesión es una ocurrencia sin repetición
 *   (`POST /partner/event-occurrence`): su `eventId` y su `eventOccurrenceUuid`. `PUT
 *   …/{uuid}` cambia instructor y duración; `PUT …/{uuid}/slot` los lugares; `DELETE …/{uuid}`
 *   la retira. La fecha y la hora no se cambian: se retira y se publica de nuevo.
 * - Reservas: `PUT /partner/slot/confirmSlot/{slot}` la confirma o la rechaza (5 minutos,
 *   con la confirmación encendida en la sede: `PUT /partner/places/update-configs`) y
 *   `DELETE /partner/slot/{slot}` la cancela.
 * - Avisos: `POST /partner/webhook/subscribe` registra la dirección de los avisos de
 *   reserva de la sede.
 *
 * Las horas van en la zona de la Ciudad de México (`es-MX`), en el formato de TotalPass
 * («03:00 PM»).
 *
 * @see https://dev.totalpass.com/docs/overview-1
 */
class ClienteTotalPassReservas
{
    /** Si AgendaUno ya tiene su llave de integrador de TotalPass. */
    public function disponible(): bool
    {
        return trim((string) config('agendauno.integraciones.totalpass_partner_api_key')) !== '';
    }

    /**
     * Los planes de la sede; null si TotalPass no contestó.
     *
     * @return list<array{id: string, nombre: string, virtual: bool}>|null
     */
    public function planes(string $placeApiKey): ?array
    {
        $respuesta = $this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http->get($this->url('/partner/plans')));
        if (! $respuesta instanceof Response || ! $respuesta->successful()) {
            return null;
        }
        $planes = [];
        foreach ((array) ($respuesta->json('Plans') ?? $respuesta->json('plans') ?? []) as $plan) {
            if (is_array($plan) && isset($plan['id'])) {
                $planes[] = [
                    'id' => (string) $plan['id'],
                    'nombre' => (string) ($plan['name'] ?? $plan['code'] ?? $plan['id']),
                    'virtual' => false,
                ];
            }
        }

        return $planes;
    }

    /**
     * Publica una ocurrencia; su `eventOccurrenceUuid` queda como id y su `eventId` como
     * evento (los avisos de reserva traen el evento).
     *
     * @param  array<string, mixed>  $ocurrencia
     */
    public function crearOcurrencia(string $placeApiKey, array $ocurrencia): ResultadoPlataforma
    {
        $respuesta = $this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->post($this->url('/partner/event-occurrence'), $ocurrencia));

        return $this->resultado($respuesta, fn (Response $r): array => [
            self::id($r->json('eventOccurrenceUuid') ?? $r->json('occurrenceUuid')),
            self::id($r->json('eventId') ?? $r->json('Event.id')),
        ]);
    }

    /**
     * Cambia el instructor, la duración, la descripción o la ventana para reservar.
     *
     * @param  array<string, mixed>  $ocurrencia
     */
    public function actualizarOcurrencia(string $placeApiKey, string $uuid, array $ocurrencia): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->put($this->url('/partner/event-occurrence/'.rawurlencode($uuid)), $ocurrencia)));
    }

    /** Los lugares para usuarios de TotalPass (no puede bajar de los ya reservados). */
    public function actualizarLugares(string $placeApiKey, string $uuid, int $lugares): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->put($this->url('/partner/event-occurrence/'.rawurlencode($uuid).'/slot'), ['slots' => $lugares])));
    }

    /** Retira la ocurrencia. Si ya no existe, ya está retirada. */
    public function retirarOcurrencia(string $placeApiKey, string $uuid): ResultadoPlataforma
    {
        $respuesta = $this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->delete($this->url('/partner/event-occurrence/'.rawurlencode($uuid))));

        return $respuesta instanceof Response && $respuesta->status() === 404
            ? ResultadoPlataforma::hecho()
            : $this->resultado($respuesta);
    }

    /**
     * La reserva como la tiene TotalPass (sus avisos no van firmados: así se sabe que
     * existe y de qué evento es). Null sin respuesta; falso si TotalPass no la tiene.
     *
     * @return array<string, mixed>|false|null
     */
    public function reserva(string $placeApiKey, string $slot): array|false|null
    {
        $respuesta = $this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->get($this->url('/partner/slot'), ['id' => $slot]));
        if (! $respuesta instanceof Response) {
            // Sin conexión o sin llaves: no se sabe.
            return null;
        }
        if (in_array($respuesta->status(), [400, 404], true)) {
            return false;
        }
        if (! $respuesta->successful()) {
            return null;
        }
        $datos = $respuesta->json();
        $reserva = is_array($datos) && array_is_list($datos) ? ($datos[0] ?? null) : $datos;

        return is_array($reserva) && (string) ($reserva['_id'] ?? $reserva['id'] ?? '') === $slot ? $reserva : false;
    }

    /**
     * Confirma o rechaza una reserva. Rechazada, con el motivo de TotalPass
     * (`class_overbooked`, `user_already_in_class`…).
     */
    public function responderReserva(string $placeApiKey, string $slot, ?string $motivo): ResultadoPlataforma
    {
        $cuerpo = $motivo === null ? ['state' => 'confirmed'] : ['state' => 'denied', 'reason' => $motivo];

        return $this->resultado($this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->put($this->url('/partner/slot/confirmSlot/'.rawurlencode($slot)), $cuerpo)));
    }

    /** Cancela una reserva (cuando no se pudo rechazar). Ya cancelada o vencida, ya está. */
    public function cancelarReserva(string $placeApiKey, string $slot): ResultadoPlataforma
    {
        $respuesta = $this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->delete($this->url('/partner/slot/'.rawurlencode($slot))));

        return $respuesta instanceof Response && in_array($respuesta->status(), [400, 404], true)
            ? ResultadoPlataforma::hecho()
            : $this->resultado($respuesta);
    }

    /**
     * Registra la dirección de los avisos de reserva de la sede y enciende la
     * confirmación de cada reserva (así se rechaza la que ya no cabe).
     */
    public function registrarAvisos(string $placeApiKey, string $url): ResultadoPlataforma
    {
        $aviso = $this->resultado($this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->post($this->url('/partner/webhook/subscribe'), ['webhook_url' => $url])));
        if (! $aviso->ok) {
            return $aviso;
        }

        return $this->resultado($this->llamar($placeApiKey, fn (PendingRequest $http): Response => $http
            ->put($this->url('/partner/places/update-configs'), ['hasSlotConfirmation' => true])));
    }

    /** El JWT de la sede para la Booking API; null sin llaves o si TotalPass no lo da. */
    private function sesion(string $placeApiKey): ?string
    {
        if (! $this->disponible() || trim($placeApiKey) === '') {
            return null;
        }
        $clave = self::claveSesion($placeApiKey);
        $guardada = Cache::get($clave);
        if (is_string($guardada) && $guardada !== '') {
            return $guardada;
        }
        try {
            $respuesta = Http::acceptJson()->asJson()->timeout(10)->post($this->url('/partner/auth'), [
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
     * Hace la llamada con la sesión de la sede: el texto de por qué no, o null sin
     * conexión.
     *
     * @param  callable(PendingRequest): Response  $llamada
     */
    private function llamar(string $placeApiKey, callable $llamada): Response|string|null
    {
        if (! $this->disponible()) {
            return 'AgendaUno aún no tiene su llave de integrador de TotalPass.';
        }
        if (trim($placeApiKey) === '') {
            return 'Falta la llave de API de tu sede de TotalPass en la integración.';
        }
        $token = $this->sesion($placeApiKey);
        if ($token === null) {
            return null;
        }
        try {
            $respuesta = $llamada(Http::withToken($token)->acceptJson()->asJson()->timeout(10));
        } catch (Throwable) {
            return null;
        }
        // La sesión pudo vencer antes de tiempo: la siguiente vez se pide otra.
        if ($respuesta->status() === 401) {
            Cache::forget(self::claveSesion($placeApiKey));
        }

        return $respuesta;
    }

    private static function claveSesion(string $placeApiKey): string
    {
        return 'totalpass:reservas:sesion:'.hash('sha256', trim($placeApiKey));
    }

    /**
     * @param  (callable(Response): array{0: string|null, 1: string|null})|null  $ids
     */
    private function resultado(Response|string|null $respuesta, ?callable $ids = null): ResultadoPlataforma
    {
        if (is_string($respuesta)) {
            return ResultadoPlataforma::rechazado($respuesta);
        }
        if ($respuesta === null) {
            return ResultadoPlataforma::sinRespuesta('No se pudo contactar a TotalPass: no respondió a tiempo, no hubo conexión o no aceptó la llave de tu sede.');
        }
        if ($respuesta->successful()) {
            [$id, $evento] = $ids !== null ? $ids($respuesta) : [null, null];

            return ResultadoPlataforma::hecho($id, $evento);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            return ResultadoPlataforma::sinRespuesta('TotalPass no aceptó la sesión de la integración: revisa la llave de tu sede.');
        }
        if ($respuesta->status() === 429 || $respuesta->serverError()) {
            return ResultadoPlataforma::sinRespuesta('TotalPass no está respondiendo (error de su lado). Se reintenta en unos minutos.');
        }

        return ResultadoPlataforma::rechazado('TotalPass no lo aceptó: '.self::mensaje($respuesta));
    }

    private function url(string $ruta): string
    {
        return rtrim((string) config('agendauno.integraciones.totalpass_booking_url'), '/').$ruta;
    }

    private static function id(mixed $valor): ?string
    {
        return is_int($valor) || (is_string($valor) && $valor !== '') ? (string) $valor : null;
    }

    private static function mensaje(Response $respuesta): string
    {
        $mensaje = $respuesta->json('message');
        if (is_array($mensaje)) {
            $mensaje = implode('; ', array_filter($mensaje, 'is_string'));
        }

        return Str::limit(is_string($mensaje) && $mensaje !== '' ? $mensaje : 'código '.$respuesta->status(), 200);
    }
}
