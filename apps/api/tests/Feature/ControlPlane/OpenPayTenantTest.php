<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Cobro con OpenPay por negocio: tarjeta en la página de OpenPay y OXXO con
| referencia; la notificación (usuario y contraseña del webhook) hace releer el
| cargo; el código de verificación del webhook se le muestra al dueño; devoluciones
| solo de tarjeta.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));

    // OpenPay falso: el estado de cada cargo se ajusta en cada prueba.
    $this->op = (object) ['cargos' => [], 'n' => 0];
    $op = $this->op;
    Http::fake(function (Request $request) use ($op) {
        $url = $request->url();

        if ($request->method() === 'POST' && str_ends_with($url, '/charges')) {
            $op->n++;
            $id = 'trx'.$op->n;
            $op->cargos[$id] = ['id' => $id, 'status' => 'charge_pending', 'order_id' => $request['order_id']];

            return Http::response($request['method'] === 'store'
                ? ['id' => $id, 'status' => 'in_progress', 'payment_method' => [
                    'type' => 'store', 'reference' => '000020TRN', 'barcode_url' => 'https://sandbox-api.openpay.mx/barcode/000020TRN',
                ]]
                : ['id' => $id, 'status' => 'charge_pending', 'payment_method' => [
                    'type' => 'redirect', 'url' => "https://sandbox-api.openpay.mx/v1/m123/charges/{$id}/card_capture",
                ]]);
        }
        if (str_ends_with($url, '/refund')) {
            return Http::response(['id' => 'trx1', 'status' => 'completed', 'refund' => ['id' => 'ref1', 'status' => 'completed']]);
        }
        if (preg_match('#/charges/(trx\d+)$#', $url, $m) === 1) {
            return Http::response($op->cargos[$m[1]] ?? [], isset($op->cargos[$m[1]]) ? 200 : 404);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio que cobra con OpenPay y un alumno con un pack por pagar.
 *
 * @return array{slug: string, bearer: string, alumno: string, orden: string}
 */
function compraConOpenPay(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/openpay", [
        'activa' => true, 'modo' => 'test', 'credenciales' => [
            'merchant_id' => 'm123', 'private_key' => 'sk_prueba', 'public_key' => 'pk_prueba',
            'webhook_user' => 'agendauno', 'webhook_password' => 'clave-webhook',
        ],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.lista', true);
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    return [...$e, 'alumno' => $a['bearer'], 'orden' => $orden];
}

/**
 * Notificación de OpenPay con el usuario y contraseña del webhook.
 *
 * @param  array{slug: string}  $e
 * @param  array<string, mixed>  $evento
 */
function notificarOpenPay(array $e, array $evento, string $password = 'clave-webhook'): TestResponse
{
    return test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/openpay", $evento, [
        'Authorization' => 'Basic '.base64_encode("agendauno:{$password}"),
    ]);
}

/**
 * @param  array{slug: string, alumno: string, orden: string}  $c
 */
function estadoOrdenOpenPay(array $c): string
{
    return (string) collect(test()->getJson("/api/v1/app/{$c['slug']}/mi/ordenes", conBearer($c['alumno']))->json('data'))
        ->firstWhere('id', $c['orden'])['estado'];
}

it('con tarjeta, el alumno paga en la página de OpenPay y la notificación confirma', function (): void {
    $c = compraConOpenPay();

    $r = $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", ['metodo' => 'tarjeta'], conBearer($c['alumno']))
        ->assertCreated()->json('data');
    expect($r['proveedor'])->toBe('openpay')
        ->and($r['checkout'])->toBe(['tipo' => 'redirect', 'url' => 'https://sandbox-api.openpay.mx/v1/m123/charges/trx1/card_capture']);
    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://sandbox-api.openpay.mx/v1/m123/charges'
        && $q['method'] === 'card' && $q['confirm'] === false && $q['order_id'] === $r['pago']
        && $q['amount'] === 899.0 && $q['customer']['email'] === 'vale@correo.mx'
        && str_contains($q['redirect_url'], '/mi-cuenta?pago=exito')
        && $q->hasHeader('X-Forwarded-For')
        && $q->header('Authorization')[0] === 'Basic '.base64_encode('sk_prueba:'));

    // OpenPay avisa; el cargo se relee en su API.
    $this->op->cargos['trx1']['status'] = 'completed';
    $evento = ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trx1', 'status' => 'completed']];
    notificarOpenPay($c, $evento)->assertOk();
    notificarOpenPay($c, $evento)->assertOk(); // avisos repetidos: sin efecto

    expect(estadoOrdenOpenPay($c))->toBe('pagada');
});

it('una notificación con otra contraseña no toca nada', function (): void {
    $c = compraConOpenPay();
    $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", ['metodo' => 'tarjeta'], conBearer($c['alumno']))->assertCreated();
    $this->op->cargos['trx1']['status'] = 'completed';

    notificarOpenPay($c, ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trx1']], 'otra')->assertStatus(401);

    expect(estadoOrdenOpenPay($c))->toBe('pendiente');
});

it('con OXXO se entrega la referencia, el código de barras y el recibo', function (): void {
    $c = compraConOpenPay();

    $checkout = $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", ['metodo' => 'oxxo'], conBearer($c['alumno']))
        ->assertCreated()->json('data.checkout');

    expect($checkout['tipo'])->toBe('voucher')
        ->and($checkout['referencia'])->toBe('000020TRN')
        ->and($checkout['codigo_barras'])->toBe('https://sandbox-api.openpay.mx/barcode/000020TRN')
        ->and($checkout['recibo'])->toBe('https://sandbox-dashboard.openpay.mx/paynet-pdf/m123/000020TRN');
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/charges') && $q['method'] === 'store' && isset($q['due_date']));
});

it('el código de verificación del webhook queda a la vista del dueño', function (): void {
    $c = compraConOpenPay();

    notificarOpenPay($c, ['type' => 'verification', 'verification_code' => 'UY1qqrxw'])->assertOk();

    $openpay = collect($this->getJson("/api/v1/app/{$c['slug']}/pasarelas", conBearer($c['bearer']))->json('data'))
        ->firstWhere('proveedor', 'openpay');
    expect($openpay['codigo_verificacion'])->toBe('UY1qqrxw');
});

it('la devolución de un pago con tarjeta se pide a OpenPay; la de OXXO se registra por fuera', function (): void {
    $c = compraConOpenPay();
    $pago = (string) $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", ['metodo' => 'tarjeta'], conBearer($c['alumno']))->json('data.pago');
    $this->op->cargos['trx1']['status'] = 'completed';
    notificarOpenPay($c, ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trx1']])->assertOk();

    $this->postJson("/api/v1/app/{$c['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Se mudó'], conBearer($c['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'aprobado')->assertJsonPath('data.referencia_externa', 'ref1');
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/charges/trx1/refund') && $q['amount'] === 899.0);

    // Un pago en tienda no se puede devolver en línea.
    $otra = compraOxxoPagada($c);
    $this->postJson("/api/v1/app/{$c['slug']}/pagos/{$otra}/reembolsos", ['motivo' => 'Se mudó'], conBearer($c['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'OpenPay no devuelve pagos hechos en tienda. Si ya devolviste el dinero por otro medio, regístralo como devolución manual.');
});

/**
 * Otra compra del alumno, pagada en OXXO. Devuelve el pago.
 *
 * @param  array{slug: string, bearer: string, alumno: string}  $c
 */
function compraOxxoPagada(array $c): string
{
    $pack = crearPackTenant($c, 4000);
    $orden = (string) test()->postJson("/api/v1/app/{$c['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($c['alumno']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$orden}/cobrar", ['metodo' => 'oxxo'], conBearer($c['alumno']))->json('data.pago');
    $id = 'trx'.test()->op->n;
    test()->op->cargos[$id]['status'] = 'completed';
    notificarOpenPay($c, ['type' => 'charge.succeeded', 'transaction' => ['id' => $id]])->assertOk();

    return $pago;
}
