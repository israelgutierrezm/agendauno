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
 * Crea un instructor y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function instructorUlid(array $e, string $email): string
{
    personalConSesion($e['slug'], $e['bearer'], $email, 'instructor');

    return (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data'))->first()['id'];
}

/**
 * Crea una sesión con instructor a una hora dada (devuelve la respuesta).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function crearSesionInstructor(array $e, array $semilla, string $instructor, string $cuando)
{
    return test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'instructor_id' => $instructor, 'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']));
}

it('bloquea crear una clase con el instructor ocupado en ese horario', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $instructor = instructorUlid($e, 'profe@correo.mx');

    crearSesionInstructor($e, $semilla, $instructor, '2026-10-01 08:00:00')->assertCreated();

    // Se solapa (08:30–09:30) con el mismo instructor → 422.
    crearSesionInstructor($e, $semilla, $instructor, '2026-10-01 08:30:00')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('meta.errors.instructor_id.0', 'El instructor ya tiene una clase en ese horario (Nivel 1).');
});

it('permite el mismo instructor en horarios que no se solapan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $instructor = instructorUlid($e, 'profe@correo.mx');

    crearSesionInstructor($e, $semilla, $instructor, '2026-10-01 08:00:00')->assertCreated();
    // 10:00–11:00 no choca.
    crearSesionInstructor($e, $semilla, $instructor, '2026-10-01 10:00:00')->assertCreated();
});

it('verificar reporta el conflicto sin guardar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $instructor = instructorUlid($e, 'profe@correo.mx');
    crearSesionInstructor($e, $semilla, $instructor, '2026-10-01 08:00:00')->assertCreated();

    $data = $this->postJson("/api/v1/app/{$e['slug']}/sesiones/verificar", [
        'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $instructor,
        'inicia_en_local' => '2026-10-01 08:30:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['conflictos'])->toHaveCount(1);
    expect($data['conflictos'][0]['tipo'])->toBe('instructor');

    // No creó nada: sigue habiendo 1 sesión.
    expect($this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(1);
});

it('verificar sin conflictos devuelve la lista vacía', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $instructor = instructorUlid($e, 'profe@correo.mx');

    $data = $this->postJson("/api/v1/app/{$e['slug']}/sesiones/verificar", [
        'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $instructor,
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['conflictos'])->toBe([]);
});
