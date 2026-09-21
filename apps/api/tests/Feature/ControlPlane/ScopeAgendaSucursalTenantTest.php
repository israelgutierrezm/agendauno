<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en AGENDA: el staff ACOTADO a sedes (recepcionista/
| instructor con asignación explícita, no propietario/admin) solo VE las clases de SUS
| sucursales; sin asignación ve todas (compatibilidad con una sola sucursal). Crear
| clases exige agenda.gestionar (propietario/admin, nunca acotados), por eso aquí se
| prueba el ALCANCE DE LECTURA, que es donde el recepcionista/instructor sí llega.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un recepcionista asignado a una sucursal solo ve las clases de esa sede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearSesionTenant($e, $sedeA, 5);
    crearSesionTenant($e, $sedeB, 9);

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    // Sin asignación: ve las clases de AMBAS sedes.
    $todas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($recep))
        ->assertOk()->json('data');
    expect($todas)->toHaveCount(2);

    // Se le asigna la sucursal A → queda ACOTADO y solo ve la clase de A (capacidad 5).
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    $soloA = $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($recep))
        ->assertOk()->json('data');
    expect($soloA)->toHaveCount(1);
    expect($soloA[0]['capacidad'])->toBe(5);
});

it('el propietario ve las clases de todas las sucursales (nunca acotado)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearSesionTenant($e, $sedeA, 5);
    crearSesionTenant($e, $sedeB, 9);

    $todas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($todas)->toHaveCount(2);
});

it('el smart-fill de oportunidades también respeta el alcance por sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    // Clases futuras (dentro de la ventana de 60 días) con cupo libre en ambas sedes.
    crearSesionTenant($e, $sedeA, 5, '2026-10-01 08:00:00');
    crearSesionTenant($e, $sedeB, 9, '2026-10-01 09:00:00');

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    $oportunidades = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/oportunidades?dias=30", conBearer($recep))
        ->assertOk()->json('data');
    expect($oportunidades)->toHaveCount(1);
    expect($oportunidades[0]['capacidad'])->toBe(5);
});
