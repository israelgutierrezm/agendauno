<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Wellhub y TotalPass por sede (ADR 0202): cada sede de un negocio es otro gimnasio en
| la plataforma, con su propia conexión y sus llaves. La visita se valida con las de la
| sede de su clase.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cada sede tiene su conexión con Wellhub y la visita se valida con la de la sede de su clase', function (): void {
    $e = estudioConSesion('fluo-sedes', 'f@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $semilla = agendaSemilla($e);
    $org = (string) $this->getJson("{$app}/organizaciones", conBearer($e['bearer']))->json('data.0.id');
    $delValle = (string) $this->postJson("{$app}/organizaciones/{$org}/sucursales", ['nombre' => 'Del Valle', 'zona_horaria' => 'America/Mexico_City'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    Http::fake(['*/access/v1/validate' => Http::response(['metadata' => ['total' => 1]], 200)]);

    $this->putJson("{$app}/integraciones/wellhub?sucursal={$semilla['sucursal']}", ['activa' => true, 'credenciales' => ['api_key' => 'wh_roma', 'gym_id' => '111']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sede.nombre', 'Roma Norte');
    $this->putJson("{$app}/integraciones/wellhub?sucursal={$delValle}", ['activa' => true, 'credenciales' => ['api_key' => 'wh_valle', 'gym_id' => '222']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sede.nombre', 'Del Valle');
    $wellhub = collect($this->getJson("{$app}/integraciones", conBearer($e['bearer']))->assertOk()->json('data'))->firstWhere('proveedor', 'wellhub');
    expect(collect($wellhub['sedes'])->pluck('sucursal.nombre')->all())->toBe(['Roma Norte', 'Del Valle']);
    $this->getJson("{$app}/integraciones/wellhub?sucursal={$delValle}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.llaves_configuradas', ['api_key', 'gym_id']);

    $enValle = crearSesionTenant($e, ['oferta' => $semilla['oferta'], 'sucursal' => $delValle], 10, '2026-10-01 06:30:00');
    $enRoma = crearSesionTenant($e, $semilla, 10, '2026-10-01 06:30:00');
    $this->postJson("{$app}/checkins", ['proveedor' => 'wellhub', 'sesion_id' => $enValle, 'codigo' => '1000000000001'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("{$app}/checkins", ['proveedor' => 'wellhub', 'sesion_id' => $enRoma, 'codigo' => '1000000000002'], conBearer($e['bearer']))->assertCreated();
    Http::assertSent(fn (Request $r): bool => $r['gympass_id'] === '1000000000001' && $r->hasHeader('X-Gym-Id', '222') && $r->hasHeader('Authorization', 'Bearer wh_valle'));
    Http::assertSent(fn (Request $r): bool => $r['gympass_id'] === '1000000000002' && $r->hasHeader('X-Gym-Id', '111') && $r->hasHeader('Authorization', 'Bearer wh_roma'));

    // Se quita la de una sede; la otra sigue.
    $this->deleteJson("{$app}/integraciones/wellhub?sucursal={$delValle}", [], conBearer($e['bearer']))->assertNoContent();
    expect(collect($this->getJson("{$app}/integraciones", conBearer($e['bearer']))->json('data'))->firstWhere('proveedor', 'wellhub')['sedes'])->toHaveCount(1);
});
