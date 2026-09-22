<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Invitar personal ACOTADO a una sede desde el alta (R19): al invitar un usuario se
| puede indicar una sucursal; entonces queda con una asignación a esa sede (y su rol),
| lo que lo acota a ella. Sin sucursal, no hay asignación (ve todas). Reusa el pipeline
| de invitación (usuario inactivo + token de activación) y las asignaciones de personal.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('invitar con sede crea la asignación que acota al usuario a esa sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    agendaSemilla($e); // segunda sede (para que el alcance importe)

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Rec A', 'email' => 'reca@correo.mx', 'rol' => 'recepcionista',
        'sucursal_id' => $sedeA['sucursal'],
    ], conBearer($e['bearer']))->assertCreated();

    $asigns = collect($this->getJson("/api/v1/app/{$e['slug']}/asignaciones-personal", conBearer($e['bearer']))
        ->assertOk()->json('data'))->where('usuario', 'Rec A')->values();

    expect($asigns)->toHaveCount(1);
    expect($asigns->first())->toMatchArray([
        'sucursal_id' => $sedeA['sucursal'], 'rol' => 'recepcionista',
    ]);
});

it('invitar sin sede no crea asignación (el usuario ve todas las sucursales)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Admin X', 'email' => 'adminx@correo.mx', 'rol' => 'admin',
    ], conBearer($e['bearer']))->assertCreated();

    expect($this->getJson("/api/v1/app/{$e['slug']}/asignaciones-personal", conBearer($e['bearer']))
        ->assertOk()->json('data'))->toHaveCount(0);
});

it('invitar con una sede inexistente responde 404 y no crea al usuario', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Rec Z', 'email' => 'recz@correo.mx', 'rol' => 'recepcionista',
        'sucursal_id' => 'sucursal-que-no-existe',
    ], conBearer($e['bearer']))->assertNotFound();

    // El usuario NO se creó (la sede se resuelve antes del alta).
    $usuarios = collect($this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($usuarios->pluck('email'))->not->toContain('recz@correo.mx');
});
