<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Clima del Inicio del equipo: el instructor ve el pronóstico de su próxima clase o
| cita en su sede; el dueño, el de ahora en la sede del negocio (antes que su IP).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Cache::flush();
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array{e: array{slug: string, bearer: string}, semilla: array{oferta: string, sucursal: string}}
 */
function negocioConSedeUbicada(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}", [
        'latitud' => 19.4194, 'longitud' => -99.1617,
    ], conBearer($e['bearer']))->assertOk();

    return ['e' => $e, 'semilla' => $semilla];
}

it('el instructor ve el pronóstico de su próxima clase en su sede', function (): void {
    $this->travelTo('2026-09-28 12:00:00');
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'hourly' => [
            'time' => ['2026-10-01T18:00'],
            'temperature_2m' => [21.4],
            'weather_code' => [2],
            'precipitation_probability' => [10],
            'is_day' => [1],
        ],
    ])]);
    ['e' => $e, 'semilla' => $semilla] = negocioConSedeUbicada();
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 18:00:00', 'duracion_minutos' => 60,
        'instructor_id' => usuarioIdPorEmail($e, 'coach@correo.mx'),
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($coach))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'pronostico')
        ->assertJsonPath('data.sesion_tipo', 'clase')
        ->assertJsonPath('data.lugar', 'Roma Norte')
        ->assertJsonPath('data.temperatura', 21);
});

it('el dueño ve el clima de ahora en la sede del negocio, no el de su red', function (): void {
    Http::fake([
        'ip-api.com/*' => Http::response(['status' => 'success', 'city' => 'Monterrey', 'lat' => 25.6, 'lon' => -100.3]),
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 18.2, 'weather_code' => 3, 'is_day' => 1]]),
    ]);
    ['e' => $e] = negocioConSedeUbicada();

    $this->withServerVariables(['REMOTE_ADDR' => '189.203.10.20'])
        ->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'ahora')
        ->assertJsonPath('data.lugar', 'Roma Norte')
        ->assertJsonPath('data.aproximado', false)
        ->assertJsonPath('data.icono', 'nublado');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ip-api.com'));
});

it('sin sedes ubicadas, el de su IP; y si nada responde, null', function (): void {
    Http::fake([
        'ip-api.com/*' => Http::response(['status' => 'success', 'city' => 'Puebla', 'lat' => 19.04, 'lon' => -98.2]),
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 17.0, 'weather_code' => 0, 'is_day' => 1]]),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->withServerVariables(['REMOTE_ADDR' => '189.203.10.20'])
        ->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.lugar', 'Puebla')
        ->assertJsonPath('data.aproximado', true);

    // Red privada (sin IP útil) y sin sedes ubicadas: no hay clima, pero responde.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($e['bearer']))
        ->assertOk()->assertExactJson(['data' => null]);
});
