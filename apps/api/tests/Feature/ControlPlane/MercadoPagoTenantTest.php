<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Cobro con Mercado Pago (Checkout Pro) por negocio: preferencia con la referencia
| de nuestro pago; la notificación firmada solo avisa y el estado se lee de la API;
| reintentos que cancelan el ticket sin pagar; devoluciones con idempotencia.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));

    // Mercado Pago falso: el estado de cada cobro y lo que hay por referencia se
    // ajusta en cada prueba.
    $this->mp = (object) ['cobros' => [], 'cancelable' => true];
    $mp = $this->mp;
    Http::fake(function (Request $request) use ($mp) {
        $url = $request->url();

        return match (true) {
            $request->method() === 'POST' && str_ends_with($url, '/checkout/preferences') => Http::response([
                'id' => '111-pref', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=111-pref',
            ]),
            $request->method() === 'PUT' && str_contains($url, '/checkout/preferences/') => Http::response(['id' => '111-pref']),
            str_contains($url, '/v1/payments/search') => Http::response(['results' => array_values(array_filter(
                $mp->cobros,
                function (array $cobro) use ($url): bool {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

                    return $cobro['external_reference'] === ($consulta['external_reference'] ?? null);
                },
            ))]),
            str_ends_with($url, '/refunds') => Http::response(['id' => 9001, 'status' => 'approved']),
            $request->method() === 'PUT' && preg_match('#/v1/payments/(\d+)$#', $url) === 1 => $mp->cancelable
                ? Http::response(['status' => 'cancelled'])
                : Http::response(['message' => 'invalid status'], 400),
            preg_match('#/v1/payments/(\d+)$#', $url, $m) === 1 => Http::response($mp->cobros[$m[1]] ?? [], isset($mp->cobros[$m[1]]) ? 200 : 404),
            default => Http::response([], 404),
        };
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio que cobra con Mercado Pago y un alumno con un pack por pagar.
 *
 * @return array{slug: string, bearer: string, alumno: string, orden: string}
 */
function compraConMercadoPago(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/mercadopago", [
        'activa' => true, 'modo' => 'test',
        'credenciales' => ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp'],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.lista', true);
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    return [...$e, 'alumno' => $a['bearer'], 'orden' => $orden];
}

/**
 * Notificación de Mercado Pago firmada con la clave secreta del webhook.
 *
 * @param  array{slug: string}  $e
 */
function notificarMercadoPago(array $e, string $cobroId, string $secreto = 'secreto-mp'): TestResponse
{
    $ts = '1742505638683';
    $firma = hash_hmac('sha256', "id:{$cobroId};request-id:req-1;ts:{$ts};", $secreto);

    return test()->postJson(
        "/api/v1/webhooks/tenant/{$e['slug']}/mercadopago?data.id={$cobroId}&type=payment",
        ['action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => $cobroId]],
        ['x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => 'req-1'],
    );
}

/**
 * @param  array{slug: string, alumno: string}  $c
 */
function estadoOrden(array $c): string
{
    return (string) collect(test()->getJson("/api/v1/app/{$c['slug']}/mi/ordenes", conBearer($c['alumno']))->json('data'))
        ->firstWhere('id', $c['orden'])['estado'];
}

it('el alumno paga en la página de Mercado Pago y la notificación firmada confirma', function (): void {
    $c = compraConMercadoPago();

    $r = $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))
        ->assertCreated()->json('data');
    expect($r['proveedor'])->toBe('mercadopago')
        ->and($r['checkout'])->toBe(['tipo' => 'redirect', 'url' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=111-pref']);
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/checkout/preferences')
        && $q['external_reference'] === $r['pago']
        && $q['items'][0]['unit_price'] === 899.0 && $q['items'][0]['currency_id'] === 'MXN'
        && str_ends_with($q['notification_url'], '/webhooks/tenant/estudio-a/mercadopago')
        && str_contains($q['back_urls']['success'], '/mi-cuenta?pago=exito')
        && $q->header('Authorization')[0] === 'Bearer APP_USR-prueba');

    // Mercado Pago avisa del cobro aprobado; el estado se lee de su API.
    $this->mp->cobros['555'] = ['id' => 555, 'status' => 'approved', 'external_reference' => $r['pago']];
    notificarMercadoPago($c, '555')->assertOk();
    notificarMercadoPago($c, '555')->assertOk(); // avisos repetidos: sin efecto

    expect(estadoOrden($c))->toBe('pagada');
});

it('una notificación sin firma válida no toca nada', function (): void {
    $c = compraConMercadoPago();
    $pago = (string) $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->json('data.pago');
    $this->mp->cobros['555'] = ['id' => 555, 'status' => 'approved', 'external_reference' => $pago];

    notificarMercadoPago($c, '555', 'otro-secreto')->assertStatus(401);

    expect(estadoOrden($c))->toBe('pendiente');
});

it('al reintentar se cancela el ticket de OXXO sin pagar; si ya se pagó, no se abre otro intento', function (): void {
    $c = compraConMercadoPago();
    $pago = (string) $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->json('data.pago');

    // Generó un ticket de OXXO (pendiente) y quiere pagar de otra forma.
    $this->mp->cobros['700'] = ['id' => 700, 'status' => 'pending', 'external_reference' => $pago];
    notificarMercadoPago($c, '700')->assertOk();
    $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->assertCreated();
    Http::assertSent(fn (Request $q): bool => $q->method() === 'PUT' && str_ends_with($q->url(), '/v1/payments/700') && $q['status'] === 'cancelled');

    // Con el segundo intento ya pagado, no se puede abrir un tercero.
    $segundo = (string) collect(Http::recorded())
        ->map(fn (array $par) => $par[0])
        ->filter(fn (Request $q): bool => str_ends_with($q->url(), '/checkout/preferences'))
        ->last()['external_reference'];
    $this->mp->cobros = ['800' => ['id' => 800, 'status' => 'approved', 'external_reference' => $segundo]];
    $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Ya hay un pago en proceso para esta compra; espera su confirmación.');
});

it('si el cliente paga un intento que ya se había cerrado y la compra sigue pendiente, se confirma', function (): void {
    $c = compraConMercadoPago();
    $primero = (string) $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->json('data.pago');
    $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->assertCreated();

    // Pagó en la primera página (quedó abierta en otra pestaña).
    $this->mp->cobros['900'] = ['id' => 900, 'status' => 'approved', 'external_reference' => $primero];
    notificarMercadoPago($c, '900')->assertOk();

    expect(estadoOrden($c))->toBe('pagada');
});

it('la devolución desde cobranza se pide a Mercado Pago con llave de idempotencia', function (): void {
    $c = compraConMercadoPago();
    $pago = (string) $this->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [], conBearer($c['alumno']))->json('data.pago');
    $this->mp->cobros['555'] = ['id' => 555, 'status' => 'approved', 'external_reference' => $pago];
    notificarMercadoPago($c, '555')->assertOk();

    $this->postJson("/api/v1/app/{$c['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Se mudó'], conBearer($c['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.via', 'pasarela')
        ->assertJsonPath('data.referencia_externa', '9001');

    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/v1/payments/555/refunds')
        && $q['amount'] === 899.0 && $q->hasHeader('X-Idempotency-Key'));
});
