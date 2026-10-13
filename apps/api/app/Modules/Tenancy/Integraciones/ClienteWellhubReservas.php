<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * La Booking API de Wellhub (ADR 0149), con el token y el Gym ID del negocio (los mismos
 * de su Access Control API):
 *
 * - Productos: `GET /setup/v1/gyms/{gym}/products`, con qué se ofrece cada clase.
 * - Clases: `POST /booking/v1/gyms/{gym}/classes`, una por oferta publicada (`PUT` para
 *   cambiar su producto).
 * - Horarios («slots»): `POST …/classes/{clase}/slots` crea, `PUT …/slots/{slot}` cambia
 *   todo, `PATCH …/slots/{slot}` solo los lugares y `DELETE …/slots/{slot}` lo retira
 *   (Wellhub cancela sus reservas).
 * - Reservas: `PATCH /booking/v2/gyms/{gym}/bookings/{reserva}` la acepta (`RESERVED`) o
 *   la rechaza (`REJECTED` con su categoría). Hay 15 minutos; después Wellhub la rechaza.
 * - Avisos: su Integration Setup API registra por gimnasio la dirección y la llave con
 *   que firma cada aviso (`POST /v1/systems/gyms/{gym}/webhooks`).
 *
 * @see https://developers.wellhub.com/product/booking-api/1.0/endpoints
 * @see https://developers.wellhub.com/product/integration-setup-api/1.0/endpoints
 */
class ClienteWellhubReservas
{
    /** Los avisos de Wellhub que AgendaUno atiende. */
    public const AVISOS = ['checkin', 'booking-requested', 'booking-canceled', 'booking-late-canceled', 'checkin-booking-occurred'];

    /**
     * Los productos del gimnasio; null si Wellhub no contestó.
     *
     * @param  array<string, string>  $credenciales
     * @return list<array{id: string, nombre: string, virtual: bool}>|null
     */
    public function productos(array $credenciales): ?array
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->get($this->booking("/setup/v1/gyms/{$gym}/products")));
        if (! $respuesta instanceof Response || ! $respuesta->successful()) {
            return null;
        }
        $productos = [];
        foreach ((array) $respuesta->json('products', []) as $producto) {
            if (is_array($producto) && isset($producto['product_id'])) {
                $productos[] = [
                    'id' => (string) $producto['product_id'],
                    'nombre' => (string) ($producto['name'] ?? $producto['product_id']),
                    'virtual' => (bool) ($producto['virtual'] ?? false),
                ];
            }
        }

        return $productos;
    }

    /**
     * Crea la clase de una oferta; su id queda en el resultado.
     *
     * @param  array<string, string>  $credenciales
     */
    public function crearClase(array $credenciales, string $nombre, string $descripcion, string $producto, string $referencia): ResultadoPlataforma
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->post($this->booking("/booking/v1/gyms/{$gym}/classes"), ['classes' => [[
                'name' => Str::limit($nombre, 250, ''),
                'description' => $descripcion !== '' ? $descripcion : $nombre,
                'bookable' => true,
                'visible' => true,
                'product_id' => (int) $producto,
                'reference' => $referencia,
            ]]]));

        return $this->resultado($respuesta, fn (Response $r): ?string => self::id($r->json('classes.0.id')));
    }

    /**
     * Cambia la clase (su producto, nombre o descripción).
     *
     * @param  array<string, string>  $credenciales
     */
    public function actualizarClase(array $credenciales, string $clase, string $nombre, string $descripcion, string $producto, string $referencia): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->put($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}"), [
                'name' => Str::limit($nombre, 250, ''),
                'description' => $descripcion !== '' ? $descripcion : $nombre,
                'bookable' => true,
                'visible' => true,
                'product_id' => (int) $producto,
                'reference' => $referencia,
            ])));
    }

    /**
     * Publica un horario. Si Wellhub ya lo tiene (mismo inicio y sala: 409), se busca
     * el suyo para no duplicarlo.
     *
     * @param  array<string, string>  $credenciales
     * @param  array<string, mixed>  $horario
     */
    public function crearHorario(array $credenciales, string $clase, array $horario): ResultadoPlataforma
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->post($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots"), $horario));
        if ($respuesta instanceof Response && $respuesta->status() === 409) {
            return $this->buscarHorario($credenciales, $clase, (string) $horario['occur_date']);
        }

        return $this->resultado($respuesta, fn (Response $r): ?string => self::id($r->json('results.0.id') ?? $r->json('id')));
    }

    /**
     * Cambia todo el horario (inicio, duración, instructor, sala o producto).
     *
     * @param  array<string, string>  $credenciales
     * @param  array<string, mixed>  $horario
     */
    public function actualizarHorario(array $credenciales, string $clase, string $slot, array $horario): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->put($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots/{$slot}"), $horario)));
    }

    /**
     * Solo los lugares del horario: los que tiene y los ocupados.
     *
     * @param  array<string, string>  $credenciales
     */
    public function actualizarLugares(array $credenciales, string $clase, string $slot, int $capacidad, int $reservados): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->patch($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots/{$slot}"), [
                'total_capacity' => $capacidad,
                'total_booked' => $reservados,
            ])));
    }

    /**
     * Retira el horario: Wellhub cancela sus reservas. Si ya no existe, ya está retirado.
     *
     * @param  array<string, string>  $credenciales
     */
    public function retirarHorario(array $credenciales, string $clase, string $slot): ResultadoPlataforma
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->delete($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots/{$slot}")));

        return $respuesta instanceof Response && $respuesta->status() === 404
            ? ResultadoPlataforma::hecho()
            : $this->resultado($respuesta);
    }

    /**
     * Acepta o rechaza una reserva. Rechazada, con la categoría de Wellhub
     * (`CLASS_IS_FULL`, `USER_IS_ALREADY_BOOKED`…).
     *
     * @param  array<string, string>  $credenciales
     */
    public function responderReserva(array $credenciales, string $reserva, ?string $categoria, ?string $motivo = null): ResultadoPlataforma
    {
        $cuerpo = $categoria === null
            ? ['status' => 'RESERVED']
            : ['status' => 'REJECTED', 'reason' => Str::limit((string) $motivo, 250, ''), 'reason_category' => $categoria];

        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->patch($this->booking('/booking/v2/gyms/'.$gym.'/bookings/'.rawurlencode($reserva)), $cuerpo)));
    }

    /**
     * Registra con Wellhub, para el gimnasio, la dirección de sus avisos (check-in y
     * reservas) y la llave con que los firma.
     *
     * @param  array<string, string>  $credenciales
     */
    public function registrarAvisos(array $credenciales, string $url, string $secreto): ResultadoPlataforma
    {
        $avisos = array_map(static fn (string $evento): array => [
            'event' => $evento,
            'url' => $url,
            'secret' => $secreto,
            'additional_data' => true,
            'internal_product' => true,
        ], self::AVISOS);

        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->post(rtrim((string) config('agendauno.integraciones.wellhub_setup_url'), '/')."/v1/systems/gyms/{$gym}/webhooks", [
                'webhooks' => $avisos,
            ])));
    }

    /**
     * El horario que Wellhub ya tiene a esa hora en la clase.
     *
     * @param  array<string, string>  $credenciales
     */
    private function buscarHorario(array $credenciales, string $clase, string $inicio): ResultadoPlataforma
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->get($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots"), ['from' => $inicio, 'to' => $inicio]));
        if (! $respuesta instanceof Response || ! $respuesta->successful()) {
            return $this->resultado($respuesta);
        }
        $buscado = strtotime($inicio);
        foreach ((array) $respuesta->json('results', []) as $slot) {
            $fecha = is_array($slot) ? (string) preg_replace('/\[.*\]$/', '', (string) ($slot['occur_date'] ?? '')) : '';
            if ($fecha !== '' && strtotime($fecha) === $buscado && self::id($slot['id'] ?? null) !== null) {
                return ResultadoPlataforma::hecho(self::id($slot['id']));
            }
        }

        return ResultadoPlataforma::rechazado('Wellhub dice que el horario ya existe, pero no lo encontró.');
    }

    /**
     * Hace la llamada con el token y el Gym ID; null sin credenciales o sin conexión.
     *
     * @param  array<string, string>  $credenciales
     * @param  callable(PendingRequest, string): Response  $llamada
     */
    private function llamar(array $credenciales, callable $llamada): Response|string|null
    {
        $token = trim($credenciales['api_key'] ?? '');
        $gimnasio = trim($credenciales['gym_id'] ?? '');
        if ($token === '' || $gimnasio === '') {
            return 'Faltan el token y el Gym ID de Wellhub en la integración.';
        }
        try {
            return $llamada(Http::withToken($token)->acceptJson()->asJson()->timeout(10), rawurlencode($gimnasio));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  (callable(Response): ?string)|null  $id
     */
    private function resultado(Response|string|null $respuesta, ?callable $id = null): ResultadoPlataforma
    {
        if (is_string($respuesta)) {
            return ResultadoPlataforma::rechazado($respuesta);
        }
        if ($respuesta === null) {
            return ResultadoPlataforma::sinRespuesta('No se pudo contactar a Wellhub: no respondió a tiempo o no hubo conexión.');
        }
        if ($respuesta->successful()) {
            return ResultadoPlataforma::hecho($id !== null ? $id($respuesta) : null);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            return ResultadoPlataforma::sinRespuesta('Wellhub no aceptó el token o el Gym ID de la integración: revisa las credenciales.');
        }
        if ($respuesta->status() === 429 || $respuesta->serverError()) {
            return ResultadoPlataforma::sinRespuesta('Wellhub no está respondiendo (error de su lado). Se reintenta en unos minutos.');
        }

        return ResultadoPlataforma::rechazado('Wellhub no lo aceptó: '.self::mensaje($respuesta));
    }

    private function booking(string $ruta): string
    {
        return rtrim((string) config('agendauno.integraciones.wellhub_url'), '/').$ruta;
    }

    private static function id(mixed $valor): ?string
    {
        return is_int($valor) || (is_string($valor) && $valor !== '') ? (string) $valor : null;
    }

    private static function mensaje(Response $respuesta): string
    {
        $mensaje = $respuesta->json('errors.0.message') ?? $respuesta->json('message') ?? $respuesta->json('errors.0.key');

        return Str::limit(is_string($mensaje) && $mensaje !== '' ? $mensaje : 'código '.$respuesta->status(), 200);
    }
}
