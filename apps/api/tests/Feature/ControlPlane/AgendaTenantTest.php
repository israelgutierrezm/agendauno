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

it('crea una sesion convirtiendo la hora local de la sucursal a UTC (con snapshot de zona)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // Mexico City opera en UTC-6 todo el año (sin horario de verano): 08:00 local => 14:00 UTC.
    $respuesta = $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($e['bearer']));

    $respuesta->assertCreated()
        ->assertJsonPath('data.oferta', 'Nivel 1')
        ->assertJsonPath('data.zona_horaria', 'America/Mexico_City')
        ->assertJsonPath('data.inicia_en', '2026-10-01T14:00:00+00:00')
        ->assertJsonPath('data.termina_en', '2026-10-01T15:00:00+00:00')
        ->assertJsonPath('data.capacidad', 12)
        ->assertJsonPath('data.estado', 'programada');
});

it('incluye la clase del domingo por la tarde aunque en UTC caiga el lunes (borde de semana por zona)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // Domingo 2026-10-11 21:00 en Mexico (UTC-6) = 2026-10-12 03:00 UTC (lunes UTC).
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-11 21:00:00',
        'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.inicia_en', '2026-10-12T03:00:00+00:00');

    // La semana Lun 2026-10-05 .. Dom 2026-10-11 debe incluirla (no truncar en UTC).
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-05&hasta=2026-10-11", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.inicia_en', '2026-10-12T03:00:00+00:00');
});

it('una fecha imposible en la agenda responde 422 por campo, no 500 (ADR 0235)', function (string $consulta, string $campo): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones?{$consulta}", conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors([$campo], 'meta.errors');
})->with([
    'desde imposible' => ['desde=2026-13-45', 'desde'],
    'no es fecha' => ['desde=no-es-fecha', 'desde'],
    'hasta imposible' => ['desde=2026-10-01&hasta=2026-10-45', 'hasta'],
    'hasta antes que desde' => ['desde=2026-10-10&hasta=2026-10-01', 'hasta'],
    'ventana de más de un año' => ['desde=2026-01-01&hasta=2027-06-01', 'hasta'],
]);

it('crear una sesión con una hora local imposible responde 422 (ADR 0235)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-13-45 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonValidationErrors(['inicia_en_local'], 'meta.errors');
});

it('lista y cancela sesiones del estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $sesion = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sesion);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('las sesiones son tenant-local: un estudio no ve las de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $semillaA = agendaSemilla($a);
    $this->postJson("/api/v1/app/{$a['slug']}/sesiones", [
        'oferta_id' => $semillaA['oferta'],
        'sucursal_id' => $semillaA['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$a['slug']}/sesiones", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/sesiones", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('un instructor puede ver la agenda pero no gestionarla (RBAC tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($coach))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($coach))->assertStatus(403);
});
