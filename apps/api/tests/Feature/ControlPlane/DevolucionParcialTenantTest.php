<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Devolución parcial en el mostrador (ADR 0178): algunas piezas de una venta regresan.
| Cada pieza vale lo que se cobró por ella; el dinero sale de la caja abierta por la
| forma de la venta (de una mixta, la elegida y sin pasar de lo que entró por ella); las
| piezas vuelven al inventario; no se devuelve más de lo vendido ni dos veces con la
| misma llave (y la misma llave con otras piezas se rechaza); con devoluciones la venta
| ya no se anula ni se corrige. De una mixta, si por la forma elegida no alcanza, el
| resto sale por las otras.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una tienda con 10 pomadas de $280 en su sede y la caja abierta sin fondo.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: string, pomada: string}
 */
function tiendaParaDevolver(): array
{
    $e = estudioConSesion('tienda-devoluciones', 'dueno@tienda-devoluciones.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $base = "/api/v1/app/{$e['slug']}";
    $pomada = (string) test()->postJson("{$base}/articulos", ['nombre' => 'Pomada mate', 'precio_minor' => 28000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$pomada}/movimientos", ['sucursal_id' => $sede, 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))
        ->assertCreated();
    test()->postJson("{$base}/caja/turnos", ['sucursal_id' => $sede, 'fondo_minor' => 0], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'base' => $base, 'sede' => $sede, 'pomada' => $pomada];
}

/**
 * @param  array{e: array{bearer: string}, base: string, sede: string, pomada: string}  $c
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ventaParaDevolver(array $c, int $piezas, array $extra = []): array
{
    return (array) test()->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo', 'items' => [['articulo_id' => $c['pomada'], 'cantidad' => $piezas]],
        ...$extra,
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data');
}

/** @param  array{e: array{bearer: string}, base: string, sede: string}  $c */
function esperadoAlDevolver(array $c): array
{
    return (array) test()->getJson("{$c['base']}/caja?sucursal_id={$c['sede']}", conBearer($c['e']['bearer']))->assertOk()->json('data.turno.esperado');
}

/** @param  array{e: array{bearer: string}, base: string, pomada: string}  $c */
function existenciasAlDevolver(array $c): int
{
    return (int) collect(test()->getJson("{$c['base']}/articulos", conBearer($c['e']['bearer']))->json('data'))
        ->firstWhere('id', $c['pomada'])['stock_total'];
}

it('devuelve algunas piezas: sale de la caja, vuelven al inventario y cuenta en el corte y en lo vendido', function (): void {
    $c = tiendaParaDevolver();
    $venta = ventaParaDevolver($c, 3, ['propina_minor' => 5000]);
    $linea = $venta['lineas'][0]['id'];
    expect($venta['devolvible'])->toBeTrue()
        ->and(existenciasAlDevolver($c))->toBe(7);
    $devolver = fn (int $piezas, string $llave) => $this->withHeader('Idempotency-Key', $llave)->postJson("{$c['base']}/pos/ventas/{$venta['id']}/devoluciones", [
        'lineas' => [['id' => $linea, 'cantidad' => $piezas]], 'motivo' => 'Venía abierta',
    ], conBearer($c['e']['bearer']));

    $datos = $devolver(1, 'dev-1')->assertCreated()->json('data');
    expect($datos)->toMatchArray(['devuelto_minor' => 28000])
        ->and($datos['lineas'][0])->toMatchArray(['cantidad' => 3, 'cantidad_devuelta' => 1]);
    // La misma llave no devuelve dos veces.
    $devolver(1, 'dev-1')->assertCreated()->assertJsonPath('data.devuelto_minor', 28000);
    // No más de lo que queda (2).
    $devolver(3, 'dev-2')->assertUnprocessable()->assertJsonPath('code', 'SALE_NOT_RETURNABLE');

    // La propina se queda; sale lo de una pieza.
    expect(esperadoAlDevolver($c)['efectivo'])->toBe(84000 + 5000 - 28000)
        ->and(existenciasAlDevolver($c))->toBe(8);
    $corte = $this->getJson("{$c['base']}/pagos/movimientos", conBearer($c['e']['bearer']))->assertOk()->json();
    expect($corte['totales']['devuelto_minor'])->toBe(28000)
        ->and($corte['totales']['por_metodo'])->toBe(['Efectivo' => 84000 - 28000])
        ->and(collect($corte['data'])->firstWhere('tipo', 'devolucion'))->toMatchArray(['monto_minor' => -28000, 'metodo' => 'efectivo', 'detalle' => 'Venía abierta']);
    expect($this->getJson("{$c['base']}/pos/ventas", conBearer($c['e']['bearer']))->assertOk()->json('meta.resumen'))
        ->toMatchArray(['vendido_minor' => 84000 - 28000, 'devuelto_minor' => 28000]);

    // Con devoluciones ya no se anula ni se corrige: se devuelve lo que queda.
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();
    $this->postJson("{$c['base']}/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Error'], conBearer($c['e']['bearer']))
        ->assertUnprocessable();
    $devolver(2, 'dev-3')->assertCreated()->assertJsonPath('data.devolvible', false);
    expect(existenciasAlDevolver($c))->toBe(10);
});

it('de una venta con varias formas sale primero por la elegida y lo que falte por las otras', function (): void {
    $c = tiendaParaDevolver();
    // 2 pomadas ($560): $200 en efectivo y $360 con tarjeta.
    $venta = ventaParaDevolver($c, 2, ['partes' => [['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 36000]]]);
    $devolver = fn (array $extra) => $this->postJson("{$c['base']}/pos/ventas/{$venta['id']}/devoluciones", [
        'lineas' => [['id' => $venta['lineas'][0]['id'], 'cantidad' => 1]], 'motivo' => 'No le quedó', ...$extra,
    ], conBearer($c['e']['bearer']));

    $devolver([])->assertUnprocessable()->assertJsonPath('code', 'SALE_NOT_RETURNABLE');
    // Por efectivo entraron $200: la pieza ($280) sale $200 en efectivo y $80 con tarjeta.
    $devolver(['metodo' => 'efectivo'])->assertCreated()
        ->assertJsonPath('devolucion.monto_minor', 28000)
        ->assertJsonPath('devolucion.metodo', 'mixto')
        ->assertJsonPath('devolucion.partes', [['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 8000]]);
    expect(esperadoAlDevolver($c))->toMatchArray(['efectivo' => 0, 'tarjeta' => 36000 - 8000]);
    // La otra pieza: ya no queda efectivo, sale toda con tarjeta.
    $devolver(['metodo' => 'efectivo'])->assertCreated()
        ->assertJsonPath('devolucion.partes', [['metodo' => 'tarjeta', 'monto_minor' => 28000]]);
    expect(esperadoAlDevolver($c))->toMatchArray(['efectivo' => 0, 'tarjeta' => 0]);

    // El corte cuenta cada parte en su forma.
    $corte = $this->getJson("{$c['base']}/pagos/movimientos", conBearer($c['e']['bearer']))->assertOk()->json();
    expect(collect($corte['totales']['por_metodo'])->sortKeys()->all())->toBe(['Efectivo' => 0, 'Tarjeta (terminal)' => 0])
        ->and($corte['totales']['devuelto_minor'])->toBe(56000);
    $repartida = collect($corte['data'])->where('tipo', 'devolucion')->firstWhere('metodo', 'mixto');
    expect($repartida['partes'])->toBe([['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 8000]]);
});

it('la misma llave con otras piezas se rechaza en lugar de dar por hecha otra devolución', function (): void {
    $c = tiendaParaDevolver();
    $venta = ventaParaDevolver($c, 3);
    $devolver = fn (int $piezas) => $this->withHeader('Idempotency-Key', 'intento-1')->postJson("{$c['base']}/pos/ventas/{$venta['id']}/devoluciones", [
        'lineas' => [['id' => $venta['lineas'][0]['id'], 'cantidad' => $piezas]], 'motivo' => 'Cambio de opinión',
    ], conBearer($c['e']['bearer']));

    $devolver(1)->assertCreated()->assertJsonPath('devolucion.monto_minor', 28000);
    // Reintento idéntico: la misma devolución y el mismo importe.
    $devolver(1)->assertCreated()->assertJsonPath('devolucion.monto_minor', 28000)->assertJsonPath('data.devuelto_minor', 28000);
    // Mismo intento con otra cantidad: no se informa $560 cuando salieron $280.
    $devolver(2)->assertUnprocessable()->assertJsonPath('code', 'SALE_NOT_RETURNABLE');
    expect(esperadoAlDevolver($c)['efectivo'])->toBe(84000 - 28000)
        ->and(existenciasAlDevolver($c))->toBe(8);
});

it('pasado el plazo del negocio ya no se devuelve', function (): void {
    $c = tiendaParaDevolver();
    $venta = ventaParaDevolver($c, 1);
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pos.dias_para_devolver' => 7]], conBearer($c['e']['bearer']))->assertOk();
    $this->travel(8)->days();

    $this->postJson("{$c['base']}/pos/ventas/{$venta['id']}/devoluciones", [
        'lineas' => [['id' => $venta['lineas'][0]['id'], 'cantidad' => 1]], 'motivo' => 'Tarde',
    ], conBearer($c['e']['bearer']))->assertUnprocessable()->assertJsonPath('message', 'Un producto se devuelve hasta 7 días después de la venta.');
});
