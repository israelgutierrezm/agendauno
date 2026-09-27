<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| El calendario del alumno pide las clases del PERIODO que ve (y de la sucursal que
| elija): con más de 100 clases repartidas en fechas y sedes, todas las del periodo
| elegido son accesibles, no solo las primeras 100 del negocio.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * 120 clases: del 1 al 20 de octubre, tres por día en cada una de dos sedes.
 *
 * @return array{e: array{slug: string, bearer: string}, alumna: array{slug: string, bearer: string}, condesa: string}
 */
function negocioConMuchasClases(): array
{
    test()->travelTo('2026-09-30 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $org = (string) test()->getJson("/api/v1/app/{$e['slug']}/organizaciones", conBearer($e['bearer']))->json('data.0.id');
    $condesa = (string) test()->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Condesa', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    foreach ([$semilla['sucursal'], $condesa] as $sede) {
        for ($dia = 1; $dia <= 20; $dia++) {
            foreach (['07:00', '12:00', '20:00'] as $hora) {
                test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
                    'oferta_id' => $semilla['oferta'], 'sucursal_id' => $sede,
                    'inicia_en_local' => sprintf('2026-10-%02d %s:00', $dia, $hora), 'duracion_minutos' => 60,
                ], conBearer($e['bearer']))->assertCreated();
            }
        }
        // 60 altas por sede: un minuto entre sedes para no toparse con el límite
        // de 120 peticiones por minuto de un usuario.
        test()->travel(61)->seconds();
    }

    return ['e' => $e, 'alumna' => alumnoConSesion($e, 'Vale', 'vale@correo.mx'), 'condesa' => $condesa];
}

it('con más de 100 clases, todas las del periodo elegido son accesibles', function (): void {
    ['e' => $e, 'alumna' => $alumna, 'condesa' => $condesa] = negocioConMuchasClases();
    $url = "/api/v1/app/{$e['slug']}/mi/agenda";

    // Los últimos días del periodo (más allá de las primeras 100 clases del negocio).
    $r = $this->getJson("{$url}?desde=2026-10-18&hasta=2026-10-20", conBearer($alumna['bearer']))->assertOk();
    expect($r->json('data'))->toHaveCount(18) // 3 días × 3 horarios × 2 sedes
        ->and($r->json('meta.truncado'))->toBeFalse()
        ->and(collect($r->json('meta.sucursales'))->pluck('nombre')->all())->toBe(['Condesa', 'Roma Norte']);

    // La de las 20:00 del 20 (ya es 21 en UTC) sigue siendo del 20 en su sede.
    expect(collect($r->json('data'))->pluck('inicia_en')->last())->toBe('2026-10-21T02:00:00+00:00');

    // Con sucursal: el filtro va antes del tope.
    $this->getJson("{$url}?desde=2026-10-18&hasta=2026-10-20&sucursal_id={$condesa}", conBearer($alumna['bearer']))
        ->assertOk()->assertJsonCount(9, 'data')
        ->assertJsonPath('data.0.sucursal', 'Condesa');

    // Sin fechas: desde hoy y 30 días; las 120 caben.
    $this->getJson($url, conBearer($alumna['bearer']))->assertOk()->assertJsonCount(120, 'data');
});

it('el periodo es de fechas válidas y de hasta 62 días', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/mi/agenda";

    $this->getJson("{$url}?desde=2026-10-01&hasta=2027-01-15", conBearer($alumna['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
    $this->getJson("{$url}?desde=2026-10-10&hasta=2026-10-01", conBearer($alumna['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
    $this->getJson("{$url}?desde=01/10/2026", conBearer($alumna['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['desde'], 'meta.errors');
});
