<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Una venta de mostrador registrada por error (ADR 0089): se corrige su forma de
| pago o se anula, con las mismas reglas que un cobro en caja. Anulada, no cuenta en
| el corte y lo vendido regresa al inventario.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Tienda con 10 pomadas en su sucursal y una venta de 2 en efectivo.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, articulo: string, venta: string}
 */
function ventaDeMostradorParaCorregir(): array
{
    $e = estudioConSesion('barberia-pos', 'dueno@barberia-pos.mx');
    $sede = agendaSemilla($e);
    $base = "/api/v1/app/{$e['slug']}";
    $articulo = (string) test()->postJson("{$base}/articulos", ['nombre' => 'Pomada mate', 'precio_minor' => 28000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$articulo}/movimientos", ['sucursal_id' => $sede['sucursal'], 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))
        ->assertCreated();
    $venta = (string) test()->postJson("{$base}/pos/ventas", [
        'sucursal_id' => $sede['sucursal'], 'metodo_pago' => 'efectivo', 'items' => [['articulo_id' => $articulo, 'cantidad' => 2]],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.corregible', true)->json('data.id');

    return ['e' => $e, 'base' => $base, 'articulo' => $articulo, 'venta' => $venta];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, base: string}  $c
 */
function stockDePomada(array $c): int
{
    return (int) test()->getJson("{$c['base']}/articulos", conBearer($c['e']['bearer']))->assertOk()->json('data.0.stock_total');
}

it('corrige la forma de pago de una venta sin cambiar el total, con bitácora', function (): void {
    $c = ventaDeMostradorParaCorregir();

    $this->putJson("{$c['base']}/pos/ventas/{$c['venta']}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Pagó con tarjeta'], conBearer($c['e']['bearer']))
        ->assertOk()
        ->assertJsonPath('data.metodo_pago', 'tarjeta')
        ->assertJsonPath('data.total_minor', 56000);

    $registro = collect($this->getJson("{$c['base']}/auditorias?accion=pos.metodo_corregido", conBearer($c['e']['bearer']))->assertOk()->json('data'))->first();
    expect($registro['antes'])->toBe(['metodo' => 'efectivo']);
    expect($registro['motivo'])->toBe('Pagó con tarjeta');
});

it('anular solo si el negocio lo permite; anulada, sale del corte y regresa al inventario', function (): void {
    $c = ventaDeMostradorParaCorregir();
    expect(stockDePomada($c))->toBe(8);

    // De inicio, anular cobros está apagado.
    $this->postJson("{$c['base']}/pos/ventas/{$c['venta']}/anular", ['motivo' => 'Se registró dos veces'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');

    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();
    $this->postJson("{$c['base']}/pos/ventas/{$c['venta']}/anular", ['motivo' => 'Se registró dos veces'], conBearer($c['e']['bearer']))
        ->assertOk()
        ->assertJsonPath('data.motivo_anulacion', 'Se registró dos veces')
        ->assertJsonPath('data.anulable', false)
        ->assertJsonPath('data.corregible', false);

    expect(stockDePomada($c))->toBe(10);
    $hoy = now('America/Mexico_City')->toDateString();
    $ventas = collect($this->getJson("{$c['base']}/pagos/movimientos?desde={$hoy}&hasta={$hoy}", conBearer($c['e']['bearer']))->assertOk()->json('data'))
        ->where('tipo', 'venta');
    expect($ventas)->toBeEmpty();

    // Una vez anulada, ya no se vuelve a anular.
    $this->postJson("{$c['base']}/pos/ventas/{$c['venta']}/anular", ['motivo' => 'Otra vez'], conBearer($c['e']['bearer']))
        ->assertStatus(422);
});

it('quien solo vende no anula', function (): void {
    $c = ventaDeMostradorParaCorregir();
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();
    $instructor = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'barbero@barberia-pos.mx', 'instructor');

    $this->postJson("{$c['base']}/pos/ventas/{$c['venta']}/anular", ['motivo' => 'x'], conBearer($instructor))->assertForbidden();
});
