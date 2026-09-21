<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en POS/INVENTARIO: el staff ACOTADO a sedes solo vende,
| mueve stock y ve existencias/ventas de SUS sucursales; sin asignación, todas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un artículo con stock inicial en varias sucursales (como propietario). R19.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  list<string>  $sucursalesUlid
 */
function articuloConStock(array $e, array $sucursalesUlid, int $cantidad = 10): string
{
    $articulo = (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => 'Botella', 'precio_minor' => 5000, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    foreach ($sucursalesUlid as $sucursalUlid) {
        test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
            'sucursal_id' => $sucursalUlid, 'tipo' => 'entrada', 'cantidad' => $cantidad,
        ], conBearer($e['bearer']))->assertCreated();
    }

    return $articulo;
}

it('el POS acota vender y el listado de ventas a las sucursales del staff', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $articulo = articuloConStock($e, [$sedeA['sucursal'], $sedeB['sucursal']]);

    // Una venta en cada sede (como propietario).
    foreach ([$sedeA, $sedeB] as $sede) {
        $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
            'sucursal_id' => $sede['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
        ], conBearer($e['bearer']))->assertCreated();
    }

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Vender en B (ajena) → 403; en A (suya) → OK.
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $sedeB['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($recep))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $sedeA['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($recep))->assertCreated();

    // El propietario ve las 3 ventas; el recep solo las 2 de su sede.
    expect($this->getJson("/api/v1/app/{$e['slug']}/pos/ventas", conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(3);
    expect($this->getJson("/api/v1/app/{$e['slug']}/pos/ventas", conBearer($recep))->assertOk()->json('data'))->toHaveCount(2);
});

it('el inventario acota el movimiento y las existencias visibles a las sucursales del staff', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $articulo = articuloConStock($e, [$sedeA['sucursal'], $sedeB['sucursal']]);

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Mover stock en B (ajena) → 403.
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sedeB['sucursal'], 'tipo' => 'entrada', 'cantidad' => 5,
    ], conBearer($recep))->assertStatus(403);

    // El inventario del recep solo muestra existencias de A (stock_total 10, no 20).
    $data = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($recep))->assertOk()->json('data');
    $art = collect($data)->firstWhere('id', $articulo);
    expect($art['existencias'])->toHaveCount(1);
    expect($art['stock_total'])->toBe(10);
    expect($art['existencias'][0]['sucursal_id'])->toBe($sedeA['sucursal']);
});
