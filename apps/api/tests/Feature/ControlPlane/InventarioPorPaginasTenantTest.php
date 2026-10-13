<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Inventario que crece: el listado va por páginas con búsqueda y filtros en el
| servidor (y sus indicadores cuentan todo), un producto y sus extras se validan
| juntos para marcar cada error en su campo, y un ajuste a mano dice por qué y queda
| en el historial del producto con quién lo hizo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un producto con esa existencia en la sede (como propietario); devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function productoInventarioPaginado(array $e, string $sucursal, string $nombre, int $stock, ?string $sku = null): string
{
    $articulo = (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => $nombre, 'sku' => $sku, 'precio_minor' => 5000, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    if ($stock > 0) {
        test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
            'sucursal_id' => $sucursal, 'tipo' => 'entrada', 'cantidad' => $stock,
        ], conBearer($e['bearer']))->assertCreated();
    }

    return $articulo;
}

it('pagina, busca y filtra el inventario en el servidor con indicadores de todo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $agua = productoInventarioPaginado($e, $sede, 'Agua', 20, 'AG-1');
    productoInventarioPaginado($e, $sede, 'Barra', 2);
    productoInventarioPaginado($e, $sede, 'Cera', 0);
    $categoria = (string) $this->postJson("/api/v1/app/{$e['slug']}/categorias-articulo", ['nombre' => 'Bebidas'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/articulos/{$agua}", [
        'nombre' => 'Agua', 'sku' => 'AG-1', 'precio_minor' => 5000, 'categoria_id' => $categoria,
    ], conBearer($e['bearer']))->assertOk();
    $url = "/api/v1/app/{$e['slug']}/articulos";

    $this->getJson("{$url}?page=1&per_page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.ultima_pagina', 2)->assertJsonPath('meta.total_articulos', 3)
        ->assertJsonPath('meta.resumen', ['productos' => 3, 'con_stock' => 1, 'bajo' => 1, 'sin_stock' => 1]);
    $this->getJson("{$url}?page=1&stock=sin_stock", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nombre', 'Cera');
    $this->getJson("{$url}?page=1&stock=bajo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nombre', 'Barra');
    $this->getJson("{$url}?page=1&q=ag-1", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nombre', 'Agua');
    $this->getJson("{$url}?page=1&categoria=-", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson("{$url}?page=1&categoria={$categoria}&sucursal_id={$sede}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_total', 20);
    // Sin `page`, todos (el mostrador los necesita juntos).
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(3, 'data');
});

it('valida el producto y sus extras juntos, cada error en su campo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $errores = $this->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => '', 'precio_minor' => 5000, 'extras' => [['nombre' => '', 'precio_minor' => 100]],
    ], conBearer($e['bearer']))->assertUnprocessable()->json('meta.errors');

    expect(array_keys($errores))->toContain('nombre', 'extras.0.nombre');
});

it('un ajuste a mano exige el motivo y queda en el historial con quién lo hizo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    $articulo = productoInventarioPaginado($e, $sedeA, 'Agua', 10);
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sedeB, 'tipo' => 'entrada', 'cantidad' => 4,
    ], conBearer($e['bearer']))->assertCreated();
    $url = "/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos";

    $errores = $this->postJson($url, ['sucursal_id' => $sedeA, 'tipo' => 'ajuste', 'cantidad' => -2], conBearer($e['bearer']))
        ->assertUnprocessable()->json('meta.errors');
    expect($errores)->toHaveKey('motivo');
    $this->postJson($url, ['sucursal_id' => $sedeA, 'tipo' => 'ajuste', 'cantidad' => -2, 'motivo' => 'Merma: se rompió'], conBearer($e['bearer']))
        ->assertCreated();

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.tipo', 'ajuste')->assertJsonPath('data.0.cantidad', -2)
        ->assertJsonPath('data.0.motivo', 'Merma: se rompió')->assertJsonPath('data.0.de_venta', false)
        ->assertJsonPath('data.0.quien', fn ($quien) => is_string($quien) && $quien !== '');
    $this->getJson("{$url}?tipo=entrada", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 2);

    // Una recepcionista acotada a la sede A no ve los movimientos de la B.
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA);
    $this->getJson($url, conBearer($recep))->assertOk()->assertJsonPath('meta.total', 2);
});
