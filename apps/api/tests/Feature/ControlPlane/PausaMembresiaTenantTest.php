<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Pausar (congelar) una membresía: en pausa no reserva ni se cobra; al reanudar
| (a mano o sola al terminar) se corren su próximo cobro, su ciclo y su vencimiento
| por los días en pausa.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Membresía mensual ilimitada vendida hoy a "Ana"; devuelve persona, acuerdo y la
 * semilla de agenda.
 *
 * @return array{slug: string, bearer: string, persona: string, acuerdo: string, semilla: array{oferta: string, sucursal: string}}
 */
function membresiaParaPausar(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    return [...$e, 'persona' => $persona, 'acuerdo' => $acuerdo, 'semilla' => agendaSemilla($e)];
}

/**
 * Derecho de la membresía tal como lo ve la ficha.
 *
 * @param  array{slug: string, bearer: string, persona: string}  $m
 * @return array<string, mixed>
 */
function derechoEnFicha(array $m): array
{
    return test()->getJson("/api/v1/app/{$m['slug']}/miembros/{$m['persona']}/ficha", conBearer($m['bearer']))
        ->assertOk()->json('data.derechos.0');
}

/**
 * @param  array{slug: string, bearer: string, persona: string, semilla: array{oferta: string, sucursal: string}}  $m
 */
function reservarEl(array $m, string $cuando): int
{
    $sesion = crearSesionTenant($m, $m['semilla'], 5, $cuando);

    return test()->postJson("/api/v1/app/{$m['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $m['persona']], conBearer($m['bearer']))
        ->status();
}

it('en pausa no reserva; al terminar se reanuda sola y corre sus fechas', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $antes = derechoEnFicha($m);
    // Con su membresía ilimitada vigente, el resumen lo dice (no solo un saldo).
    $this->getJson("/api/v1/app/{$m['slug']}/miembros/{$m['persona']}/resumen", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.ilimitado', true);

    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}/pausar", [
        'hasta' => '2026-09-19', 'motivo' => 'Vacaciones',
    ], conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'pausado')
        ->assertJsonPath('data.pausa.hasta', '2026-09-19');

    // En pausa: no reserva y recepción lo ve "en pausa".
    expect(reservarEl($m, '2026-09-15 19:00:00'))->toBe(422);
    $this->getJson("/api/v1/app/{$m['slug']}/miembros/{$m['persona']}/resumen", conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.membresia.estado', 'pausada')
        ->assertJsonPath('data.ilimitado', false)
        ->assertJsonPath('data.membresia.pausada_hasta', '2026-09-19')
        ->assertJsonPath('data.alertas', ['membresia_pausada']);
    expect(derechoEnFicha($m)['pausa_hasta'])->toBe('2026-09-19');

    // Al día siguiente del último día en pausa se reanuda sola: 10 días después.
    // 00:10 del 20 en el negocio (Ciudad de México): ya terminó su último día de pausa.
    $this->travelTo('2026-09-20 06:10:00');
    $this->artisan('agendauno:reanudar-pausas')->assertSuccessful();

    $despues = derechoEnFicha($m);
    expect($despues['estado'])->toBe('activo')
        ->and($despues['pausa_hasta'])->toBeNull()
        ->and($despues['proxima_cobro_en'])
        ->toBe(CarbonImmutable::parse($antes['proxima_cobro_en'])->addDays(10)->toDateString());
    expect(reservarEl($m, '2026-09-22 19:00:00'))->toBe(201);
});

it('reanudar antes solo corre los días que estuvo en pausa', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $antes = derechoEnFicha($m);

    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}/pausar", ['hasta' => '2026-10-09'], conBearer($m['bearer']))->assertOk();

    $this->travelTo('2026-09-13 09:00:00');
    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}/reanudar", [], conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'activo')
        ->assertJsonPath('data.pausa', null)
        ->assertJsonPath('data.proxima_cobro_en', CarbonImmutable::parse($antes['proxima_cobro_en'])->addDays(3)->toDateString());
});

it('en pausa no se cobra la renovación', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}/pausar", ['hasta' => '2026-09-30'], conBearer($m['bearer']))->assertOk();

    $resultado = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $m['slug'])->firstOrFail(),
        fn (): string => app(CobroRecurrenteTenant::class)->renovar(
            AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail(),
            'manual',
        ),
    );

    expect($resultado)->toBe('omitido');
});

it('solo pausa una membresía activa, sin pago pendiente y con fechas válidas', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $url = "/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}";

    $this->postJson("{$url}/pausar", ['hasta' => '2026-09-01'], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_PAUSE_NOT_ALLOWED');
    $this->postJson("{$url}/pausar", ['hasta' => '2027-06-01'], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_PAUSE_NOT_ALLOWED');
    $this->postJson("{$url}/reanudar", [], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_PAUSE_NOT_ALLOWED');

    // Con un cobro fallido (en mora) primero se regulariza.
    $this->postJson("{$url}/cobro-fallido", [], conBearer($m['bearer']))->assertCreated();
    $this->postJson("{$url}/pausar", ['hasta' => '2026-09-20'], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_PAUSE_NOT_ALLOWED');
    $this->postJson("{$url}/regularizar", [], conBearer($m['bearer']))->assertOk();

    $this->postJson("{$url}/pausar", ['hasta' => '2026-09-20'], conBearer($m['bearer']))->assertOk();
    // Ya en pausa: no se pausa dos veces.
    $this->postJson("{$url}/pausar", ['hasta' => '2026-09-25'], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_PAUSE_NOT_ALLOWED');
});

it('pausar exige membresias.gestionar', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $instructor = personalConSesion($m['slug'], $m['bearer'], 'beto@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}/pausar", ['hasta' => '2026-09-20'], conBearer($instructor))
        ->assertStatus(403);
});

it('cuánto puede durar una pausa lo decide el negocio', function (): void {
    $this->travelTo('2026-09-10 12:00:00');
    $m = membresiaParaPausar();
    $url = "/api/v1/app/{$m['slug']}/acuerdos/{$m['acuerdo']}";
    $this->putJson("/api/v1/app/{$m['slug']}/parametros", ['valores' => ['membresias.max_dias_pausa' => 30]], conBearer($m['bearer']))
        ->assertOk();

    // Del 10 de septiembre al 10 de octubre son 31 días.
    $this->postJson("{$url}/pausar", ['hasta' => '2026-10-10'], conBearer($m['bearer']))
        ->assertStatus(422)->assertJsonPath('message', 'Una pausa puede durar hasta 30 días.');
    $this->postJson("{$url}/pausar", ['hasta' => '2026-10-09'], conBearer($m['bearer']))->assertOk();
});
