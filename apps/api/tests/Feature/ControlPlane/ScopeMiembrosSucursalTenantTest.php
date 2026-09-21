<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en MIEMBROS: el staff ACOTADO a sedes (recepcionista/
| instructor con asignación explícita, no propietario/admin) solo ve/gestiona a los
| alumnos de SUS sucursales. Sin asignación = ve todo (compatibilidad con estudios de
| una sola sucursal). Aquí se prueba la DENEGACIÓN, no solo la ampliación.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un recepcionista asignado a una sucursal solo ve a los alumnos de esa sede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $recepId = usuarioIdPorEmail($e, 'recep@correo.mx');

    // Sin asignación: ve a TODOS (compatibilidad con una sola sucursal).
    $todos = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($recep))
        ->assertOk()->json('data'))->pluck('nombre');
    expect($todos)->toContain('AnaA', 'BetoB');

    // Se le asigna la sucursal A → queda ACOTADO.
    asignarSucursal($e, $recepId, $sedeA['sucursal']);

    $soloA = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($recep))
        ->assertOk()->json('data'))->pluck('nombre');
    expect($soloA)->toContain('AnaA');
    expect($soloA)->not->toContain('BetoB');
});

it('el propietario ve a los alumnos de todas las sucursales (nunca acotado)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);

    $nombres = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->json('data'))->pluck('nombre');
    expect($nombres)->toContain('AnaA', 'BetoB');
});

it('un recepcionista acotado no da de alta en otra sucursal (y sin indicarla usa la suya)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Alta en la sucursal B (ajena) → 403.
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Intruso', 'tipo' => 'miembro', 'sucursal_id' => $sedeB['sucursal'],
    ], conBearer($recep))->assertStatus(403);

    // Alta SIN sucursal → se asigna la suya (A) automáticamente.
    $creado = $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'MiAlumno', 'tipo' => 'miembro',
    ], conBearer($recep))->assertCreated()->json('data');
    expect($creado['sucursal']['id'])->toBe($sedeA['sucursal']);
});

it('un recepcionista acotado no edita a un alumno de otra sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $betoB = crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$betoB}", [
        'nombre' => 'Hackeado',
    ], conBearer($recep))->assertStatus(403);
});

it('un recepcionista acotado no abre la ficha ni el resumen de un alumno de otra sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $anaA = crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    $betoB = crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Alumno de OTRA sede: 403 en la vista de detalle (ficha y resumen).
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$betoB}/ficha", conBearer($recep))->assertStatus(403);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$betoB}/resumen", conBearer($recep))->assertStatus(403);

    // Alumno de SU sede: OK.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$anaA}/ficha", conBearer($recep))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$anaA}/resumen", conBearer($recep))->assertOk();
});
