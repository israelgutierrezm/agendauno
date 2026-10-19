<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Reservas desde la app de Wellhub (ADR 0149): el negocio publica las clases que elige,
| de una sucursal, y recibe sus reservas. Quien reserva sigue siendo visitante de la
| plataforma; su reserva ocupa lugar sin consumir créditos y se acepta o rechaza con la
| clase bloqueada.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

const SECRETO_RESERVAS_WELLHUB = 'secreto-de-reservas';

/**
 * Simula la Booking API y la Integration Setup API de Wellhub. `$reservas` es lo que
 * contesta al aceptar o rechazar una reserva.
 */
function wellhubDeReservasSimulado(int $reservas = 204): void
{
    Http::fake(function (Request $r) use ($reservas) {
        $url = $r->url();

        return match (true) {
            str_contains($url, '/setup/v1/gyms/445566/products') => Http::response(['gym_id' => 445566, 'products' => [
                ['product_id' => 987, 'name' => 'Clases ilimitadas', 'virtual' => false],
            ]]),
            str_contains($url, '/v1/systems/gyms/445566/webhooks') => Http::response(null, 204),
            str_contains($url, '/booking/v2/gyms/445566/bookings/') => Http::response(null, $reservas),
            $r->method() === 'POST' && str_ends_with($url, '/booking/v1/gyms/445566/classes') => Http::response(['classes' => [['id' => 77, 'name' => 'Pole', 'reference' => 'x']]], 201),
            $r->method() === 'POST' && str_ends_with($url, '/classes/77/slots') => Http::response(['metadata' => ['total' => 1, 'errors' => 0], 'results' => [['id' => 5001]]], 201),
            str_contains($url, '/classes/77/slots/5001') => Http::response(null, $r->method() === 'PUT' ? 200 : 204),
            str_ends_with($url, '/access/v1/validate') => Http::response(['metadata' => ['total' => 1]], 200),
            default => Http::response(['message' => 'no simulado: '.$url], 500),
        };
    });
}

/**
 * Conecta Wellhub y publica la oferta de la semilla en su sucursal; devuelve la
 * dirección de sus avisos.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function publicarEnWellhub(array $e, array $semilla): string
{
    $url = (string) test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true,
        'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566', 'webhook_secret' => SECRETO_RESERVAS_WELLHUB],
    ], conBearer($e['bearer']))->assertOk()->json('data.automatico.aviso_url');

    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", [
        'activas' => true,
        'sucursal_id' => $semilla['sucursal'],
        'clases' => [['oferta_id' => $semilla['oferta'], 'publicada' => true, 'producto' => '987']],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activas', true);

    return $url;
}

/**
 * Un aviso de reserva de Wellhub, firmado como lo firma Wellhub.
 *
 * @param  array<string, mixed>  $usuario
 */
function avisoDeReservaWellhub(string $url, string $tipo, string $reserva, array $usuario = [], int $slot = 5001): TestResponse
{
    $cuerpo = (string) json_encode([
        'event_type' => $tipo,
        'event_data' => [
            'user' => ['unique_token' => '1234567890123', 'name' => 'Ana Pérez', 'email' => 'ana@correo.mx', ...$usuario],
            'slot' => ['id' => $slot, 'gym_id' => 445566, 'class_id' => 77, 'booking_number' => $reserva],
            'timestamp' => now()->getTimestampMs(),
            'event_id' => 'ev-'.$tipo.'-'.$reserva,
        ],
    ]);

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GYMPASS_SIGNATURE' => strtoupper(hash_hmac('sha1', $cuerpo, SECRETO_RESERVAS_WELLHUB)),
    ], $cuerpo);
}

/**
 * Lo que Wellhub recibió al contestar la reserva.
 *
 * @return list<array<string, mixed>>
 */
function respuestasAWellhub(string $reserva): array
{
    return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/bookings/'.$reserva))
        ->map(fn (array $par): array => $par[0]->data())
        ->values()
        ->all();
}

/**
 * Los lugares de plataforma de la base del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array{estado: string, origen: string, motivo: string|null}>
 */
function lugaresDeWellhub(array $e): array
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    return app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): array => LugarPlataformaTenant::query()
        ->orderBy('id')->get(['estado', 'origen', 'motivo'])->map(fn (LugarPlataformaTenant $l): array => [
            'estado' => $l->estado, 'origen' => $l->origen, 'motivo' => $l->motivo,
        ])->all());
}

it('publica en Wellhub las clases elegidas, con la hora del negocio y sus lugares, y no repite llamadas', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 2, '2026-10-02 08:00:00');
    // Fuera de lo publicado: más allá de los 14 días por adelantado.
    crearSesionTenant($e, $semilla, 2, '2026-10-20 08:00:00');
    wellhubDeReservasSimulado();

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true,
        'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566', 'webhook_secret' => SECRETO_RESERVAS_WELLHUB],
    ], conBearer($e['bearer']))->assertOk();
    $config = $this->getJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", conBearer($e['bearer']))->assertOk();
    expect($config->json('data.productos.0'))->toBe(['id' => '987', 'nombre' => 'Clases ilimitadas', 'virtual' => false])
        ->and($config->json('data.clases.0.oferta_id'))->toBe($semilla['oferta'])
        ->and($config->json('data.activas'))->toBeFalse()
        ->and($config->json('data.dias_publicados'))->toBe(14);

    publicarEnWellhub($e, $semilla);

    // Se registró con Wellhub la dirección de sus avisos (check-in y reservas) con su llave.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v1/systems/gyms/445566/webhooks')
        && collect($r['webhooks'])->pluck('event')->contains('booking-requested')
        && collect($r['webhooks'])->every(fn (array $w): bool => $w['secret'] === SECRETO_RESERVAS_WELLHUB));
    // La clase, con su producto y la oferta como referencia.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/booking/v1/gyms/445566/classes')
        && $r['classes'][0]['product_id'] === 987 && $r['classes'][0]['reference'] === $semilla['oferta']);
    // El horario: a la hora del negocio, con su cupo y la cancelación hasta 12 h antes.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/classes/77/slots')
        && $r['occur_date'] === '2026-10-02T08:00:00-06:00'
        && $r['total_capacity'] === 2 && $r['total_booked'] === 0
        && $r['length_in_minutes'] === 60 && $r['product_id'] === 987
        && $r['booking_window']['closes_at'] === '2026-10-02T08:00:00-06:00');
    expect(Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/slots') && $r->method() === 'POST'))->toHaveCount(1);

    $this->getJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", conBearer($e['bearer']))
        ->assertJsonPath('data.horarios.publicados', 1)
        ->assertJsonPath('data.avisos.registrados', true);

    // Sin cambios, la sincronización no vuelve a llamar.
    $antes = count(Http::recorded());
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();
    expect(count(Http::recorded()))->toBe($antes);

    // Cancelada la clase, se retira de Wellhub.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/classes/77/slots/5001'));
});

it('acepta la reserva desde la app con la clase bloqueada, ocupa su lugar y rechaza cuando ya no hay', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 1, '2026-10-02 08:00:00');
    wellhubDeReservasSimulado();
    $url = publicarEnWellhub($e, $semilla);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $personas = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): int => PersonaTenant::query()->count());

    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();

    expect(respuestasAWellhub('BK_1'))->toBe([['status' => 'RESERVED']])
        ->and(lugaresDeWellhub($e))->toBe([['estado' => 'reservado', 'origen' => 'reserva', 'motivo' => null]]);
    // Wellhub ya no ofrece lugares en la clase.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), '/classes/77/slots/5001')
        && $r['total_capacity'] === 1 && $r['total_booked'] === 1);

    // Otra persona: la clase está llena.
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_2', ['unique_token' => '9999999999999', 'name' => 'Luis', 'email' => 'luis@correo.mx'])->assertOk();
    expect(respuestasAWellhub('BK_2')[0])->toMatchArray(['status' => 'REJECTED', 'reason_category' => 'CLASS_IS_FULL']);

    // Wellhub reintenta el mismo aviso: la misma reserva, sin contestar otra vez.
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();
    expect(respuestasAWellhub('BK_1'))->toHaveCount(1);

    // Es visitante de Wellhub, sin visitas todavía; no es persona del negocio.
    $visitantes = $this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($visitantes)->firstWhere('nombre', 'Ana Pérez'))->toMatchArray(['visitas' => 0, 'proveedor' => 'wellhub'])
        ->and(app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): int => PersonaTenant::query()->count()))->toBe($personas);
});

it('rechaza a quien ya tiene lugar, la clase cancelada y la que ya cerró sus reservas', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-01 08:00:00');
    wellhubDeReservasSimulado();
    $url = publicarEnWellhub($e, $semilla);

    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_2')->assertOk();
    expect(respuestasAWellhub('BK_2')[0]['reason_category'])->toBe('USER_IS_ALREADY_BOOKED');

    // Ya empezó: sus reservas cerraron.
    $this->travelTo(now()->addMinutes(125));
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_3', ['unique_token' => '5555555555555'])->assertOk();
    expect(respuestasAWellhub('BK_3')[0]['reason_category'])->toBe('CHECK_IN_AND_CANCELATION_WINDOWS_CLOSED');
    $this->travelTo(now()->subMinutes(125));

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_4', ['unique_token' => '7777777777777'])->assertOk();
    expect(respuestasAWellhub('BK_4')[0]['reason_category'])->toBe('CLASS_HAS_BEEN_CANCELED');

    // Un horario que no se publicó aquí.
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_5', [], 999)->assertOk();
    expect(respuestasAWellhub('BK_5')[0]['reason_category'])->toBe('CLASS_NOT_FOUND');
});

it('la cancelación libera el lugar; la tardía queda como tardía', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 3, '2026-10-02 08:00:00');
    wellhubDeReservasSimulado();
    $url = publicarEnWellhub($e, $semilla);

    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_2', ['unique_token' => '9999999999999'])->assertOk();
    avisoDeReservaWellhub($url, 'booking-canceled', 'BK_1')->assertOk();
    avisoDeReservaWellhub($url, 'booking-late-canceled', 'BK_2')->assertOk();

    expect(array_column(lugaresDeWellhub($e), 'estado'))->toBe(['cancelado', 'cancelado_tarde']);
    // Wellhub vuelve a ofrecer los tres lugares.
    expect(Http::recorded(fn (Request $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), '/slots/5001'))->last()[0]['total_booked'])->toBe(0);

    $resumen = $this->getJson("/api/v1/app/{$e['slug']}/reportes/plataformas?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))->assertOk();
    expect($resumen->json('data.reservas'))->toMatchArray(['total' => 2, 'canceladas' => 1, 'canceladas_tarde' => 1]);
});

it('el check-in a la clase reservada va a esa clase; la reserva sin check-in queda como no asistió', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-01 07:00:00');
    // Otra clase a la misma hora: sin la reserva no se sabría a cuál vino.
    crearSesionTenant($e, $semilla, 5, '2026-10-01 07:00:00');
    wellhubDeReservasSimulado();
    $url = publicarEnWellhub($e, $semilla);

    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();
    avisoDeReservaWellhub($url, 'booking-requested', 'BK_2', ['unique_token' => '9999999999999'])->assertOk();

    $this->travelTo(now()->addMinutes(50));
    $cuerpo = (string) json_encode([
        'event_type' => 'checkin-booking-occurred',
        'event_data' => [
            'booking' => ['booking_number' => 'BK_1'],
            'user' => ['unique_token' => '1234567890123'],
            'gym' => ['id' => 445566, 'title' => 'Fluo', 'product' => ['description' => 'Clases ilimitadas']],
            'timestamp' => now()->timestamp,
            'expires_at' => now()->addMinutes(90)->timestamp,
        ],
    ]);
    $this->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GYMPASS_SIGNATURE' => strtoupper(hash_hmac('sha1', $cuerpo, SECRETO_RESERVAS_WELLHUB)),
    ], $cuerpo)->assertOk();

    $visitas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->assertOk()->json('data');
    expect($visitas)->toHaveCount(1)
        ->and($visitas[0]['estado'])->toBe('validado')
        ->and(array_column(lugaresDeWellhub($e), 'estado'))->toBe(['asistio', 'reservado']);

    // Terminó la clase: quien reservó y no vino, no asistió.
    $this->travelTo(now()->addMinutes(80));
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();
    expect(array_column(lugaresDeWellhub($e), 'estado'))->toBe(['asistio', 'no_asistio']);
});

it('si no se le puede contestar a Wellhub a tiempo, la reserva se libera', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 3, '2026-10-02 08:00:00');
    wellhubDeReservasSimulado(503);
    $url = publicarEnWellhub($e, $semilla);

    avisoDeReservaWellhub($url, 'booking-requested', 'BK_1')->assertOk();
    // Se intentó y queda pendiente, con su lugar apartado mientras Wellhub espera.
    expect(lugaresDeWellhub($e)[0]['estado'])->toBe('reservado');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $estado = fn (): string => app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): string => (string) EventoPlataformaTenant::query()->where('tipo', 'reserva_solicitada')->value('estado'));
    expect($estado())->toBe('pendiente');

    $this->travelTo(now()->addMinutes(16));
    $this->artisan('agendauno:procesar-plataformas')->assertSuccessful();
    expect($estado())->toBe('fallido')
        ->and(lugaresDeWellhub($e)[0])->toMatchArray(['estado' => 'rechazado', 'motivo' => 'SIN_RESPUESTA']);
});

it('la llave de avisos que genera el negocio al encender las reservas es otra configuración (R13)', function (): void {
    wellhubDeReservasSimulado();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    // Conectado sin llave de avisos: se genera una al encender las reservas.
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();
    $configurada = fn (): ?string => app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): ?string => IntegracionTenant::query()->where('proveedor', 'wellhub')->firstOrFail()->configurada_en?->toIso8601String(),
    );
    $antes = $configurada();

    $this->travel(1)->minutes();
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", [
        'activas' => true,
        'sucursal_id' => $semilla['sucursal'],
        'clases' => [['oferta_id' => $semilla['oferta'], 'publicada' => true, 'producto' => '987']],
    ], conBearer($e['bearer']))->assertOk();

    // Lo que llegó firmado sin ella ya no comprueba la conexión.
    expect($configurada())->not->toBe($antes)
        ->and($configurada())->toBe(now()->toIso8601String());
});

it('solo publica con la integración conectada y un producto por clase', function (): void {
    $e = estudioConSesion('fluo-rs', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    wellhubDeReservasSimulado();

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", [
        'activas' => true, 'sucursal_id' => $semilla['sucursal'], 'clases' => [],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('meta.errors.activas.0', 'Primero conecta y activa la integración con sus llaves.');

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", [
        'activas' => true, 'sucursal_id' => $semilla['sucursal'],
        'clases' => [['oferta_id' => $semilla['oferta'], 'publicada' => true]],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['clases.0.producto'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub/reservas", [
        'activas' => true, 'clases' => [],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['sucursal_id'], 'meta.errors');
    // TotalPass aún no publica clases.
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true, 'credenciales' => ['api_key' => 'tp', 'codigo_gimnasio' => 'G1'],
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass/reservas", [
        'activas' => true, 'sucursal_id' => $semilla['sucursal'], 'clases' => [],
    ], conBearer($e['bearer']))->assertUnprocessable();
});
