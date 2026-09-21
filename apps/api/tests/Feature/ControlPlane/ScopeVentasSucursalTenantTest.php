<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en VENTAS/ÓRDENES: la orden se atribuye a una sucursal
| (la del comprador o, si no tiene, la del vendedor acotado); el staff ACOTADO solo ve
| y crea órdenes de SUS sedes. Sin asignación ve todo (compatibilidad).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('las órdenes se atribuyen a la sucursal y el listado se acota al staff', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $anaA = crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    $betoB = crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);
    $producto = crearPackTenant($e);

    // El propietario crea una orden para cada alumno (se atribuyen a su sede de casa).
    foreach ([$anaA, $betoB] as $comprador) {
        $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
            'comprador_id' => $comprador, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
        ], conBearer($e['bearer']))->assertCreated();
    }

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // El propietario ve las 2 órdenes; el recep solo la de su sede.
    expect($this->getJson("/api/v1/app/{$e['slug']}/ordenes", conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(2);
    expect($this->getJson("/api/v1/app/{$e['slug']}/ordenes", conBearer($recep))->assertOk()->json('data'))->toHaveCount(1);
});

it('un recepcionista acotado no crea una orden para un alumno de otra sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $anaA = crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    $betoB = crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);
    $producto = crearPackTenant($e);

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Alumno de otra sede → 403; alumno de su sede → OK.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $betoB, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($recep))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $anaA, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($recep))->assertCreated();
});
