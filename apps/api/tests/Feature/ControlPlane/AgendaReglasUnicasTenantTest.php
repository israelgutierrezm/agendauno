<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RecursoTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| 1.3 de la fase 1: las mismas reglas de agenda en todas las formas de crear o cambiar
| una sesión (recepción, sustitutos y clases recurrentes): un profesional no atiende
| dos cosas a la vez, una sala no pasa de su cupo, es de la misma sede y está en
| servicio; y una clase recurrente explica qué fechas no pudo generar.
| El 7 de enero de 2030 es lunes.
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
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $sede
 */
function salaEn(array $e, array $sede, string $nombre = 'Salón A'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $sede['sucursal'], 'nombre' => $nombre, 'modo' => 'unidad',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function sesionSuelta(array $e, array $datos): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/sesiones", ['duracion_minutos' => 60, ...$datos], conBearer($e['bearer']));
}

/**
 * Crea una clase recurrente y la genera en el rango; devuelve lo que respondió.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 * @return array{creadas: int, omitidas: list<array{fecha: string, motivo: string}>}
 */
function serieGenerada(array $e, array $datos, string $desde, string $hasta): array
{
    $plantilla = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'duracion_minutos' => 60, 'vigente_desde' => $desde, 'vigente_hasta' => $hasta, ...$datos,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", ['desde' => $desde, 'hasta' => $hasta], conBearer($e['bearer']))
        ->assertCreated()->json('data');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function profesor(array $e, string $email): string
{
    personalConSesion($e['slug'], $e['bearer'], $email, 'instructor');

    return (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data'))
        ->last()['id'];
}

it('una sala de otra sede o fuera de servicio no se asigna, con un mensaje claro', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sala = salaEn($e, $sedeA);
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Otra'], conBearer($e['bearer']))->json('data.id');
    $sedeB = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Polanco', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    sesionSuelta($e, ['oferta_id' => $sedeA['oferta'], 'sucursal_id' => $sedeB, 'recurso_id' => $sala, 'inicia_en_local' => '2030-01-07 08:00:00'])
        ->assertUnprocessable()->assertJsonPath('meta.errors.recurso_id.0', 'Salón A es de otra sede.');

    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => RecursoTenant::query()->where('ulid', $sala)->update(['activo' => false]),
    );
    sesionSuelta($e, ['oferta_id' => $sedeA['oferta'], 'sucursal_id' => $sedeA['sucursal'], 'recurso_id' => $sala, 'inicia_en_local' => '2030-01-07 08:00:00'])
        ->assertUnprocessable()->assertJsonPath('meta.errors.recurso_id.0', 'Salón A está fuera de servicio.');
});

it('una clase recurrente no ocupa una sala tomada por una sesión suelta y dice qué fecha omitió', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $sala = salaEn($e, $sede);
    sesionSuelta($e, ['oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'recurso_id' => $sala, 'inicia_en_local' => '2030-01-07 08:00:00'])
        ->assertCreated();

    $r = serieGenerada($e, [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'recurso_id' => $sala,
        'dias_semana' => [1], 'hora_local' => '08:00',
    ], '2030-01-07', '2030-01-21');

    expect($r['creadas'])->toBe(2)
        ->and($r['omitidas'])->toBe([['fecha' => '2030-01-07', 'motivo' => 'Salón A no está disponible en ese horario.']]);
});

it('una clase recurrente no encima al instructor con otra clase suya', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $beto = profesor($e, 'beto@correo.mx');
    sesionSuelta($e, ['oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $beto, 'inicia_en_local' => '2030-01-08 09:30:00'])
        ->assertCreated();

    $r = serieGenerada($e, [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $beto,
        'dias_semana' => [2], 'hora_local' => '09:00',
    ], '2030-01-08', '2030-01-15');

    expect($r['creadas'])->toBe(1)
        ->and($r['omitidas'][0]['fecha'])->toBe('2030-01-08')
        ->and($r['omitidas'][0]['motivo'])->toBe('Esa persona ya atiende Nivel 1 a las 09:30.');
});

it('nadie del equipo puede estar en dos clases a la vez, sea sustituto o asistente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $beto = profesor($e, 'beto@correo.mx');
    $caro = profesor($e, 'caro@correo.mx');
    sesionSuelta($e, ['oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $beto, 'inicia_en_local' => '2030-01-09 10:00:00'])
        ->assertCreated();
    $deCaro = (string) sesionSuelta($e, ['oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $caro, 'inicia_en_local' => '2030-01-09 10:30:00'])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$deCaro}/staff", ['usuario_id' => $beto, 'rol' => 'sustituto'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.instructor_id.0', 'Esa persona ya atiende Nivel 1 a las 10:00.');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$deCaro}/staff", ['usuario_id' => $beto, 'rol' => 'asistente'], conBearer($e['bearer']))
        ->assertUnprocessable();
    // A otra hora, sí.
    $tarde = (string) sesionSuelta($e, ['oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $caro, 'inicia_en_local' => '2030-01-09 18:00:00'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$tarde}/staff", ['usuario_id' => $beto, 'rol' => 'sustituto'], conBearer($e['bearer']))
        ->assertCreated();
});
