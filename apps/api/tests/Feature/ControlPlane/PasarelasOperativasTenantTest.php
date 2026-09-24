<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Solo cobra una pasarela completamente operativa (Stripe con su llave); OpenPay y
| Mercado Pago se muestran como no disponibles. Un intento que vence o no se paga se
| cierra, y reintentar anula el intento anterior (o espera si ya se pagó).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Stripe falso: cada sesión nueva es cs_1, cs_2…; vencer una sesión responde según
 * `$yaPagada` (ya pagada → no se puede vencer y su estado es complete).
 *
 * @return object{sesiones: int}
 */
function stripeDeIntentos(bool $yaPagada = false): object
{
    $estado = (object) ['sesiones' => 0];
    Http::fake(function (Request $request) use ($estado, $yaPagada) {
        $url = $request->url();

        if (str_ends_with($url, '/checkout/sessions') && $request->method() === 'POST') {
            $estado->sesiones++;
            $id = 'cs_'.$estado->sesiones;

            return Http::response(['id' => $id, 'url' => "https://checkout.stripe.com/c/pay/{$id}"]);
        }
        if (str_ends_with($url, '/expire')) {
            return $yaPagada ? Http::response(['error' => ['message' => 'complete']], 400) : Http::response(['status' => 'expired']);
        }
        if (str_contains($url, '/checkout/sessions/')) {
            return Http::response(['status' => $yaPagada ? 'complete' : 'expired']);
        }

        return Http::response([], 404);
    });

    return $estado;
}

/**
 * Orden pendiente de un pack y Stripe listo (con llave).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ordenConStripeListo(array $e): string
{
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.lista', true);

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e, 8000), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('OpenPay y Mercado Pago aparecen como no disponibles y no se pueden activar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $pasarelas = collect($this->getJson("/api/v1/app/{$e['slug']}/pasarelas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->keyBy('proveedor');
    expect($pasarelas['stripe']['disponible'])->toBeTrue()
        ->and($pasarelas['openpay']['disponible'])->toBeFalse()
        ->and($pasarelas['mercadopago']['disponible'])->toBeFalse();

    foreach (['openpay', 'mercadopago'] as $proveedor) {
        $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/{$proveedor}", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))
            ->assertStatus(422);
    }
});

it('Stripe sin llave secreta no cobra (antes simulaba un intento)', function (): void {
    Http::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.lista', false);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e, 8000), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');
    Http::assertNothingSent();
});

it('una sesión vencida cierra el intento y reintentar abre otra, anulando la anterior', function (): void {
    $stripe = stripeDeIntentos();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $orden = ordenConStripeListo($e);

    $primero = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->json('data.pago');

    // Stripe avisa que la sesión venció sin pagarse: el intento queda rechazado.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_1']],
    ])->assertOk();
    $estadoPrimero = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): string => PagoTenant::query()->where('ulid', $primero)->firstOrFail()->estado->value,
    );
    expect($estadoPrimero)->toBe('rechazado');

    // Reintento: sesión nueva; y un tercer intento anula la segunda (que seguía abierta).
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_2');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_3');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/checkout/sessions/cs_2/expire'));
    expect($stripe->sesiones)->toBe(3);

    // La sesión vigente se paga: la orden queda pagada.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_3', 'payment_status' => 'paid']],
    ])->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'pagada');
});

it('si el intento anterior ya se pagó, no se cobra otra vez', function (): void {
    stripeDeIntentos(yaPagada: true);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $orden = ordenConStripeListo($e);

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Ya hay un pago en proceso para esta compra; espera su confirmación.');
});

it('la renta: una sesión vencida libera el cargo y reintentar vence la anterior', function (): void {
    stripeDeIntentos();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plataforma']);
    $cargo = cargoRentaPendiente($e);

    $ref = (string) $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->json('data.referencia');
    expect($ref)->toBe('cs_1');

    // Reintento con el primero aún abierto: se vence y se abre otro.
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.referencia', 'cs_2');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/checkout/sessions/cs_1/expire'));

    // Stripe avisa que cs_2 venció: el cargo queda sin intento en curso.
    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_2']],
    ])->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.cargos.0.estado', 'pendiente');
});
