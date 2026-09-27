<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Clima del Inicio del alumno: el pronóstico para su próxima clase en la ubicación
| de su sucursal; sin clase, el de ahora por su IP. Si el servicio falla, no hay
| clima pero el Inicio sigue en pie.
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
 * Estudio con una alumna con sesión y la agenda base; la sucursal, ubicada.
 *
 * @return array{e: array{slug: string, bearer: string}, alumna: array{slug: string, bearer: string}, persona: string, semilla: array{oferta: string, sucursal: string}}
 */
function alumnaConSucursalUbicada(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}", [
        'latitud' => 19.4194, 'longitud' => -99.1617,
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.latitud', 19.4194)
        ->assertJsonPath('data.longitud', -99.1617);

    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');

    return ['e' => $e, 'alumna' => $alumna, 'persona' => $persona, 'semilla' => $semilla];
}

it('con clase próxima, el pronóstico para esa hora en su sucursal', function (): void {
    $this->travelTo('2026-09-28 12:00:00');
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'hourly' => [
            'time' => ['2026-10-01T07:00', '2026-10-01T08:00'],
            'temperature_2m' => [13.2, 15.6],
            'weather_code' => [3, 61],
            'precipitation_probability' => [20, 70],
            'is_day' => [1, 1],
        ],
    ])]);
    $d = alumnaConSucursalUbicada();
    $e = $d['e'];
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $d['persona'], 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, $d['semilla'], 10, '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $d['persona']], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/clima", conBearer($d['alumna']['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'pronostico')
        ->assertJsonPath('data.sesion_tipo', 'clase')
        ->assertJsonPath('data.lugar', 'Roma Norte')
        ->assertJsonPath('data.temperatura', 16)
        ->assertJsonPath('data.condicion', 'Lluvia')
        ->assertJsonPath('data.icono', 'lluvia')
        ->assertJsonPath('data.lluvia', 70);

    // La hora pedida es la de la clase, en la zona de la sede.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'start_date=2026-10-01') && str_contains($r->url(), 'timezone=America'));
});

it('sin clase, el clima de ahora por su IP (aproximado)', function (): void {
    Http::fake([
        'ip-api.com/*' => Http::response(['status' => 'success', 'city' => 'Guadalajara', 'lat' => 20.67, 'lon' => -103.35]),
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 24.4, 'weather_code' => 0, 'is_day' => 1]]),
    ]);
    $d = alumnaConSucursalUbicada();

    $this->withServerVariables(['REMOTE_ADDR' => '189.203.10.20'])
        ->getJson("/api/v1/app/{$d['e']['slug']}/mi/clima", conBearer($d['alumna']['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'ahora')
        ->assertJsonPath('data.lugar', 'Guadalajara')
        ->assertJsonPath('data.aproximado', true)
        ->assertJsonPath('data.temperatura', 24)
        ->assertJsonPath('data.icono', 'despejado');
});

it('con una IP privada (desarrollo) usa la ubicación de la sucursal', function (): void {
    Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 18.0, 'weather_code' => 2, 'is_day' => 0]])]);
    $d = alumnaConSucursalUbicada();

    $this->getJson("/api/v1/app/{$d['e']['slug']}/mi/clima", conBearer($d['alumna']['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'ahora')
        ->assertJsonPath('data.lugar', 'Roma Norte')
        ->assertJsonPath('data.aproximado', false)
        ->assertJsonPath('data.es_de_dia', false);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ip-api.com'));
});

it('si el servicio del clima falla, no hay clima pero todo sigue en pie', function (): void {
    Http::fake(['*' => Http::response('', 504)]);
    $d = alumnaConSucursalUbicada();

    $this->getJson("/api/v1/app/{$d['e']['slug']}/mi/clima", conBearer($d['alumna']['bearer']))
        ->assertOk()->assertExactJson(['data' => null]);
});

it('la ubicación de la sucursal va completa y en rango', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $url = "/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}";

    $this->putJson($url, ['latitud' => 19.4], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['longitud'], 'meta.errors');
    $this->putJson($url, ['latitud' => 120, 'longitud' => -99.1], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['latitud'], 'meta.errors');
    // Quitarla: las dos en null.
    $this->putJson($url, ['latitud' => 19.4, 'longitud' => -99.1], conBearer($e['bearer']))->assertOk();
    $this->putJson($url, ['latitud' => null, 'longitud' => null], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.latitud', null);
});
