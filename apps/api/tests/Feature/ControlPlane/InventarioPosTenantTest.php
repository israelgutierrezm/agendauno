<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un artículo minorista y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearArticulo(array $e, string $nombre = 'Agua 600ml', int $precio = 2500): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => $nombre, 'precio_minor' => $precio, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function reabastecer(array $e, string $articulo, string $sucursal, int $cantidad): void
{
    test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sucursal, 'tipo' => 'entrada', 'cantidad' => $cantidad,
    ], conBearer($e['bearer']))->assertCreated();
}

it('crea articulo, reabastece y el stock es la suma del ledger', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e);

    // Sin movimientos: stock 0.
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista[0]['stock_total'])->toBe(0);

    reabastecer($e, $articulo, $semilla['sucursal'], 20);

    $art = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($art['stock_total'])->toBe(20);
    expect($art['existencias'][0]['stock'])->toBe(20);
});

it('cada existencia dice si hay stock, si es bajo o si no hay, con el umbral del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e);

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk();
    expect($lista->json('meta.stock_bajo'))->toBe(3);
    expect($lista->json('data.0.estado_stock'))->toBe('sin_stock');

    reabastecer($e, $articulo, $semilla['sucursal'], 3);
    $art = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($art['estado_stock'])->toBe('bajo');
    expect($art['existencias'][0]['estado'])->toBe('bajo');

    // El negocio decide qué es «bajo».
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['inventario.stock_bajo' => 2]], conBearer($e['bearer']))->assertOk();
    $art = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk();
    expect($art->json('meta.stock_bajo'))->toBe(2);
    expect($art->json('data.0.estado_stock'))->toBe('con_stock');
});

it('una venta POS registra el ticket y descuenta stock', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e, 'Agua 600ml', 2500);
    reabastecer($e, $articulo, $semilla['sucursal'], 20);

    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $semilla['sucursal'],
        'metodo_pago' => 'efectivo',
        'items' => [['articulo_id' => $articulo, 'cantidad' => 3]],
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.total_minor', 7500)
        ->assertJsonPath('data.lineas.0.cantidad', 3);

    // Stock 20 - 3 = 17.
    $art = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($art['stock_total'])->toBe(17);
});

it('rechaza vender mas de lo que hay en stock (STOCK_INSUFFICIENT)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e);
    reabastecer($e, $articulo, $semilla['sucursal'], 2);

    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $semilla['sucursal'],
        'items' => [['articulo_id' => $articulo, 'cantidad' => 5]],
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFICIENT');

    // No se descuento nada (la venta fallo atomicamente).
    $art = $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($art['stock_total'])->toBe(2);
});

it('el stock es por sucursal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    // Segunda sucursal.
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Org2'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $suc2 = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Del Valle', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $articulo = crearArticulo($e);
    reabastecer($e, $articulo, $semilla['sucursal'], 10);

    // La sucursal 2 no tiene stock: vender ahi falla.
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $suc2,
        'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFICIENT');

    // En la sucursal 1 si.
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $semilla['sucursal'],
        'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated();
});

it('un ajuste no puede dejar el stock en negativo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e);
    reabastecer($e, $articulo, $semilla['sucursal'], 3);

    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $semilla['sucursal'], 'tipo' => 'ajuste', 'cantidad' => -5, 'motivo' => 'Merma',
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFICIENT');
});

it('RBAC: el instructor no gestiona inventario ni vende en POS', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $articulo = crearArticulo($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/articulos", ['nombre' => 'X', 'precio_minor' => 100], conBearer($coach))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $semilla['sucursal'], 'items' => [['articulo_id' => $articulo, 'cantidad' => 1]],
    ], conBearer($coach))->assertStatus(403);
});

it('el inventario es tenant-local: un estudio no ve los articulos de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');
    crearArticulo($a, 'Solo de A');

    $this->getJson("/api/v1/app/{$b['slug']}/articulos", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});
