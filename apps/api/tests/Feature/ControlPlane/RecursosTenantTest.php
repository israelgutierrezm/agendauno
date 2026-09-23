<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Motor de recursos tenant-local (R3): una sesion puede consumir un recurso
| (sala/cancha/carril). El motor impide sobre-reservarlo: modo `unidad` = 1 sesion a
| la vez; `pool` = hasta `capacidad` simultaneas. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function crearRecurso(array $e, array $semilla, string $modo = 'unidad', int $capacidad = 1): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $semilla['sucursal'], 'nombre' => 'Sala', 'modo' => $modo, 'capacidad' => $capacidad,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function postSesionConRecurso(array $e, array $semilla, string $recurso, string $cuando)
{
    return test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'recurso_id' => $recurso, 'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']));
}

it('un recurso unidad no admite dos sesiones que se solapan, pero si contiguas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $recurso = crearRecurso($e, $semilla, 'unidad');

    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 10:00')->assertCreated();

    // Solapada -> rechazada.
    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 10:30')
        ->assertStatus(422)->assertJsonPath('meta.errors.recurso_id.0', 'El recurso o sala ya está ocupado en ese horario.');

    // Contigua (11:00, justo al terminar la primera) -> permitida.
    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 11:00')->assertCreated();
});

it('un recurso pool admite hasta su capacidad de sesiones simultaneas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $recurso = crearRecurso($e, $semilla, 'pool', 2);

    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 10:00')->assertCreated();
    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 10:30')->assertCreated();

    // La tercera simultanea supera la capacidad (2).
    postSesionConRecurso($e, $semilla, $recurso, '2026-10-05 10:45')
        ->assertStatus(422)->assertJsonPath('meta.errors.recurso_id.0', 'El recurso o sala ya está ocupado en ese horario.');
});

it('la generacion recurrente omite instancias cuyo recurso ya esta ocupado por otra serie', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $recurso = crearRecurso($e, $semilla, 'unidad');

    $desde = Carbon::now()->addDay()->toDateString();
    $hasta = Carbon::now()->addDays(7)->toDateString();

    $cuerpo = [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'recurso_id' => $recurso,
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'hora_local' => '18:00', 'duracion_minutos' => 60,
        'vigente_desde' => Carbon::now()->toDateString(),
    ];
    $p1 = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", $cuerpo, conBearer($e['bearer']))->assertCreated()->json('data.id');
    $p2 = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", $cuerpo, conBearer($e['bearer']))->assertCreated()->json('data.id');

    // P1 materializa los 7 dias en el recurso.
    $c1 = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p1}/generar", ['desde' => $desde, 'hasta' => $hasta], conBearer($e['bearer']))
        ->assertCreated()->json('data.creadas');
    expect($c1)->toBe(7);

    // P2 usa el MISMO recurso a la misma hora: todas chocan -> 0 creadas.
    $c2 = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p2}/generar", ['desde' => $desde, 'hasta' => $hasta], conBearer($e['bearer']))
        ->assertCreated()->json('data.creadas');
    expect($c2)->toBe(0);
});

it('gestionar recursos exige agenda.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/recursos", conBearer($recep))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $semilla['sucursal'], 'nombre' => 'Sala', 'modo' => 'unidad',
    ], conBearer($recep))->assertStatus(403);
});
