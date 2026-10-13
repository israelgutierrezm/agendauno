<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Reservas desde la app de TotalPass (ADR 0149): cada sesión publicada es una ocurrencia
| de TotalPass con el plan de su clase; sus reservas se comprueban con TotalPass (sus
| avisos no van firmados) y se confirman o rechazan con la clase bloqueada.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config(['agendauno.integraciones.totalpass_partner_api_key' => 'pk_agendauno']);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Simula las APIs de TotalPass. `$confirmar` es lo que contesta al confirmar o rechazar
 * una reserva (400 si la sede no pide confirmar).
 */
function totalPassDeReservasSimulado(int $confirmar = 200): void
{
    $ocurrencias = 0;
    Http::fake(function (Request $r) use ($confirmar, &$ocurrencias) {
        $url = $r->url();
        $metodo = $r->method();

        return match (true) {
            str_ends_with($url, '/partner/auth') => Http::response(['token' => 'jwt-de-la-sede'], 201),
            str_ends_with($url, '/partner/webhook/create') => Http::response(['ok' => true], 201),
            str_ends_with($url, '/partner/webhook/subscribe') => Http::response(['status' => 'success', 'code' => 200], 201),
            str_ends_with($url, '/partner/places/update-configs') => Http::response(['hasSlotConfirmation' => true], 201),
            str_ends_with($url, '/partner/plans') => Http::response(['name' => 'Fluo', 'Plans' => [
                ['id' => 305, 'name' => 'Plan Clases', 'code' => 'B0KR5QWH'],
            ]]),
            $metodo === 'POST' && str_ends_with($url, '/partner/event-occurrence') => (function () use (&$ocurrencias) {
                $ocurrencias++;

                return Http::response(['eventId' => 900 + $ocurrencias, 'eventOccurrenceUuid' => 'occ-'.$ocurrencias, 'slots' => 3], 201);
            })(),
            str_contains($url, '/partner/event-occurrence/') => Http::response(['message' => 'ok'], 200),
            $metodo === 'GET' && str_contains($url, '/partner/slot') => str_contains($url, 'id=falso')
                ? Http::response(['message' => 'slot_not_found'], 404)
                : Http::response([['_id' => (string) preg_replace('/^.*[?&]id=([^&]+).*$/', '$1', $url), 'eventId' => 901, 'status' => 'active']]),
            str_contains($url, '/partner/slot/confirmSlot/') => Http::response(['message' => ['status' => 'confirmed']], $confirmar),
            $metodo === 'DELETE' && str_contains($url, '/partner/slot/') => Http::response(['message' => 'Slot removed successfully'], 200),
            default => Http::response(['message' => 'no simulado: '.$url], 500),
        };
    });
}

/**
 * Conecta TotalPass (con la llave de la sede) y publica la oferta de la semilla en su
 * sucursal con el plan 305; devuelve la dirección de sus avisos.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function publicarEnTotalPass(array $e, array $semilla): string
{
    $url = (string) test()->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true,
        'credenciales' => ['api_key' => 'tp_key', 'codigo_gimnasio' => 'G1', 'place_api_key' => 'place_1'],
    ], conBearer($e['bearer']))->assertOk()->json('data.automatico.aviso_url');

    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass/reservas", [
        'activas' => true,
        'sucursal_id' => $semilla['sucursal'],
        'clases' => [['oferta_id' => $semilla['oferta'], 'publicada' => true, 'producto' => '305']],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activas', true);

    return $url;
}

/**
 * El aviso de una reserva de TotalPass (sin firma: TotalPass no la documenta).
 *
 * @param  array<string, mixed>  $usuario
 */
function avisoDeReservaTotalPass(string $url, string $slot, string $estado = 'active', array $usuario = [], int $evento = 901): TestResponse
{
    return test()->postJson($url, [
        'event' => ['id' => $evento, 'title' => 'Pole Sport Nivel 1', 'plan_code' => 'B0KR5QWH'],
        'place' => ['place' => 12, 'name' => 'Fluo'],
        'user' => [
            'name' => 'Ana Pérez', 'email' => 'ana@correo.mx', 'phone' => '5512345678',
            'document_number' => 'PEPA900101', 'document_type' => 'curp', 'code' => 'TP-ANA',
            ...$usuario,
        ],
        'slot' => [
            'id' => $slot, 'status' => $estado, 'date' => '2026-10-02T14:00:00.000Z', 'seat' => null,
            'confirmation_url' => 'https://otro.example/partner/slot/confirmSlot/'.$slot,
        ],
    ]);
}

/**
 * Lo que TotalPass recibió al confirmar o rechazar la reserva.
 *
 * @return list<array<string, mixed>>
 */
function respuestasATotalPass(string $slot): array
{
    return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/partner/slot/confirmSlot/'.$slot))
        ->map(fn (array $par): array => $par[0]->data())
        ->values()
        ->all();
}

/**
 * Lo que hay en la base del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function enElNegocioTotalPass(array $e, Closure $consulta): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $consulta);
}

it('publica cada sesión como ocurrencia de TotalPass con su plan y la hora de la Ciudad de México; si cambia la hora, la vuelve a publicar', function (): void {
    $e = estudioConSesion('fluo-tp', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 3, '2026-10-02 08:00:00');
    totalPassDeReservasSimulado();

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true, 'credenciales' => ['api_key' => 'tp_key', 'codigo_gimnasio' => 'G1', 'place_api_key' => 'place_1'],
    ], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/integraciones/totalpass/reservas", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.disponible', true)
        ->assertJsonPath('data.productos.0', ['id' => '305', 'nombre' => 'Plan Clases', 'virtual' => false]);

    publicarEnTotalPass($e, $semilla);

    // Se registró su aviso de reservas y que cada reserva se confirme.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/partner/webhook/subscribe')
        && str_contains((string) $r['webhook_url'], "/webhooks/bienestar/{$e['slug']}/totalpass/"));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/partner/places/update-configs') && $r['hasSlotConfirmation'] === true);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/partner/event-occurrence')
        && $r['planId'] === 305 && $r['eventDate'] === '2026-10-02' && $r['startTime'] === '08:00 AM'
        && $r['timezone'] === 'es-MX' && $r['slots'] === 3 && $r['duration'] === 60
        && $r['externalReference'] === $sesion && $r['bookingWindow']['maxTimeToBook'] === '2026-10-02 07:59 AM');

    // Otra hora: TotalPass no la cambia en su lugar, así que se retira y se publica de nuevo.
    enElNegocioTotalPass($e, fn () => SesionTenant::query()->where('ulid', $sesion)->firstOrFail()
        ->fill(['inicia_en' => '2026-10-02 15:00:00', 'termina_en' => '2026-10-02 16:00:00'])->save());
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/partner/event-occurrence/occ-1'));
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/partner/event-occurrence')
        && $r['startTime'] === '09:00 AM');
    expect(enElNegocioTotalPass($e, fn () => HorarioPlataformaTenant::query()->firstOrFail()->only(['id_externo', 'evento_externo', 'estado'])))
        ->toBe(['id_externo' => 'occ-2', 'evento_externo' => '902', 'estado' => 'publicado']);
});

it('comprueba la reserva con TotalPass y la confirma o la rechaza con la clase bloqueada', function (): void {
    $e = estudioConSesion('fluo-tp', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 1, '2026-10-02 08:00:00');
    totalPassDeReservasSimulado();
    $url = publicarEnTotalPass($e, $semilla);

    avisoDeReservaTotalPass($url, 'sl1')->assertOk();

    // Se comprobó con TotalPass y se confirmó con la dirección de TotalPass, no la del aviso.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), '/partner/slot?id=sl1'));
    expect(respuestasATotalPass('sl1'))->toBe([['state' => 'confirmed']]);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'otro.example'));
    // TotalPass ya no ofrece más lugares: el suyo está tomado.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/partner/event-occurrence/occ-1/slot') && $r['slots'] === 1);

    // Otra persona: ya no hay lugar.
    avisoDeReservaTotalPass($url, 'sl2', 'active', ['code' => 'TP-LUIS', 'name' => 'Luis'])->assertOk();
    expect(respuestasATotalPass('sl2'))->toBe([['state' => 'denied', 'reason' => 'class_overbooked']]);

    // Un aviso de una reserva que TotalPass no tiene no aparta nada.
    avisoDeReservaTotalPass($url, 'falso', 'active', ['code' => 'TP-X'])->assertOk();
    expect(respuestasATotalPass('falso'))->toBe([]);

    $lugares = enElNegocioTotalPass($e, fn (): array => LugarPlataformaTenant::query()->orderBy('id')->pluck('estado')->all());
    expect($lugares)->toBe(['reservado', 'rechazado']);
    // Es visitante de TotalPass sin su documento de identidad.
    $visitante = collect($this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma?proveedor=totalpass", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id_externo', 'TP-ANA');
    expect($visitante)->toMatchArray(['nombre' => 'Ana Pérez', 'producto' => 'B0KR5QWH', 'visitas' => 0])
        ->and(json_encode($visitante))->not->toContain('PEPA900101');
});

it('si la sede no pide confirmar, la rechazada se cancela; la cancelación libera el lugar', function (): void {
    $e = estudioConSesion('fluo-tp', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 1, '2026-10-02 08:00:00');
    totalPassDeReservasSimulado(400);
    $url = publicarEnTotalPass($e, $semilla);

    avisoDeReservaTotalPass($url, 'sl1')->assertOk();
    avisoDeReservaTotalPass($url, 'sl2', 'active', ['code' => 'TP-LUIS'])->assertOk();

    // La aceptada ya quedó en TotalPass; la que no cabe se cancela allá.
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/partner/slot/sl1'));
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/partner/slot/sl2'));

    avisoDeReservaTotalPass($url, 'sl1', 'canceled')->assertOk();
    expect(enElNegocioTotalPass($e, fn (): array => LugarPlataformaTenant::query()->orderBy('id')->pluck('estado')->all()))
        ->toBe(['cancelado', 'rechazado'])
        ->and(enElNegocioTotalPass($e, fn (): int => EventoPlataformaTenant::query()->where('estado', 'pendiente')->count()))->toBe(0);
});

it('sin la llave de integrador de AgendaUno no se publica en TotalPass', function (): void {
    config(['agendauno.integraciones.totalpass_partner_api_key' => null]);
    $e = estudioConSesion('fluo-tp', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    totalPassDeReservasSimulado();

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true, 'credenciales' => ['api_key' => 'tp_key', 'codigo_gimnasio' => 'G1', 'place_api_key' => 'place_1'],
    ], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/integraciones/totalpass/reservas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.disponible', false);
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass/reservas", [
        'activas' => true, 'sucursal_id' => $semilla['sucursal'],
        'clases' => [['oferta_id' => $semilla['oferta'], 'publicada' => true, 'producto' => '305']],
    ], conBearer($e['bearer']))->assertUnprocessable();
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/partner/event-occurrence'));
});
