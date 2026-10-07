<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| 2.3 de la fase 2: tiempos de preparación y limpieza. Un masaje de 60 minutos con 15
| de limpieza ocupa 75 de agenda, pero al cliente se le comunican 60; no se ofrecen
| horarios que invadan esos márgenes, ni para el profesional ni para la sala.
| Hoy es 1 de enero de 2030; el lunes 7 a las 10:00 en CDMX son las 16:00 UTC.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio de citas: un masaje de 60 min con 15 de limpieza, una terapeuta que atiende
 * de 08:00 a 20:00 y una clienta con cuenta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string, ana: array{slug: string, bearer: string}}
 */
function spaConLimpieza(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 90000,
        'duracion_minutos' => 60, 'limpieza_min' => 15,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.limpieza_min', 15);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'coach' => $coach, 'ana' => alumnoConSesion($e, 'Ana', 'ana@correo.mx')];
}

/**
 * Horas locales (HH:MM) que ofrece la disponibilidad del servicio ese día.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string, ana: array{slug: string, bearer: string}}  $c
 * @return list<string>
 */
function horasLibres(array $c, string $fecha): array
{
    $slots = test()->getJson("/api/v1/app/{$c['e']['slug']}/mi/citas/disponibilidad?instructor_id={$c['coach']}&sucursal_id={$c['sede']['sucursal']}&fecha={$fecha}&oferta_id={$c['sede']['oferta']}", conBearer($c['ana']['bearer']))
        ->assertOk()->json('data.slots');

    return array_map(fn (array $s): string => CarbonImmutable::parse($s['inicia'])->setTimezone('America/Mexico_City')->format('H:i'), $slots);
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string, ana: array{slug: string, bearer: string}}  $c
 */
function citaDeAna(array $c, string $cuando): TestResponse
{
    return test()->postJson("/api/v1/app/{$c['e']['slug']}/mi/citas", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['coach'],
        'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($c['ana']['bearer']));
}

it('no se ofrece ni se agenda un horario que invada la limpieza de otra cita', function (): void {
    $c = spaConLimpieza();
    citaDeAna($c, '2030-01-07 10:00:00')->assertCreated();

    // Una cita tras otra con su limpieza: 08:00, 09:15, 10:30, 11:45… salvo lo que
    // choca con 10:00–11:15.
    $libres = horasLibres($c, '2030-01-07');
    expect($libres)->toContain('08:00')->toContain('11:45')
        ->not->toContain('09:15')->not->toContain('10:30');

    citaDeAna($c, '2030-01-07 11:00:00')->assertUnprocessable()->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
    citaDeAna($c, '2030-01-07 11:15:00')->assertCreated();
});

it('al cliente se le comunica la atención y el equipo ve lo que ocupa', function (): void {
    $c = spaConLimpieza();
    $cita = citaDeAna($c, '2030-01-07 10:00:00')->assertCreated()->json('data');

    $mia = collect(test()->getJson("/api/v1/app/{$c['e']['slug']}/mi/perfil", conBearer($c['ana']['bearer']))->json('data.reservas'))
        ->firstWhere('id', $cita['id']);
    expect($mia['inicia_en'])->toBe('2030-01-07T16:00:00+00:00');

    $sesion = collect($this->getJson("/api/v1/app/{$c['e']['slug']}/sesiones?desde=2030-01-07&hasta=2030-01-08", conBearer($c['e']['bearer']))->assertOk()->json('data'))
        ->firstWhere('tipo', 'cita');
    expect($sesion['termina_en'])->toBe('2030-01-07T17:00:00+00:00')
        ->and($sesion['ocupa_hasta'])->toBe('2030-01-07T17:15:00+00:00');
});

it('cambiar los márgenes del servicio no mueve lo ya agendado', function (): void {
    $c = spaConLimpieza();
    citaDeAna($c, '2030-01-07 10:00:00')->assertCreated();

    $this->putJson("/api/v1/app/{$c['e']['slug']}/ofertas/{$c['sede']['oferta']}", [
        'lugares' => 0, 'limpieza_min' => 30,
    ], conBearer($c['e']['bearer']))->assertOk();

    // La cita de las 10:00 conserva sus 15 min: a las 11:15 ya se puede.
    citaDeAna($c, '2030-01-07 11:15:00')->assertCreated();
});

it('la sala y el profesional respetan la limpieza entre clases', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'limpieza_min' => 15], conBearer($e['bearer']))->assertOk();
    $sala = (string) $this->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $sede['sucursal'], 'nombre' => 'Salón A', 'modo' => 'unidad',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    personalConSesion($e['slug'], $e['bearer'], 'beto@correo.mx', 'instructor');
    $beto = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $clase = fn (array $extra, string $hora) => $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'inicia_en_local' => "2030-01-07 {$hora}:00", 'duracion_minutos' => 60, ...$extra,
    ], conBearer($e['bearer']));

    $clase(['recurso_id' => $sala, 'instructor_id' => $beto], '08:00')->assertCreated();

    $clase(['recurso_id' => $sala], '09:00')
        ->assertUnprocessable()->assertJsonPath('meta.errors.recurso_id.0', 'Salón A no está disponible en ese horario.');
    $clase(['instructor_id' => $beto], '09:00')
        ->assertUnprocessable()->assertJsonPath('meta.errors.instructor_id.0', 'Ese horario invade la preparación o limpieza de Nivel 1 de las 08:00.');
    $clase(['recurso_id' => $sala, 'instructor_id' => $beto], '09:15')->assertCreated();
});
