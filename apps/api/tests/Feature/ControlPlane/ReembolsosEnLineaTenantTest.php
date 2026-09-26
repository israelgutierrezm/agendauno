<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Devoluciones de pagos en línea: se piden a Stripe de verdad; lo pendiente se
| concilia con su webhook; si no se puede devolver en línea, el negocio la declara
| manual. Y la reversión parcial se calcula sobre lo acumulado.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Stripe falso: la sesión cs_1 cobró con pi_1; las devoluciones responden con el
 * estado dado (o con error si es null).
 */
function stripeConDevolucion(?string $estadoDevolucion): void
{
    Http::fake(function (Request $request) use ($estadoDevolucion) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/checkout/sessions') => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            str_contains($url, '/checkout/sessions/cs_1') => Http::response(['id' => 'cs_1', 'payment_intent' => 'pi_1']),
            str_ends_with($url, '/refunds') => $estadoDevolucion === null
                ? Http::response(['error' => ['message' => 'charge_already_refunded']], 400)
                : Http::response(['id' => 're_1', 'status' => $estadoDevolucion]),
            default => Http::response([], 404),
        };
    });
}

/**
 * Pack de 8 créditos vendido a "Ana" y pagado en línea con Stripe (confirmado por el
 * webhook). Devuelve persona y pago.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, pago: string}
 */
function packPagadoConStripe(array $e): array
{
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $persona = crearMiembroTenant($e, 'Ana');
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e, 8000), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();

    return ['persona' => $persona, 'pago' => $pago];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function saldoDeAna(array $e, string $persona): int
{
    return (int) test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.saldo');
}

it('la devolución de un pago con Stripe se pide a Stripe y revierte los créditos', function (): void {
    stripeConDevolucion('succeeded');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $p = packPagadoConStripe($e);
    expect(saldoDeAna($e, $p['persona']))->toBe(8000);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.via', 'pasarela')
        ->assertJsonPath('data.referencia_externa', 're_1');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/refunds')
        && $r['payment_intent'] === 'pi_1' && (int) $r['amount'] === 89900
        && $r->hasHeader('Idempotency-Key'));
    expect(saldoDeAna($e, $p['persona']))->toBe(0);
});

it('una devolución pendiente no toca créditos hasta que Stripe la confirma', function (): void {
    stripeConDevolucion('pending');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $p = packPagadoConStripe($e);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'pendiente');
    expect(saldoDeAna($e, $p['persona']))->toBe(8000);

    // Mientras está en curso no se puede volver a devolver lo mismo.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Otra vez'], conBearer($e['bearer']))
        ->assertStatus(422);

    // Stripe confirma: se aplican los efectos (una sola vez aunque el aviso se repita).
    foreach ([1, 2] as $_) {
        $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
            'type' => 'charge.refund.updated', 'data' => ['object' => ['id' => 're_1', 'status' => 'succeeded']],
        ])->assertOk();
    }

    $this->getJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.estado', 'aprobado');
    expect(saldoDeAna($e, $p['persona']))->toBe(0);
});

it('si Stripe rechaza después la devolución pendiente, queda fallida y se puede pedir otra', function (): void {
    stripeConDevolucion('pending');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $p = packPagadoConStripe($e);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'refund.failed', 'data' => ['object' => ['id' => 're_1', 'status' => 'failed']],
    ])->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.estado', 'fallido');
    expect(saldoDeAna($e, $p['persona']))->toBe(8000);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'De nuevo'], conBearer($e['bearer']))
        ->assertCreated();
});

it('si la pasarela no puede devolver, el intento queda fallido y se puede declarar devolución manual', function (): void {
    stripeConDevolucion(null); // Stripe responde con error
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $p = packPagadoConStripe($e);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_REFUNDABLE');
    // El intento queda registrado (antes de pedirlo) como fallido, con el motivo.
    $this->getJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'fallido');
    expect(saldoDeAna($e, $p['persona']))->toBe(8000);

    // El negocio le devolvió por transferencia y lo declara.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Transferencia', 'manual' => true], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.via', 'manual');
    expect(saldoDeAna($e, $p['persona']))->toBe(0);
});

it('dos devoluciones del 50 % dejan los créditos en 0, no en 25 %', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e, 8000), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'manual'], conBearer($e['bearer']))
        ->assertCreated()->json('data.pago');

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['monto_minor' => 44950, 'motivo' => 'Mitad'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.via', 'caja');
    expect(saldoDeAna($e, $persona))->toBe(4000);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['monto_minor' => 44950, 'motivo' => 'Otra mitad'], conBearer($e['bearer']))
        ->assertCreated();
    expect(saldoDeAna($e, $persona))->toBe(0);
});
