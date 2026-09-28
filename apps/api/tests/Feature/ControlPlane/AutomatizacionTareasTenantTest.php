<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una regla de automatizacion via API y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function crearRegla(array $e, array $datos): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/automatizaciones", $datos, conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * Reserva a un miembro nuevo (emite reserva.creada) y devuelve el ulid de la sesion.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function reservarParaEvento(array $e, string $nombre = 'Ana'): string
{
    $vp = venderPackAMiembroTenant($e, 8000, $nombre);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    return $sesion;
}

it('una regla genera una tarea al ocurrir el evento, renderizada y con vencimiento', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearRegla($e, [
        'nombre' => 'Bienvenida', 'evento' => 'reserva.creada',
        'condiciones' => ['estado' => 'confirmada'],
        'titulo_plantilla' => 'Dar seguimiento a {{persona_nombre}} ({{estado}})',
        'delay_minutos' => 60,
    ]);

    reservarParaEvento($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $resp = $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))->assertOk();
    $resp->assertJsonPath('pendientes', 1)
        ->assertJsonPath('data.0.titulo', 'Dar seguimiento a Ana (confirmada)')
        ->assertJsonPath('data.0.persona', 'Ana')
        ->assertJsonPath('data.0.automatica', true)
        ->assertJsonPath('data.0.estado', 'pendiente');
    expect($resp->json('data.0.vence_en'))->not->toBeNull();
});

it('no genera tarea si el payload no cumple la condicion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearRegla($e, [
        'nombre' => 'Solo espera', 'evento' => 'reserva.creada',
        'condiciones' => ['estado' => 'en_espera'], // la reserva sera confirmada -> no coincide
        'titulo_plantilla' => 'No deberia crearse',
    ]);

    reservarParaEvento($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))->assertOk()->assertJsonPath('pendientes', 0);
});

it('una regla inactiva no dispara', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearRegla($e, [
        'nombre' => 'Apagada', 'evento' => 'reserva.creada',
        'titulo_plantilla' => 'X', 'activa' => false,
    ]);

    reservarParaEvento($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))->assertOk()->assertJsonPath('pendientes', 0);
});

it('no duplica la tarea aunque el relay corra varias veces (idempotente)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearRegla($e, ['nombre' => 'Una vez', 'evento' => 'reserva.creada', 'titulo_plantilla' => 'Seguimiento']);

    reservarParaEvento($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))->assertOk()->assertJsonPath('pendientes', 1);
});

it('permite crear una tarea manual y completarla', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $tarea = (string) $this->postJson("/api/v1/app/{$e['slug']}/tareas", [
        'titulo' => 'Llamar a proveedor', 'detalle' => 'Cotizar colchonetas',
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.automatica', false)
        ->assertJsonPath('data.estado', 'pendiente')->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$tarea}/completar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'completada');

    // Ya no aparece entre las pendientes; si en completadas.
    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))->assertOk()->assertJsonPath('pendientes', 0);
    $this->getJson("/api/v1/app/{$e['slug']}/tareas?estado=completada", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('RBAC: el instructor ve tareas pero no gestiona reglas de automatizacion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($coach))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/automatizaciones", conBearer($coach))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/automatizaciones", [
        'nombre' => 'X', 'evento' => 'reserva.creada', 'titulo_plantilla' => 'X',
    ], conBearer($coach))->assertStatus(403);
});

it('reglas y tareas son tenant-local: un estudio no ve las del otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    crearRegla($a, ['nombre' => 'Solo A', 'evento' => 'reserva.creada', 'titulo_plantilla' => 'A']);
    reservarParaEvento($a);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    // A tiene su regla y su tarea; B no ve ninguna.
    $this->getJson("/api/v1/app/{$a['slug']}/tareas", conBearer($a['bearer']))->assertOk()->assertJsonPath('pendientes', 1);
    $this->getJson("/api/v1/app/{$b['slug']}/automatizaciones", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/tareas", conBearer($b['bearer']))->assertOk()->assertJsonPath('pendientes', 0);
});
