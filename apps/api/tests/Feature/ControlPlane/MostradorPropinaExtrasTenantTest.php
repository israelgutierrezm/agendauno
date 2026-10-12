<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Mostrador (ADR 0139): un producto con extras (una proteína con fresas o leche de
| soya), la propina aparte de lo vendido y para quién, y lo que entregó el cliente en
| efectivo para el cambio. En el corte, las propinas van aparte: están en la caja pero
| no son ingreso del negocio.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una cafetería con una proteína preparada (con sus extras) y 10 en existencia.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: string, proteina: string, extras: array<string, string>}
 */
function cafeteriaMostrador(): array
{
    $e = estudioConSesion('estudio-cafe', 'dueno@cafe.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $base = "/api/v1/app/{$e['slug']}";
    $proteina = test()->postJson("{$base}/articulos", [
        'nombre' => 'Proteína preparada', 'precio_minor' => 6000, 'moneda' => 'MXN',
        'extras' => [
            ['nombre' => 'Fresas', 'precio_minor' => 1500],
            ['nombre' => 'Arándanos', 'precio_minor' => 1500],
            ['nombre' => 'Medio plátano', 'precio_minor' => 500],
            ['nombre' => 'Leche de soya', 'precio_minor' => 1000],
        ],
    ], conBearer($e['bearer']))->assertCreated();
    $id = (string) $proteina->json('data.id');
    test()->postJson("{$base}/articulos/{$id}/movimientos", ['sucursal_id' => $sede, 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))->assertCreated();

    return [
        'e' => $e, 'base' => $base, 'sede' => $sede, 'proteina' => $id,
        'extras' => collect($proteina->json('data.extras'))->pluck('id', 'nombre')->all(),
    ];
}

it('los extras del producto se editan con él y se cobran con su precio, guardados en la venta', function (): void {
    $c = cafeteriaMostrador();
    expect(array_keys($c['extras']))->toBe(['Fresas', 'Arándanos', 'Medio plátano', 'Leche de soya']);

    // Dos proteínas con fresas y leche de soya: (60 + 15 + 10) × 2.
    $venta = $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'tarjeta',
        'items' => [['articulo_id' => $c['proteina'], 'cantidad' => 2, 'extras' => [$c['extras']['Fresas'], $c['extras']['Leche de soya']]]],
    ], conBearer($c['e']['bearer']))->assertCreated();
    expect($venta->json('data.total_minor'))->toBe(17000)
        ->and($venta->json('data.lineas.0.extras'))->toBe(['Fresas', 'Leche de soya']);

    // Quitar un extra del producto no cambia lo ya vendido, y ya no se puede pedir.
    $this->putJson("{$c['base']}/articulos/{$c['proteina']}", [
        'nombre' => 'Proteína preparada', 'precio_minor' => 6000,
        'extras' => [['id' => $c['extras']['Arándanos'], 'nombre' => 'Arándanos', 'precio_minor' => 1800]],
    ], conBearer($c['e']['bearer']))->assertOk()->assertJsonPath('data.extras.0.precio_minor', 1800)->assertJsonCount(1, 'data.extras');
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'tarjeta',
        'items' => [['articulo_id' => $c['proteina'], 'cantidad' => 1, 'extras' => [$c['extras']['Fresas']]]],
    ], conBearer($c['e']['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['items.0.extras'], 'meta.errors');
    expect(collect($this->getJson("{$c['base']}/pos/ventas", conBearer($c['e']['bearer']))->json('data'))->first()['lineas'][0]['extras'])
        ->toBe(['Fresas', 'Leche de soya']);
});

it('la propina va aparte y para quien se diga; en efectivo, lo recibido da el cambio', function (): void {
    $c = cafeteriaMostrador();
    personalConSesion($c['e']['slug'], $c['e']['bearer'], 'barista@cafe.mx', 'recepcionista');
    $equipo = collect($this->getJson("{$c['base']}/pos/propina-para", conBearer($c['e']['bearer']))->assertOk()->json('data'));
    $barista = (string) $equipo->firstWhere('nombre', 'Personal')['id'];

    // $60 de proteína + $10 de propina, paga con $100: cambio de $30.
    $venta = $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo',
        'items' => [['articulo_id' => $c['proteina'], 'cantidad' => 1]],
        'propina_minor' => 1000, 'propina_para_id' => $barista, 'recibido_minor' => 10000,
    ], conBearer($c['e']['bearer']))->assertCreated();
    expect($venta->json('data'))->toMatchArray([
        'total_minor' => 6000, 'propina_minor' => 1000, 'propina_para' => 'Personal',
        'cobrado_minor' => 7000, 'recibido_minor' => 10000, 'cambio_minor' => 3000,
    ]);

    // Lo recibido no alcanza: no se cobra.
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo',
        'items' => [['articulo_id' => $c['proteina'], 'cantidad' => 1]], 'propina_minor' => 1000, 'recibido_minor' => 6500,
    ], conBearer($c['e']['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['recibido_minor'], 'meta.errors');
    // Solo alguien del equipo recibe propina.
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo',
        'items' => [['articulo_id' => $c['proteina'], 'cantidad' => 1]], 'propina_minor' => 500, 'propina_para_id' => 'no-existe',
    ], conBearer($c['e']['bearer']))->assertUnprocessable();

    // En el corte: lo cobrado es lo vendido; las propinas, aparte y por forma de pago.
    $corte = $this->getJson("{$c['base']}/pagos/movimientos", conBearer($c['e']['bearer']))->assertOk()->json();
    expect($corte['totales']['cobrado_minor'])->toBe(6000)
        ->and($corte['totales']['propinas_minor'])->toBe(1000)
        ->and($corte['totales']['propinas_por_metodo'])->toBe(['Efectivo' => 1000])
        ->and(collect($corte['data'])->firstWhere('tipo', 'venta')['propina_minor'])->toBe(1000);
});
