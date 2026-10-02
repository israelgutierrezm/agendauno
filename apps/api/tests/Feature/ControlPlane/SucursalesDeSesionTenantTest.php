<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La sesión dice con qué sucursales puede trabajar cada quien: el dueño, con todas;
| quien está asignado a una sede, solo con la suya. Con más de una, el panel ofrece
| elegir la sucursal; con una, la usa sin preguntar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el dueño opera todas las sucursales; quien está asignado, solo la suya', function (): void {
    $e = estudioConSesion('barberia-sedes', 'dueno@barberia-sedes.mx');
    $roma = agendaSemilla($e)['sucursal'];
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Otra'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $valle = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Del Valle', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $delDueno = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()->json('data.usuario.sucursales');
    expect(array_column($delDueno, 'nombre'))->toBe(['Del Valle', 'Roma Norte']);

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'lupita@barberia-sedes.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'lupita@barberia-sedes.mx'), $valle);

    $deLupita = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recepcion))->assertOk()->json('data.usuario.sucursales');
    expect($deLupita)->toBe([['id' => $valle, 'nombre' => 'Del Valle', 'zona_horaria' => 'America/Mexico_City']])
        ->and($roma)->not->toBe($valle);
});
