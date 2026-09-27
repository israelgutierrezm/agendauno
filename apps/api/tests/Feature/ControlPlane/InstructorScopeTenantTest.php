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
 * Ulid del usuario autenticado por su bearer.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function usuarioUlid(array $e, string $bearer): string
{
    return (string) test()->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($bearer))
        ->assertOk()->json('data.usuario.ulid');
}

/**
 * Crea una sesion asignada a un instructor (ulid de usuario) y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function sesionConInstructor(array $e, array $semilla, ?string $instructorUlid): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'instructor_id' => $instructorUlid,
        'inicia_en_local' => '2026-10-01 08:00:00',
        'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('un instructor solo ve el roster de sus sesiones asignadas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach1 = personalConSesion($e['slug'], $e['bearer'], 'coach1@correo.mx', 'instructor');
    $coach2 = personalConSesion($e['slug'], $e['bearer'], 'coach2@correo.mx', 'instructor');
    $sesion = sesionConInstructor($e, $semilla, usuarioUlid($e, $coach1));

    // coach1 (asignado) ve el roster; coach2 (no asignado) recibe 403.
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($coach1))->assertOk();
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($coach2))->assertStatus(403);
    // El staff (propietario) ve cualquiera.
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk();
});

it('un instructor solo marca asistencia en sus sesiones asignadas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach1 = personalConSesion($e['slug'], $e['bearer'], 'coach1@correo.mx', 'instructor');
    $coach2 = personalConSesion($e['slug'], $e['bearer'], 'coach2@correo.mx', 'instructor');
    $sesion = sesionConInstructor($e, $semilla, usuarioUlid($e, $coach1));

    // El staff reserva a un miembro con pack.
    $vp = venderPackAMiembroTenant($e, 8000);
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // coach2 (no asignado) no puede marcar; coach1 (asignado) si.
    test()->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($coach2))
        ->assertStatus(403);
    test()->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($coach1))
        ->assertCreated();
});

it('la agenda de un instructor solo lista sus sesiones asignadas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach1 = personalConSesion($e['slug'], $e['bearer'], 'coach1@correo.mx', 'instructor');
    $coach2 = personalConSesion($e['slug'], $e['bearer'], 'coach2@correo.mx', 'instructor');

    sesionConInstructor($e, $semilla, usuarioUlid($e, $coach1));
    sesionConInstructor($e, $semilla, usuarioUlid($e, $coach2));

    test()->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($coach1))->assertOk()->assertJsonCount(1, 'data');
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($coach2))->assertOk()->assertJsonCount(1, 'data');
    // El propietario ve todas.
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))->assertOk()->assertJsonCount(2, 'data');
});

it('el staff puede (re)asignar el instructor de una sesion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach1 = personalConSesion($e['slug'], $e['bearer'], 'coach1@correo.mx', 'instructor');
    $sesion = sesionConInstructor($e, $semilla, null);

    // Sin asignar: coach1 no ve el roster.
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($coach1))->assertStatus(403);

    // El propietario lo asigna; ahora coach1 si.
    test()->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/instructor", [
        'instructor_id' => usuarioUlid($e, $coach1),
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.instructor', 'Personal');

    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($coach1))->assertOk();
});

it('su calendario: el instructor ve solo sus sesiones, con la sede; el dueño que imparte filtra las suyas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach1 = personalConSesion($e['slug'], $e['bearer'], 'coach1@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'coach2@correo.mx', 'instructor');
    $suya = sesionConInstructor($e, $semilla, usuarioUlid($e, $coach1));
    sesionConInstructor($e, $semilla, usuarioIdPorEmail($e, 'coach2@correo.mx'));
    $delDueno = sesionConInstructor($e, $semilla, usuarioUlid($e, $e['bearer']));
    $rango = 'desde=2026-10-01&hasta=2026-10-01';

    $lista = test()->getJson("/api/v1/app/{$e['slug']}/sesiones?{$rango}", conBearer($coach1))->assertOk()->json('data');
    expect(array_column($lista, 'id'))->toBe([$suya])
        ->and($lista[0]['sucursal'])->toBe('Roma Norte');

    // Quien ve toda la agenda pide solo las suyas con su id.
    $propias = test()->getJson("/api/v1/app/{$e['slug']}/sesiones?{$rango}&instructor_id=".usuarioUlid($e, $e['bearer']), conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect(array_column($propias, 'id'))->toBe([$delDueno]);
});
