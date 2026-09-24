<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea comprador + pack + orden pendiente; devuelve [orden, comprador].
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{orden: string, comprador: string}
 */
function ordenPendiente(array $e): array
{
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return ['orden' => $orden, 'comprador' => $comprador];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function saldoComprador(array $e, string $comprador): int
{
    $d = test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.saldo');

    return (int) ($d ?? 0);
}

it('cobro manual aprueba y hace fulfillment de inmediato', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPendiente($e);

    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.orden.estado', 'pagada');

    expect(saldoComprador($e, $o['comprador']))->toBe(8000);
});

it('rechaza cobrar con una pasarela no activa (GATEWAY_UNAVAILABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPendiente($e);

    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');
});

it('cobro Stripe con llaves abre la página de pago de Stripe y el webhook confirma -> fulfillment', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_prueba_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_prueba_123',
        ], 200),
    ]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Activa Stripe con secret_key (sin webhook_secret: el webhook procesa sin firma).
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();

    $o = ordenPendiente($e);

    // Cobro en linea -> pendiente + redirección a Stripe Checkout; la orden sigue pendiente.
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.checkout.tipo', 'redirect')
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_prueba_123')
        ->assertJsonPath('data.orden.estado', 'pendiente');

    // La sesión cobra el total y regresa a Ventas con el resultado.
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['line_items'][0]['price_data']['unit_amount'] === 89900
        && str_ends_with($r['success_url'], '/ventas?pago=exito')
        && str_ends_with($r['cancel_url'], '/ventas?pago=cancelado'));

    expect(saldoComprador($e, $o['comprador']))->toBe(0); // aun sin fulfillment

    // Webhook de Stripe (sin webhook_secret -> sin firma): la sesión pagada confirma.
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_prueba_123', 'payment_status' => 'paid']],
    ])->assertOk();

    expect(saldoComprador($e, $o['comprador']))->toBe(8000); // fulfillment tras confirmar
});

it('con OXXO la sesión se completa sin pagar y solo se entrega al pagar en tienda', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_oxxo_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_oxxo_1',
        ], 200),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $o = ordenPendiente($e);

    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'oxxo',
    ], conBearer($e['bearer']))->assertCreated();
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['payment_method_types'] === ['oxxo']);

    // Generó su ficha OXXO pero aún no paga: no se entrega nada.
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_oxxo_1', 'payment_status' => 'unpaid']],
    ])->assertOk();
    expect(saldoComprador($e, $o['comprador']))->toBe(0);

    // Pagó en tienda.
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.async_payment_succeeded',
        'data' => ['object' => ['id' => 'cs_oxxo_1', 'payment_status' => 'paid']],
    ])->assertOk();
    expect(saldoComprador($e, $o['comprador']))->toBe(8000);
});

it('el webhook de Stripe con webhook_secret rechaza firma invalida (400)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test',
        'credenciales' => ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'],
    ], conBearer($e['bearer']))->assertOk();

    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_x']],
    ], ['Stripe-Signature' => 't=1,v1=firma-mala'])->assertStatus(400);
});

it('el cobro es idempotente por idempotency_key', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPendiente($e);

    $primero = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'manual', 'idempotency_key' => 'k-1',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    $segundo = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$o['orden']}/cobrar", [
        'proveedor' => 'manual', 'idempotency_key' => 'k-1',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    expect($segundo)->toBe($primero);
    // Fulfillment una sola vez.
    expect(saldoComprador($e, $o['comprador']))->toBe(8000);
});
