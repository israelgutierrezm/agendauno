<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La agenda de una sucursal muestra solo a los profesionales que atienden ahí: cada
| profesional dice sus sucursales (puede tener varias). Quien no tiene ninguna
| aparece en todas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cada profesional dice en qué sucursales atiende: una, varias o ninguna', function (): void {
    $e = estudioConSesion('barberia-columnas', 'dueno@barberia-columnas.mx', 'barberia');
    $roma = agendaSemilla($e)['sucursal'];
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Otra'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $valle = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Del Valle', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    foreach (['tono', 'ivan', 'sal', 'luz'] as $quien) {
        personalConSesion($e['slug'], $e['bearer'], "{$quien}@barberia-columnas.mx", 'instructor');
    }
    $tono = usuarioIdPorEmail($e, 'tono@barberia-columnas.mx');
    $ivan = usuarioIdPorEmail($e, 'ivan@barberia-columnas.mx');
    $sal = usuarioIdPorEmail($e, 'sal@barberia-columnas.mx');
    $luz = usuarioIdPorEmail($e, 'luz@barberia-columnas.mx');
    asignarSucursal($e, $tono, $roma, 'instructor');
    // Iván atiende en las dos sucursales.
    asignarSucursal($e, $ivan, $roma, 'instructor');
    asignarSucursal($e, $ivan, $valle, 'instructor');
    // Luz no tiene sucursal asignada, pero sí horario de atención en Del Valle.
    abrirHorarioDeCitas($e, $luz, $valle);

    $lista = collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data'))->keyBy('id');

    expect($lista[$tono]['sucursales'])->toBe([$roma])
        ->and($lista[$ivan]['sucursales'])->toEqualCanonicalizing([$roma, $valle])
        // Sin sucursal propia: la agenda lo muestra en todas.
        ->and($lista[$luz]['sucursales'])->toBe([$valle])
        ->and($lista[$sal]['sucursales'])->toBe([]);
});
