<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Motor de disponibilidad para citas (F-08): los huecos libres de un proveedor salen de
| sus ventanas de atención (hora local de la sucursal), troceadas por la duración del
| servicio, descartando los que ya inician o chocan con una clase/cita suya.
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
 */
function fijarHorarioAtencion(array $e, string $instructorId, string $sucursalUlid, int $dia): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $instructorId,
        'sucursal_id' => $sucursalUlid,
        'horarios' => [['dia_semana' => $dia, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']],
    ], conBearer($e['bearer']))->assertCreated();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array{inicia: string, termina: string}>
 */
function slotsDisponibles(array $e, string $instructorId, string $sucursalUlid, string $fecha): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/disponibilidad?instructor_id={$instructorId}&sucursal_id={$sucursalUlid}&fecha={$fecha}&duracion_minutos=60", conBearer($e['bearer']))
        ->assertOk()->json('data.slots');
}

it('calcula huecos libres del proveedor dentro de su horario de atención', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    pasarNegocioACitas($e);

    $fecha = '2026-10-05';
    $dia = (int) CarbonImmutable::parse($fecha)->isoWeekday();
    fijarHorarioAtencion($e, $coachId, $sede['sucursal'], $dia);

    // 09:00–12:00, servicio de 60 min → 3 huecos (9–10, 10–11, 11–12).
    expect(slotsDisponibles($e, $coachId, $sede['sucursal'], $fecha))->toHaveCount(3);
});

it('excluye los huecos que chocan con una clase/cita del proveedor', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    pasarNegocioACitas($e);

    $fecha = '2026-10-05';
    $dia = (int) CarbonImmutable::parse($fecha)->isoWeekday();
    fijarHorarioAtencion($e, $coachId, $sede['sucursal'], $dia);

    // El proveedor ya tiene una clase 10:00–11:00 ese día.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $coachId, 'inicia_en_local' => "{$fecha} 10:00:00", 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated();

    // Quedan 9–10 y 11–12; 10–11 ocupado.
    expect(slotsDisponibles($e, $coachId, $sede['sucursal'], $fecha))->toHaveCount(2);
});

it('sin proveedor, lista las ventanas de atención de todos (agenda por profesional)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'coach2@correo.mx', 'instructor');
    $ids = collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data'))->pluck('id')->all();
    pasarNegocioACitas($e);

    fijarHorarioAtencion($e, $ids[0], $sede['sucursal'], 1);
    fijarHorarioAtencion($e, $ids[1], $sede['sucursal'], 2);

    $ventanas = $this->getJson("/api/v1/app/{$e['slug']}/horarios-atencion", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($ventanas)->toHaveCount(2);
    expect(collect($ventanas)->pluck('instructor_id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
    expect($ventanas[0]['sucursal_id'])->toBe($sede['sucursal']);
});

it('un día cerrado del negocio no ofrece huecos de cita', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    pasarNegocioACitas($e);

    $fecha = '2026-10-05';
    fijarHorarioAtencion($e, $coachId, $sede['sucursal'], (int) CarbonImmutable::parse($fecha)->isoWeekday());
    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => $fecha, 'motivo' => 'Feriado'], conBearer($e['bearer']))
        ->assertCreated();

    expect(slotsDisponibles($e, $coachId, $sede['sucursal'], $fecha))->toBe([]);
});
