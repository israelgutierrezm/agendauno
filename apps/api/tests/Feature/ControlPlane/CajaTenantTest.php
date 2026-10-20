<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Cierre y arqueo de caja (ADR 0175): cada sede abre su caja por turno con un fondo;
| cada movimiento de mostrador queda en el libro de su caja abierta; al cerrar se
| cuenta a ciegas y, si no cuadra, se pide el motivo y el admin lo revisa. Lo cerrado
| no cambia: anular, corregir o devolver después entra en la caja abierta del momento.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una barbería con 10 pomadas de $280 en su sede, un paquete de $899 y recepción.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: string, pomada: string, pack: string, recepcion: string}
 */
function mostradorConCaja(): array
{
    $e = estudioConSesion('barberia-caja', 'dueno@barberia-caja.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $base = "/api/v1/app/{$e['slug']}";
    $pomada = (string) test()->postJson("{$base}/articulos", ['nombre' => 'Pomada mate', 'precio_minor' => 28000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$pomada}/movimientos", ['sucursal_id' => $sede, 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))
        ->assertCreated();

    return [
        'e' => $e, 'base' => $base, 'sede' => $sede, 'pomada' => $pomada,
        'pack' => crearPackTenant($e, 8000),
        'recepcion' => personalConSesion($e['slug'], $e['bearer'], 'recepcion@barberia-caja.mx', 'recepcionista'),
    ];
}

/**
 * @param  array{base: string, sede: string, pomada: string}  $c
 */
function ventaDePomadas(array $c, string $bearer, int $cantidad, string $metodo = 'efectivo', int $propina = 0): string
{
    return (string) test()->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => $metodo, 'propina_minor' => $propina,
        'items' => [['articulo_id' => $c['pomada'], 'cantidad' => $cantidad]],
    ], conBearer($bearer))->assertCreated()->json('data.id');
}

/**
 * Un paquete vendido y cobrado en caja; devuelve el ulid del pago.
 *
 * @param  array{e: array{slug: string, bearer: string}, base: string, pack: string}  $c
 */
function packCobradoEnCaja(array $c, string $metodo, string $nombre = 'Ana'): string
{
    $persona = crearMiembroTenant($c['e'], $nombre);
    $orden = (string) test()->postJson("{$c['base']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $c['pack'], 'cantidad' => 1]],
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data.id');
    test()->postJson("{$c['base']}/ordenes/{$orden}/liquidar", ['metodo' => $metodo], conBearer($c['e']['bearer']))->assertOk();

    return (string) collect(test()->getJson("{$c['base']}/pagos", conBearer($c['e']['bearer']))->json('data'))
        ->first(fn (array $p): bool => str_starts_with((string) $p['persona'], $nombre))['id'];
}

/**
 * @param  array{base: string, sede: string}  $c
 * @return array<string, mixed>
 */
function cajaActual(array $c, string $bearer): array
{
    return (array) test()->getJson("{$c['base']}/caja?sucursal_id={$c['sede']}", conBearer($bearer))->assertOk()->json('data');
}

/**
 * @param  array{base: string, sede: string}  $c
 */
function abrirCaja(array $c, string $bearer, int $fondo): string
{
    return (string) test()->postJson("{$c['base']}/caja/turnos", ['sucursal_id' => $c['sede'], 'fondo_minor' => $fondo], conBearer($bearer))
        ->assertCreated()->json('data.id');
}

it('una sede que no usa caja cobra como siempre; ya con caja, sin una abierta no se mueve dinero', function (): void {
    $c = mostradorConCaja();
    ventaDePomadas($c, $c['recepcion'], 1);
    expect(cajaActual($c, $c['recepcion']))->toMatchArray(['usa_caja' => false, 'turno' => null]);

    $turno = abrirCaja($c, $c['recepcion'], 0);
    // Solo una caja sin cerrar por sede.
    $this->postJson("{$c['base']}/caja/turnos", ['sucursal_id' => $c['sede'], 'fondo_minor' => 0], conBearer($c['recepcion']))
        ->assertStatus(409)->assertJsonPath('code', 'CASH_REGISTER_ALREADY_OPEN');
    $this->postJson("{$c['base']}/caja/turnos/{$turno}/conteo", [], conBearer($c['recepcion']))->assertOk()->assertJsonPath('data.cuadra', true);

    // La caja de la sede está cerrada: ni venta ni cobro en caja.
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo', 'items' => [['articulo_id' => $c['pomada'], 'cantidad' => 1]],
    ], conBearer($c['recepcion']))->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_CLOSED');
    $persona = crearMiembroTenant($c['e'], 'Beto');
    $orden = (string) $this->postJson("{$c['base']}/ordenes", ['comprador_id' => $persona, 'items' => [['producto_id' => $c['pack'], 'cantidad' => 1]]], conBearer($c['e']['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("{$c['base']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_CLOSED');
    // Y no quedó nada a medias: la orden sigue por cobrar.
    $this->getJson("{$c['base']}/ordenes/{$orden}", conBearer($c['e']['bearer']))->assertJsonPath('data.estado', 'pendiente');
});

it('el libro de la caja: recepción cuenta a ciegas y el admin ve lo esperado en vivo', function (): void {
    $c = mostradorConCaja();
    $turno = abrirCaja($c, $c['recepcion'], 50000);
    ventaDePomadas($c, $c['recepcion'], 2, 'efectivo', 5000);
    packCobradoEnCaja($c, 'transferencia');
    $this->postJson("{$c['base']}/caja/turnos/{$turno}/movimientos", ['tipo' => 'retiro', 'monto_minor' => 20000, 'motivo' => 'Depósito al banco'], conBearer($c['recepcion']))
        ->assertCreated();

    // Recepción: sin lo esperado ni los cobros, solo sus retiros e ingresos.
    $ciega = cajaActual($c, $c['recepcion'])['turno'];
    expect($ciega['esperado'])->toBeNull()
        ->and($ciega['diferencia_minor'])->toBeNull()
        ->and(collect($ciega['movimientos'])->pluck('tipo')->all())->toBe(['retiro']);

    // El admin: fondo + venta + propina − retiro en efectivo; la transferencia aparte.
    $admin = cajaActual($c, $c['e']['bearer'])['turno'];
    expect($admin['esperado'])->toBe(['efectivo' => 91000, 'tarjeta' => 0, 'transferencia' => 89900, 'ventanilla' => 0])
        ->and(collect($admin['movimientos'])->pluck('tipo')->all())->toBe(['venta', 'propina', 'cobro', 'retiro']);

    // Cuadra: se cierra y ya se ve el resultado.
    $cerrada = $this->postJson("{$c['base']}/caja/turnos/{$turno}/conteo", ['efectivo_minor' => 91000, 'transferencia_minor' => 89900], conBearer($c['recepcion']))
        ->assertOk()->json('data');
    expect($cerrada)->toMatchArray(['estado' => 'cerrada', 'cuadra' => true, 'diferencia_minor' => 0, 'requiere_revision' => false])
        ->and(collect($cerrada['conteos'])->firstWhere('metodo', 'efectivo'))->toMatchArray(['esperado_minor' => 91000, 'contado_minor' => 91000]);
    expect(cajaActual($c, $c['recepcion']))->toMatchArray(['usa_caja' => true, 'turno' => null]);
});

it('si no cuadra pide el motivo, el conteo no se repite y el admin lo revisa', function (): void {
    $c = mostradorConCaja();
    $turno = abrirCaja($c, $c['recepcion'], 10000);
    ventaDePomadas($c, $c['recepcion'], 1);

    $contada = $this->postJson("{$c['base']}/caja/turnos/{$turno}/conteo", ['efectivo_minor' => 33000], conBearer($c['recepcion']))
        ->assertOk()->json('data');
    // Faltan $50: ya contada, se ve; la caja no recibe más dinero ni se vuelve a contar.
    expect($contada)->toMatchArray(['estado' => 'contada', 'cuadra' => false, 'diferencia_minor' => -5000]);
    $this->postJson("{$c['base']}/caja/turnos/{$turno}/conteo", ['efectivo_minor' => 38000], conBearer($c['recepcion']))
        ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_INVALID_STATE');
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'efectivo', 'items' => [['articulo_id' => $c['pomada'], 'cantidad' => 1]],
    ], conBearer($c['recepcion']))->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_CLOSED');
    $this->postJson("{$c['base']}/caja/turnos", ['sucursal_id' => $c['sede'], 'fondo_minor' => 0], conBearer($c['recepcion']))->assertStatus(409);

    $this->postJson("{$c['base']}/caja/turnos/{$turno}/cierre", ['motivo' => 'Di mal un cambio'], conBearer($c['recepcion']))
        ->assertOk()->assertJsonPath('data.estado', 'cerrada')->assertJsonPath('data.requiere_revision', true);

    // Recepción no supervisa; el admin ve los cierres por revisar y los revisa.
    $this->getJson("{$c['base']}/caja/turnos", conBearer($c['recepcion']))->assertForbidden();
    $pendientes = $this->getJson("{$c['base']}/caja/turnos?revision=pendiente", conBearer($c['e']['bearer']))->assertOk()->json('data');
    expect($pendientes)->toHaveCount(1)
        ->and($pendientes[0])->toMatchArray(['id' => $turno, 'diferencia_minor' => -5000, 'motivo_diferencia' => 'Di mal un cambio']);
    $this->postJson("{$c['base']}/caja/turnos/{$turno}/revision", ['nota' => 'Se habló con recepción'], conBearer($c['e']['bearer']))
        ->assertOk()->assertJsonPath('data.nota_revision', 'Se habló con recepción');
    expect($this->getJson("{$c['base']}/caja/turnos?revision=pendiente", conBearer($c['e']['bearer']))->json('data'))->toBe([]);
    $this->postJson("{$c['base']}/caja/turnos/{$turno}/revision", ['nota' => 'Otra vez'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_INVALID_STATE');
});

it('lo cerrado no cambia: anular, corregir y devolver después entran en la caja abierta', function (): void {
    $c = mostradorConCaja();
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();

    $primero = abrirCaja($c, $c['recepcion'], 0);
    $venta = ventaDePomadas($c, $c['recepcion'], 2);
    $pago = packCobradoEnCaja($c, 'efectivo');
    $this->postJson("{$c['base']}/caja/turnos/{$primero}/conteo", ['efectivo_minor' => 145900], conBearer($c['recepcion']))
        ->assertOk()->assertJsonPath('data.cuadra', true);

    $segundo = abrirCaja($c, $c['recepcion'], 200000);
    // La venta de ayer era con tarjeta; el paquete se anula; otro se cobra y se devuelve en parte.
    $this->putJson("{$c['base']}/pos/ventas/{$venta}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Pagó con tarjeta'], conBearer($c['e']['bearer']))->assertOk();
    $this->postJson("{$c['base']}/pagos/{$pago}/anular", ['motivo' => 'Se registró dos veces'], conBearer($c['e']['bearer']))->assertOk();
    $otro = packCobradoEnCaja($c, 'efectivo', 'Beto');
    $this->postJson("{$c['base']}/pagos/{$otro}/reembolsos", ['monto_minor' => 30000, 'motivo' => 'Descuento olvidado', 'revertir_creditos' => false], conBearer($c['e']['bearer']))
        ->assertCreated();

    // El primer cierre sigue como se contó.
    $cerrado = $this->getJson("{$c['base']}/caja/turnos/{$primero}", conBearer($c['e']['bearer']))->assertOk()->json('data');
    expect(collect($cerrado['conteos'])->firstWhere('metodo', 'efectivo'))->toMatchArray(['esperado_minor' => 145900, 'contado_minor' => 145900])
        ->and($cerrado['movimientos'])->toHaveCount(2);

    // Los ajustes, en la caja abierta: fondo − venta corregida − anulado + cobrado − devuelto.
    $abierta = cajaActual($c, $c['e']['bearer'])['turno'];
    expect($abierta['id'])->toBe($segundo)
        ->and($abierta['esperado'])->toBe(['efectivo' => 200000 - 56000 - 89900 + 89900 - 30000, 'tarjeta' => 56000, 'transferencia' => 0, 'ventanilla' => 0])
        ->and(collect($abierta['movimientos'])->pluck('tipo')->all())->toBe(['correccion', 'correccion', 'anulacion', 'cobro', 'devolucion']);
});

it('anular o corregir un cobro que no pasó por caja no descuadra la caja de hoy', function (): void {
    $c = mostradorConCaja();
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();
    // Antes de estrenar la caja: un paquete y una venta en efectivo.
    $pago = packCobradoEnCaja($c, 'efectivo');
    $otro = packCobradoEnCaja($c, 'efectivo', 'Beto');
    $venta = ventaDePomadas($c, $c['recepcion'], 1);

    abrirCaja($c, $c['recepcion'], 50000);
    $this->postJson("{$c['base']}/pagos/{$pago}/anular", ['motivo' => 'Se registró dos veces'], conBearer($c['e']['bearer']))->assertOk();
    $this->putJson("{$c['base']}/pagos/{$otro}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Fue con tarjeta'], conBearer($c['e']['bearer']))->assertOk();
    $this->putJson("{$c['base']}/pos/ventas/{$venta}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Fue con tarjeta'], conBearer($c['e']['bearer']))->assertOk();

    // Su dinero nunca estuvo en este cajón: la caja sigue con su fondo.
    $abierta = cajaActual($c, $c['e']['bearer'])['turno'];
    expect($abierta['esperado'])->toBe(['efectivo' => 50000, 'tarjeta' => 0, 'transferencia' => 0, 'ventanilla' => 0])
        ->and($abierta['movimientos'])->toBe([]);
});

it('repetir un retiro con su clave no lo registra dos veces; la misma clave con otro monto, no', function (): void {
    $c = mostradorConCaja();
    $turno = abrirCaja($c, $c['recepcion'], 50000);
    $url = "{$c['base']}/caja/turnos/{$turno}/movimientos";
    $retiro = fn (int $monto) => $this->postJson($url, ['tipo' => 'retiro', 'monto_minor' => $monto, 'motivo' => 'Pago a proveedor', 'clave' => 'ret-1'], conBearer($c['recepcion']));

    $retiro(20000)->assertCreated();
    $retiro(20000)->assertCreated();
    $retiro(30000)->assertStatus(422);

    $abierta = cajaActual($c, $c['e']['bearer'])['turno'];
    expect($abierta['esperado']['efectivo'])->toBe(30000)
        ->and($abierta['movimientos'])->toHaveCount(1);
});

it('fuera del efectivo, el conteo admite un monto negativo (una devolución por la terminal)', function (): void {
    $c = mostradorConCaja();
    $turno = abrirCaja($c, $c['recepcion'], 0);
    $url = "{$c['base']}/caja/turnos/{$turno}/conteo";

    $this->postJson($url, ['efectivo_minor' => -100], conBearer($c['recepcion']))->assertStatus(422);
    $this->postJson($url, ['efectivo_minor' => 0, 'tarjeta_minor' => -89900], conBearer($c['recepcion']))
        ->assertOk()->assertJsonPath('data.cuadra', false);
});
