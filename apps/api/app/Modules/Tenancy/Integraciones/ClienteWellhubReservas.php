<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use Carbon\CarbonImmutable;
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
 * - Reservas: `PATCH /booking/v2/gyms/{gym}/bookings/{reserva}` la acepta (`RESERVED`), la
 *   rechaza (`REJECTED` con su categoría) o la cancela el gimnasio (`CANCELLED_BY_GYM`).
 *   Hay 15 minutos para contestar; después Wellhub la rechaza.
 * - Avisos: su Integration Setup API registra por gimnasio la dirección y la llave con
 *   que firma cada aviso (`POST /v1/systems/gyms/{gym}/webhooks`; `PUT …/webhooks/{evento}`
 *   cambia uno que ya estaba).
 * - El token: el del negocio o, si no capturó uno, el de AgendaUno como sistema integrado
 *   (`WELLHUB_API_TOKEN`).
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
        // Si una vuelta anterior la creó y no alcanzó a guardarla, es esa (sin duplicar).
        $existente = $this->claseConReferencia($credenciales, $referencia);
        if ($existente !== null) {
            return ResultadoPlataforma::hecho($existente);
        }
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
    public function actualizarClase(array $credenciales, string $clase, string $nombre, string $descripcion, string $producto, string $referencia, bool $visible = true): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->put($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}"), [
                'name' => Str::limit($nombre, 250, ''),
                'description' => $descripcion !== '' ? $descripcion : $nombre,
                'bookable' => $visible,
                'visible' => $visible,
                'product_id' => (int) $producto,
                'reference' => $referencia,
            ])));
    }

    /**
     * La clase del gimnasio con esa referencia (el ULID de la oferta); null si no hay o
     * Wellhub no contestó. Su lista no se filtra: se busca aquí.
     *
     * @param  array<string, string>  $credenciales
     */
    public function claseConReferencia(array $credenciales, string $referencia): ?string
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->get($this->booking("/booking/v1/gyms/{$gym}/classes")));
        if ($referencia === '' || ! $respuesta instanceof Response || ! $respuesta->successful()) {
            return null;
        }
        foreach ((array) $respuesta->json('classes', []) as $clase) {
            if (is_array($clase) && (string) ($clase['reference'] ?? '') === $referencia) {
                return self::id($clase['id'] ?? null);
            }
        }

        return null;
    }

    /**
     * El negocio cancela una reserva de Wellhub (por ejemplo, porque se canceló la clase
     * o la persona no puede ir): Wellhub le avisa.
     *
     * @param  array<string, string>  $credenciales
     */
    public function cancelarReserva(array $credenciales, string $reserva, string $motivo): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->patch($this->booking('/booking/v2/gyms/'.$gym.'/bookings/'.rawurlencode($reserva)), [
                'status' => 'CANCELLED_BY_GYM',
                'reason' => Str::limit($motivo, 250, ''),
            ])));
    }

    /**
     * ¿Wellhub contesta con estas credenciales? (`GET /booking/v1/health`).
     *
     * @param  array<string, string>  $credenciales
     */
    public function probar(array $credenciales): ResultadoPlataforma
    {
        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->get($this->booking('/booking/v1/health'))));
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
    public function responderReserva(array $credenciales, string $reserva, ?string $categoria, ?string $motivo = null, ?string $enlace = null): ResultadoPlataforma
    {
        // Una clase en línea lleva su enlace al aceptarla.
        $cuerpo = $categoria === null
            ? array_filter(['status' => 'RESERVED', 'virtual_class_url' => $enlace])
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
        $aviso = static fn (string $evento): array => [
            'event' => $evento,
            'url' => $url,
            'secret' => $secreto,
            'additional_data' => true,
            'internal_product' => true,
        ];
        // Los que el gimnasio ya tenía (de otro sistema o de antes) se cambian; los demás
        // se crean. Sin poder leerlos, se crean todos.
        $registrados = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->get($this->setup("/v1/systems/gyms/{$gym}/webhooks")));
        $existentes = $registrados instanceof Response && $registrados->successful()
            ? array_values(array_filter(array_map(
                static fn ($w): ?string => is_array($w) && is_string($w['event'] ?? null) ? $w['event'] : null,
                (array) $registrados->json('webhooks', []),
            )))
            : [];
        foreach (array_intersect(self::AVISOS, $existentes) as $evento) {
            $cambio = $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
                ->put($this->setup("/v1/systems/gyms/{$gym}/webhooks/{$evento}"), ['webhook' => $aviso($evento)])));
            if (! $cambio->ok) {
                return $cambio;
            }
        }
        $nuevos = array_values(array_diff(self::AVISOS, $existentes));
        if ($nuevos === []) {
            return ResultadoPlataforma::hecho();
        }

        return $this->resultado($this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            ->post($this->setup("/v1/systems/gyms/{$gym}/webhooks"), [
                'webhooks' => array_map($aviso, $nuevos),
            ])));
    }

    /**
     * Como sistema integrado: la dirección donde Wellhub avisa que un gimnasio eligió a
     * AgendaUno (`SYSTEM_INTEGRATION_REQUESTED`), con la llave de la plataforma.
     */
    public function suscribirSistema(string $url, string $secreto): ResultadoPlataforma
    {
        return $this->resultado($this->llamarSistema(fn (PendingRequest $http): Response => $http
            ->post($this->setup('/v1/systems/gyms/notification-webhook'), [
                'type' => 'SYSTEM_INTEGRATION_REQUESTED',
                'url' => $url,
                'secret' => $secreto,
            ])));
    }

    /**
     * Los gimnasios que eligieron a AgendaUno y si están activos en Wellhub; null sin
     * respuesta.
     *
     * @return array<string, bool>|null
     */
    public function gimnasiosDelSistema(): ?array
    {
        $respuesta = $this->llamarSistema(fn (PendingRequest $http): Response => $http
            ->get($this->setup('/v1/systems/gyms')));
        if (! $respuesta instanceof Response || ! $respuesta->successful()) {
            return null;
        }
        $gimnasios = [];
        foreach ((array) $respuesta->json('partners', []) as $socio) {
            if (is_array($socio) && self::id($socio['id'] ?? null) !== null) {
                $gimnasios[(string) self::id($socio['id'])] = (bool) ($socio['enabled'] ?? false);
            }
        }

        return $gimnasios;
    }

    /**
     * El horario que Wellhub ya tiene a esa hora en la clase.
     *
     * @param  array<string, string>  $credenciales
     */
    private function buscarHorario(array $credenciales, string $clase, string $inicio): ResultadoPlataforma
    {
        $respuesta = $this->llamar($credenciales, fn (PendingRequest $http, string $gym): Response => $http
            // Un minuto de margen: `to` puede no incluir el instante exacto.
            ->get($this->booking("/booking/v1/gyms/{$gym}/classes/{$clase}/slots"), [
                'from' => $inicio,
                'to' => CarbonImmutable::parse($inicio)->addMinute()->toIso8601String(),
            ]));
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
        $token = self::token($credenciales);
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

    /**
     * Una llamada de AgendaUno como sistema integrado (sin gimnasio), con su token.
     *
     * @param  callable(PendingRequest): Response  $llamada
     */
    private function llamarSistema(callable $llamada): Response|string|null
    {
        $token = trim((string) config('agendauno.integraciones.wellhub_api_token'));
        if ($token === '') {
            return 'AgendaUno aún no tiene su token de sistema integrado de Wellhub.';
        }
        try {
            return $llamada(Http::withToken($token)->acceptJson()->asJson()->timeout(10));
        } catch (Throwable) {
            return null;
        }
    }

    private function setup(string $ruta): string
    {
        return rtrim((string) config('agendauno.integraciones.wellhub_setup_url'), '/').$ruta;
    }

    /**
     * El token: el que capturó el negocio o el de AgendaUno como sistema integrado.
     *
     * @param  array<string, string>  $credenciales
     */
    public static function token(array $credenciales): string
    {
        $propio = trim($credenciales['api_key'] ?? '');

        return $propio !== '' ? $propio : trim((string) config('agendauno.integraciones.wellhub_api_token'));
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
