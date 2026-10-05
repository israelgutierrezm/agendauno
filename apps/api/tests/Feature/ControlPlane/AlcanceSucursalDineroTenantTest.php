<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en el DINERO: quien está acotado a una sede solo ve,
| totaliza y exporta los movimientos de las suyas, y no corrige, anula ni devuelve
| cobros de otra. Mismo negocio, dos sucursales (no es aislamiento entre negocios).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Vende y cobra en caja un paquete al cliente (la venta queda en su sucursal).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cobrarEnCajaAlcanceDinero(array $e, string $persona, string $producto): void
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
}

it('quien está acotado a una sede solo ve, totaliza y exporta el dinero de la suya', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    $producto = crearPackTenant($e, 8000);
    cobrarEnCajaAlcanceDinero($e, crearMiembroEnSucursal($e, 'AnaA', $sedeA), $producto);
    cobrarEnCajaAlcanceDinero($e, crearMiembroEnSucursal($e, 'BetoB', $sedeB), $producto);

    $rol = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Caja', 'permisos' => ['facturacion.ver', 'ordenes.ver', 'ordenes.gestionar', 'pagos.reembolsar', 'miembros.ver', 'productos.ver', 'derechos.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $caja = personalConSesion($e['slug'], $e['bearer'], 'caja@correo.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'caja@correo.mx'), $sedeA);

    // El dueño ve las dos ventas; la caja de la sede A, solo la suya (lista y totales).
    $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos", conBearer($e['bearer']))->assertOk()->assertJsonCount(2, 'data');
    $suyos = $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos", conBearer($caja))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.persona', 'AnaA');
    expect($suyos->json('totales_por_moneda.0.cobrado_minor'))->toBe($suyos->json('data.0.monto_minor'));

    $csv = (string) $this->get("/api/v1/app/{$e['slug']}/pagos/movimientos?formato=csv", conBearer($caja))->assertOk()->getContent();
    expect($csv)->toContain('AnaA')->and($csv)->not->toContain('BetoB');

    // La lista de cobros (para devolver o corregir) tampoco muestra los de la otra sede.
    $this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($caja))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.persona', 'AnaA');
});

it('quien está acotado a una sede no corrige, anula ni devuelve un cobro de otra', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    cobrarEnCajaAlcanceDinero($e, crearMiembroEnSucursal($e, 'BetoB', $sedeB), crearPackTenant($e, 8000));
    $pagoB = (string) $this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data.0.id');

    $rol = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Caja', 'permisos' => ['facturacion.ver', 'ordenes.ver', 'ordenes.gestionar', 'pagos.reembolsar', 'miembros.ver', 'productos.ver', 'derechos.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $caja = personalConSesion($e['slug'], $e['bearer'], 'caja@correo.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'caja@correo.mx'), $sedeA);

    $this->putJson("/api/v1/app/{$e['slug']}/pagos/{$pagoB}/metodo", ['metodo' => 'transferencia'], conBearer($caja))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pagoB}/anular", ['motivo' => 'Error'], conBearer($caja))->assertStatus(403);
    $this->getJson("/api/v1/app/{$e['slug']}/pagos/{$pagoB}/reembolsos", conBearer($caja))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pagoB}/reembolsos", ['motivo' => 'Error'], conBearer($caja))->assertStatus(403);

    // El dueño (no acotado) sí puede devolverlo.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pagoB}/reembolsos", ['motivo' => 'Error'], conBearer($e['bearer']))->assertCreated();
});
