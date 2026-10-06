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
 * @param  array{slug: string, bearer: string}  $e
 */
function crearOrganizacionTenant(array $e): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Marca'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $extra
 */
function crearSucursalTenant(array $e, string $orgUlid, string $nombre, array $extra = []): string
{
    return (string) test()->postJson(
        "/api/v1/app/{$e['slug']}/organizaciones/{$orgUlid}/sucursales",
        array_merge(['nombre' => $nombre], $extra),
        conBearer($e['bearer']),
    )->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function crearMiembroEnSucursalTenant(array $e, string $nombre, ?string $sucursalUlid): void
{
    $carga = ['nombre' => $nombre];
    if ($sucursalUlid !== null) {
        $carga['sucursal_id'] = $sucursalUlid;
    }

    test()->postJson("/api/v1/app/{$e['slug']}/miembros", $carga, conBearer($e['bearer']))->assertCreated();
}

it('crea y edita una sucursal como unidad de negocio (moneda/impuesto/region)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $org = crearOrganizacionTenant($e);

    $creada = test()->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Roma Norte', 'moneda' => 'mxn', 'impuesto_tasa_bps' => 1600, 'region' => 'Centro',
    ], conBearer($e['bearer']))->assertCreated();

    $creada->assertJsonPath('data.moneda', 'MXN') // normaliza a mayusculas
        ->assertJsonPath('data.impuesto_tasa_bps', 1600)
        ->assertJsonPath('data.region', 'Centro');

    $id = (string) $creada->json('data.id');

    // Edicion parcial: cambia el impuesto y conserva la region; la moneda es la del
    // negocio (una sola, ADR 0099): otra no se acepta.
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$id}", [
        'impuesto_tasa_bps' => 0,
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.impuesto_tasa_bps', 0)
        ->assertJsonPath('data.moneda', 'MXN')
        ->assertJsonPath('data.region', 'Centro');
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$id}", ['moneda' => 'usd'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['moneda'], 'meta.errors');
});

it('asigna sucursal de casa al miembro y permite filtrar por sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $org = crearOrganizacionTenant($e);
    $a = crearSucursalTenant($e, $org, 'Sucursal A');
    $b = crearSucursalTenant($e, $org, 'Sucursal B');

    test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'sucursal_id' => $a], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.sucursal.id', $a)
        ->assertJsonPath('data.sucursal.nombre', 'Sucursal A');

    crearMiembroEnSucursalTenant($e, 'Beto', $b);
    test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Caro'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.sucursal', null);

    // Filtro por sucursal A: solo Ana.
    test()->getJson("/api/v1/app/{$e['slug']}/miembros?sucursal_id={$a}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Ana');
});

it('el reporte consolidado agrega miembros por sucursal, totales y sin sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $org = crearOrganizacionTenant($e);
    $a = crearSucursalTenant($e, $org, 'Sucursal A');
    $b = crearSucursalTenant($e, $org, 'Sucursal B');

    crearMiembroEnSucursalTenant($e, 'A1', $a);
    crearMiembroEnSucursalTenant($e, 'A2', $a);
    crearMiembroEnSucursalTenant($e, 'B1', $b);
    crearMiembroEnSucursalTenant($e, 'Sin casa', null);

    $r = test()->getJson("/api/v1/app/{$e['slug']}/reportes/sucursales", conBearer($e['bearer']))->assertOk();

    // Filas ordenadas por nombre: A, luego B.
    $r->assertJsonPath('data.sucursales.0.nombre', 'Sucursal A')
        ->assertJsonPath('data.sucursales.0.miembros_activos', 2)
        ->assertJsonPath('data.sucursales.0.sesiones_proximas', 0)
        ->assertJsonPath('data.sucursales.1.nombre', 'Sucursal B')
        ->assertJsonPath('data.sucursales.1.miembros_activos', 1)
        ->assertJsonPath('data.sin_sucursal.miembros_activos', 1)
        ->assertJsonPath('data.totales.sucursales', 2)
        ->assertJsonPath('data.totales.miembros_activos', 4);
});

it('el reporte consolidado exige permiso de facturacion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->getJson("/api/v1/app/{$e['slug']}/reportes/sucursales", conBearer($coach))->assertForbidden();
});
