<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Recurrencia de agenda tenant-local (R5): una plantilla de horario materializa
| sesiones (con serie_id) de forma IDEMPOTENTE, respetando excepciones (feriados) y
| sin resucitar instancias canceladas (override por instancia). Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una plantilla de horario (todos los dias) y devuelve su ulid + el rango.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 * @return array{plantilla: string, desde: string, hasta: string}
 */
function plantillaHorarioTodaLaSemana(array $e, array $semilla): array
{
    $desde = Carbon::now()->addDay()->toDateString();
    $hasta = Carbon::now()->addDays(7)->toDateString(); // 7 dias

    $plantilla = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7],
        'hora_local' => '18:00',
        'duracion_minutos' => 60,
        'capacidad' => 10,
        'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return ['plantilla' => $plantilla, 'desde' => $desde, 'hasta' => $hasta];
}

it('materializa las sesiones del rango y es idempotente al reejecutar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $p = plantillaHorarioTodaLaSemana($e, $semilla);

    $creadas = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p['plantilla']}/generar", [
        'desde' => $p['desde'], 'hasta' => $p['hasta'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.creadas');
    expect($creadas)->toBe(7);

    // Reejecutar el mismo rango no duplica.
    $creadas = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p['plantilla']}/generar", [
        'desde' => $p['desde'], 'hasta' => $p['hasta'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.creadas');
    expect($creadas)->toBe(0);
});

it('omite las fechas marcadas como excepcion (feriado/cierre)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $p = plantillaHorarioTodaLaSemana($e, $semilla);

    // Un dia del rango es excepcion.
    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", [
        'fecha' => Carbon::now()->addDays(3)->toDateString(), 'motivo' => 'Feriado',
    ], conBearer($e['bearer']))->assertCreated();

    $creadas = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p['plantilla']}/generar", [
        'desde' => $p['desde'], 'hasta' => $p['hasta'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.creadas');

    expect($creadas)->toBe(6); // 7 dias - 1 excepcion
});

it('no resucita una sesion cancelada al reejecutar (override por instancia)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $p = plantillaHorarioTodaLaSemana($e, $semilla);

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p['plantilla']}/generar", [
        'desde' => $p['desde'], 'hasta' => $p['hasta'],
    ], conBearer($e['bearer']))->assertCreated();

    // Cancela una instancia generada.
    $sesion = (string) $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();

    // Reejecutar NO la recrea (firstOrCreate la encuentra por serie_id + inicia_en).
    $creadas = $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$p['plantilla']}/generar", [
        'desde' => $p['desde'], 'hasta' => $p['hasta'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.creadas');
    expect($creadas)->toBe(0);
});

it('cada serie dice su actividad e instructor para filtrar la semana', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $coach,
        'dias_semana' => [1, 3], 'hora_local' => '09:00', 'duracion_minutos' => 60,
        'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.actividad', 'Pole Sport');

    $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.actividad', 'Pole Sport')
        ->assertJsonPath('data.0.instructor_id', $coach)
        ->assertJsonPath('data.0.instructor', 'Personal');
});

it('gestionar plantillas/excepciones exige agenda.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    // Recepcionista ve (agenda.ver)...
    $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($recep))->assertOk();

    // ...pero no crea (agenda.gestionar).
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1], 'hora_local' => '10:00', 'duracion_minutos' => 60,
        'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($recep))->assertStatus(403);
});
