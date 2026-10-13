<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Inventario y mostrador sin datos a medias ni duplicados: un producto con un extra
| inválido no se guarda a medias; la respuesta de un movimiento respeta las sedes de
| quien lo hace; el mostrador devuelve la misma venta si se reintenta, no cobra un
| precio que cambió con el carrito abierto ni vende un producto desactivado.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un producto con stock en la sede (como propietario).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function productoConStockSeguro(array $e, string $sucursal, int $stock = 10, int $precio = 5000): string
{
    $articulo = (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => 'Botella', 'precio_minor' => $precio, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sucursal, 'tipo' => 'entrada', 'cantidad' => $stock,
    ], conBearer($e['bearer']))->assertCreated();

    return $articulo;
}

it('un producto con un extra inválido no se guarda a medias', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/articulos";

    $this->postJson($url, [
        'nombre' => 'Proteína', 'precio_minor' => 6000,
        'extras' => [['nombre' => 'Fresas', 'precio_minor' => 1500], ['nombre' => '', 'precio_minor' => 1000]],
    ], conBearer($e['bearer']))->assertUnprocessable();
    // Nada quedó creado (volver a guardar no duplica).
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');

    $id = (string) $this->postJson($url, ['nombre' => 'Proteína', 'precio_minor' => 6000], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->putJson("{$url}/{$id}", [
        'nombre' => 'Proteína grande', 'precio_minor' => 9000,
        'extras' => [['nombre' => 'Leche', 'precio_minor' => -5]],
    ], conBearer($e['bearer']))->assertUnprocessable();
    // El producto tampoco cambió.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.nombre', 'Proteína')
        ->assertJsonPath('data.0.precio_minor', 6000)
        ->assertJsonPath('data.0.extras', []);
});

it('la respuesta de un movimiento solo trae las sedes de quien lo hace', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $articulo = productoConStockSeguro($e, $sedeA['sucursal']);
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sedeB['sucursal'], 'tipo' => 'entrada', 'cantidad' => 7,
    ], conBearer($e['bearer']))->assertCreated();
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    $r = $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sedeA['sucursal'], 'tipo' => 'ajuste', 'cantidad' => -2, 'motivo' => 'Conteo físico',
    ], conBearer($recep))->assertCreated();

    expect(collect($r->json('data.existencias'))->pluck('sucursal_id')->all())->toBe([$sedeA['sucursal']])
        ->and($r->json('data.stock_total'))->toBe(8);
});

it('un ajuste no deja el stock en negativo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $articulo = productoConStockSeguro($e, $sede['sucursal'], 5);
    $url = "/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos";

    $this->postJson($url, ['sucursal_id' => $sede['sucursal'], 'tipo' => 'ajuste', 'cantidad' => -6, 'motivo' => 'Merma'], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->postJson($url, ['sucursal_id' => $sede['sucursal'], 'tipo' => 'ajuste', 'cantidad' => -5, 'motivo' => 'Merma'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.stock_total', 0);
});

it('el mostrador devuelve la misma venta si el cobro se reintenta con su clave', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $articulo = productoConStockSeguro($e, $sede['sucursal'], 10);
    $venta = [
        'sucursal_id' => $sede['sucursal'], 'clave_intento' => 'intento-01JZ5',
        'items' => [['articulo_id' => $articulo, 'cantidad' => 3, 'precio_minor' => 5000]],
    ];

    $primera = $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", $venta, conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", $venta, conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.id', $primera);

    // Una sola venta y un solo descuento de inventario.
    $this->getJson("/api/v1/app/{$e['slug']}/pos/ventas", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.stock_total', 7);
});

it('no cobra un precio que cambió con el carrito abierto ni un producto desactivado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $articulo = productoConStockSeguro($e, $sede['sucursal'], 10, 5000);
    $url = "/api/v1/app/{$e['slug']}/pos/ventas";

    // Subió a $60 mientras el mostrador mostraba $50.
    $this->putJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}", ['nombre' => 'Botella', 'precio_minor' => 6000], conBearer($e['bearer']))->assertOk();
    $r = $this->postJson($url, [
        'sucursal_id' => $sede['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1, 'precio_minor' => 5000]],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect($r->json('meta.errors')['items.0.precio_minor'][0])->toBe('Cambió el precio de «Botella»: revisa el total y vuelve a cobrar.');
    // Con el precio actual, sí.
    $this->postJson($url, [
        'sucursal_id' => $sede['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1, 'precio_minor' => 6000]],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.total_minor', 6000);

    // Desactivado con el carrito abierto: ya no se vende (y no se descuenta nada).
    $this->putJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}", ['nombre' => 'Botella', 'precio_minor' => 6000, 'activo' => false], conBearer($e['bearer']))->assertOk();
    $r = $this->postJson($url, [
        'sucursal_id' => $sede['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect($r->json('meta.errors')['items.0.articulo_id'][0])->toBe('«Botella» ya no está a la venta: quítalo de la venta.');
    $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.stock_total', 9);
});
