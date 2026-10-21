<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La factura de una venta (ADR 0179): una orden pagada o una venta del mostrador se
| factura una sola vez, con un borrador que ya trae los conceptos sin IVA, a quién y la
| forma de pago. Facturada, ya no se anula ni se corrige su forma de pago, y quien está
| acotado a una sede ve las facturas de las ventas de su sede.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un paquete de $899.00 vendido a Ana (o a quien se diga) y cobrado en caja en efectivo.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, orden: string, pago: string}
 */
function paqueteCobradoParaFacturar(array $e, ?string $persona = null): array
{
    $persona ??= crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    return ['persona' => $persona, 'orden' => $orden, 'pago' => $pago];
}

/**
 * Dos pomadas de $280.00 vendidas en el mostrador de la sede, con o sin propina.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function pomadasVendidasParaFacturar(array $e, string $sucursal, string $metodo = 'efectivo', int $propina = 0): string
{
    $base = "/api/v1/app/{$e['slug']}";
    $articulo = (string) test()->postJson("{$base}/articulos", ['nombre' => 'Pomada mate', 'precio_minor' => 28000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$articulo}/movimientos", ['sucursal_id' => $sucursal, 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))
        ->assertCreated();

    return (string) test()->postJson("{$base}/pos/ventas", [
        'sucursal_id' => $sucursal, 'metodo_pago' => $metodo, 'items' => [['articulo_id' => $articulo, 'cantidad' => 2]], 'propina_minor' => $propina,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * El borrador con el RFC genérico: lo que el negocio mandaría al timbrar.
 *
 * @param  array<string, mixed>  $borrador
 * @param  array<string, string>  $venta
 * @return array<string, mixed>
 */
function facturaDelBorrador(array $borrador, array $venta): array
{
    return [
        'receptor' => ['nombre' => 'Público en general', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'S01',
        'forma_pago' => $borrador['forma_pago'],
        'items' => $borrador['conceptos'],
        ...$venta,
    ];
}

it('el borrador de una orden pagada trae sus conceptos sin IVA, a quién y la forma de pago', function (): void {
    $e = estudioConSesion('factura-orden', 'a@factura-orden.mx');
    cargarDatosFiscales($e);
    $v = paqueteCobradoParaFacturar($e);

    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$v['orden']}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.etiqueta', 'Pack 8 clases')
        ->assertJsonPath('data.receptor.nombre', 'Ana')
        ->assertJsonPath('data.forma_pago', '01')
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.conceptos', [[
            'descripcion' => 'Pack 8 clases', 'cantidad' => 1, 'precio_unitario_minor' => 77500,
            'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48',
        ]]);
});

it('una orden se factura una vez: queda ligada y ya no se anula ni se corrige', function (): void {
    $e = estudioConSesion('factura-orden', 'a@factura-orden.mx');
    cargarDatosFiscales($e);
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($e['bearer']))
        ->assertOk();
    $v = paqueteCobradoParaFacturar($e);
    $url = "/api/v1/app/{$e['slug']}/facturas";
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $v['pago'])['facturable'])->toBeTrue();
    $borrador = $this->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk()->json('data');

    $factura = $this->postJson($url, facturaDelBorrador($borrador, ['orden_id' => $v['orden']]), conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.orden_id', $v['orden'])
        ->assertJsonPath('data.total_minor', 89900)
        ->json('data');

    // Ni otra factura ni otro borrador.
    $this->postJson($url, facturaDelBorrador($borrador, ['orden_id' => $v['orden']]), conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.orden_id.0', 'Esta venta ya está facturada.');
    $this->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.orden_id.0', 'Esta venta ya está facturada.');

    // El cobro dice su factura, y ya no se anula ni se le corrige la forma de pago.
    $fila = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $v['pago']);
    expect($fila['orden'])->toBe($v['orden'])
        ->and($fila['factura'])->toBe(['id' => $factura['id'], 'uuid' => $factura['uuid']])
        ->and($fila['facturable'])->toBeFalse();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');

    // Su siguiente compra ya trae los datos fiscales con que se le facturó.
    $otra = paqueteCobradoParaFacturar($e, $v['persona']);
    $this->getJson("{$url}/borrador?orden={$otra['orden']}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.receptor', [
            'nombre' => 'Público en general', 'email' => null, 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700', 'uso_cfdi' => 'S01', 'regimen_fiscal' => null,
        ]);
});

it('solo se factura una venta pagada, y un anticipo no factura la venta', function (): void {
    $e = estudioConSesion('factura-orden', 'a@factura-orden.mx');
    cargarDatosFiscales($e);
    $persona = crearMiembroTenant($e, 'Ana');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.orden_id.0', 'Solo se factura una venta pagada.');

    $v = paqueteCobradoParaFacturar($e);
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", [
        ...facturaDelBorrador(['forma_pago' => '01', 'conceptos' => [[
            'descripcion' => 'Anticipo', 'cantidad' => 1, 'precio_unitario_minor' => 10000, 'clave_prod_serv' => '84111506', 'clave_unidad' => 'ACT',
        ]]], ['orden_id' => $v['orden']]),
        'anticipo' => true,
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonValidationErrors(['orden_id'], 'meta.errors');
});

it('una venta del mostrador se factura una vez, sin la propina, y ya no se corrige ni se anula', function (): void {
    $e = estudioConSesion('factura-pos', 'a@factura-pos.mx');
    cargarDatosFiscales($e);
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($e['bearer']))
        ->assertOk();
    $sede = agendaSemilla($e);
    $venta = pomadasVendidasParaFacturar($e, $sede['sucursal'], 'tarjeta', 3000);
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $borrador = $this->getJson("{$url}/borrador?venta={$venta}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.forma_pago', '04')
        ->assertJsonPath('data.total_minor', 56000)
        ->assertJsonPath('data.conceptos', [[
            'descripcion' => 'Pomada mate', 'cantidad' => 2, 'precio_unitario_minor' => 24138,
            'clave_prod_serv' => '01010101', 'clave_unidad' => 'H87',
        ]])
        ->json('data');

    $factura = $this->postJson($url, facturaDelBorrador($borrador, ['venta_id' => $venta]), conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.venta_id', $venta)->json('data');
    $this->postJson($url, facturaDelBorrador($borrador, ['venta_id' => $venta]), conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.venta_id.0', 'Esta venta ya está facturada.');

    $fila = collect($this->getJson("/api/v1/app/{$e['slug']}/pos/ventas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $venta);
    expect($fila['factura'])->toBe(['id' => $factura['id'], 'uuid' => $factura['uuid']])
        ->and($fila['facturable'])->toBeFalse()
        ->and($fila['corregible'])->toBeFalse()
        ->and($fila['anulable'])->toBeFalse();
    $this->putJson("/api/v1/app/{$e['slug']}/pos/ventas/{$venta}/metodo", ['metodo' => 'efectivo', 'motivo' => 'Pagó en efectivo'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_CORRECTABLE');
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas/{$venta}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');
});

it('quien está acotado a una sede ve y factura solo las ventas de su sede', function (): void {
    $e = estudioConSesion('factura-sedes', 'a@factura-sedes.mx');
    cargarDatosFiscales($e);
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $ventaA = pomadasVendidasParaFacturar($e, $sedeA['sucursal']);
    $ventaB = pomadasVendidasParaFacturar($e, $sedeB['sucursal']);
    $url = "/api/v1/app/{$e['slug']}/facturas";
    $borrador = $this->getJson("{$url}/borrador?venta={$ventaB}", conBearer($e['bearer']))->assertOk()->json('data');
    $facturaB = (string) $this->postJson($url, facturaDelBorrador($borrador, ['venta_id' => $ventaB]), conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@factura-sedes.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@factura-sedes.mx'), $sedeA['sucursal']);

    // La de la otra sede, ni la ve ni la factura.
    $this->getJson("{$url}/borrador?venta={$ventaB}", conBearer($recep))->assertForbidden();
    $this->getJson("{$url}/{$facturaB}", conBearer($recep))->assertForbidden();
    expect($this->getJson($url, conBearer($recep))->assertOk()->json('data'))->toBe([]);

    // La de su sede, sí.
    $borradorA = $this->getJson("{$url}/borrador?venta={$ventaA}", conBearer($recep))->assertOk()->json('data');
    $facturaA = (string) $this->postJson($url, facturaDelBorrador($borradorA, ['venta_id' => $ventaA]), conBearer($recep))
        ->assertCreated()->json('data.id');
    expect(collect($this->getJson($url, conBearer($recep))->assertOk()->json('data'))->pluck('id')->all())->toBe([$facturaA]);
    expect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(2);
});

it('lo devuelto en el mostrador no se factura; devuelto todo, ya no es facturable', function (): void {
    $e = estudioConSesion('factura-devuelta', 'a@factura-devuelta.mx');
    cargarDatosFiscales($e);
    $sede = agendaSemilla($e);
    $base = "/api/v1/app/{$e['slug']}";
    test()->postJson("{$base}/caja/turnos", ['sucursal_id' => $sede['sucursal'], 'fondo_minor' => 0], conBearer($e['bearer']))->assertCreated();
    $venta = pomadasVendidasParaFacturar($e, $sede['sucursal']);
    $linea = (string) collect($this->getJson("{$base}/pos/ventas", conBearer($e['bearer']))->json('data'))->firstWhere('id', $venta)['lineas'][0]['id'];
    $devolver = fn (string $llave) => $this->withHeader('Idempotency-Key', $llave)->postJson("{$base}/pos/ventas/{$venta}/devoluciones", [
        'lineas' => [['id' => $linea, 'cantidad' => 1]], 'motivo' => 'Venía abierta',
    ], conBearer($e['bearer']))->assertCreated();

    // Devuelta una de dos pomadas: se factura una, por $280.
    $devolver('dev-1');
    $this->getJson("{$base}/facturas/borrador?venta={$venta}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.total_minor', 28000)
        ->assertJsonPath('data.conceptos.0.cantidad', 1)
        ->assertJsonPath('data.conceptos.0.precio_unitario_minor', 24138);

    // Devuelta la otra: ya no hay nada que facturar.
    $devolver('dev-2');
    $this->getJson("{$base}/facturas/borrador?venta={$venta}", conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.venta_id.0', 'Se devolvió todo lo vendido: no queda nada que facturar.');
    expect(collect($this->getJson("{$base}/pos/ventas", conBearer($e['bearer']))->json('data'))->firstWhere('id', $venta)['facturable'])
        ->toBeFalse();
});

it('la factura de una orden es por lo cobrado menos lo devuelto; devuelto todo, ya no se factura', function (): void {
    $e = estudioConSesion('factura-orden', 'a@factura-orden.mx');
    cargarDatosFiscales($e);
    $v = paqueteCobradoParaFacturar($e);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos", ['motivo' => 'Ajuste', 'monto_minor' => 40000], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 49900)
        ->assertJsonPath('data.conceptos.0.cantidad', 1)
        ->assertJsonPath('data.conceptos.0.precio_unitario_minor', 43017);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos", ['motivo' => 'Todo', 'monto_minor' => 49900], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertUnprocessable();
});
