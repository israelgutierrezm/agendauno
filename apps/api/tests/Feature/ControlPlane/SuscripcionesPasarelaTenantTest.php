<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Pago automático por suscripción con Mercado Pago (preapproval) y OpenPay
| (suscripciones con la tarjeta capturada por OpenPay.js): la pasarela cobra cada
| mes por su cuenta; aquí se activa la suscripción y se concilian sus cobros (el
| aviso y la corrida diaria), una sola vez por cobro.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));

    $this->pg = (object) [
        'preapproval' => 'pending',
        'cuotas' => [],
        'cargosOp' => [],
    ];
    $pg = $this->pg;
    Http::fake(function (Request $request) use ($pg) {
        $url = $request->url();
        $metodo = $request->method();

        return match (true) {
            // Mercado Pago
            $metodo === 'POST' && str_ends_with($url, '/preapproval') => Http::response([
                'id' => 'pre_1', 'init_point' => 'https://www.mercadopago.com.mx/subscriptions/checkout?preapproval_id=pre_1',
            ]),
            str_ends_with($url, '/preapproval/pre_1') => Http::response([
                'id' => 'pre_1', 'status' => $metodo === 'PUT' ? 'canceled' : $pg->preapproval,
                'payment_method_id' => 'visa', 'card_id' => 'card_9',
            ]),
            str_contains($url, '/authorized_payments/search') => Http::response(['results' => $pg->cuotas]),
            preg_match('#/authorized_payments/(\d+)$#', $url) === 1 => Http::response(['id' => 1, 'preapproval_id' => 'pre_1']),
            // OpenPay
            $metodo === 'POST' && str_ends_with($url, '/customers') => Http::response(['id' => 'cus_op1']),
            str_ends_with($url, '/customers/cus_op1/cards') => Http::response([
                'id' => 'card_op1', 'brand' => 'visa', 'card_number' => '411111XXXXXX1111',
                'expiration_year' => '30', 'expiration_month' => '12',
            ]),
            str_ends_with($url, '/plans') => Http::response(['id' => 'plan_1']),
            $metodo === 'POST' && str_ends_with($url, '/customers/cus_op1/subscriptions') => Http::response(['id' => 'sub_1', 'status' => 'trial']),
            str_ends_with($url, '/customers/cus_op1/subscriptions/sub_1') => $metodo === 'DELETE'
                ? Http::response(null, 204)
                : Http::response(['id' => 'sub_1', 'status' => 'active']),
            str_contains($url, '/customers/cus_op1/charges') => Http::response($pg->cargosOp),
            default => Http::response([], 404),
        };
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio que cobra con la pasarela dada y un alumno con una membresía mensual
 * ($1,299) pagada en recepción.
 *
 * @param  array<string, string>  $credenciales
 * @return array{slug: string, bearer: string, alumno: string, acuerdo: string}
 */
function membresiaConPasarela(string $proveedor, array $credenciales): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/{$proveedor}", [
        'activa' => true, 'modo' => 'test', 'credenciales' => $credenciales,
    ], conBearer($e['bearer']))->assertOk();
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $acuerdo = (string) test()->getJson("/api/v1/app/{$e['slug']}/mi/pago-automatico", conBearer($a['bearer']))
        ->assertOk()->json('data.membresias.0.id');

    return [...$e, 'alumno' => $a['bearer'], 'acuerdo' => $acuerdo];
}

/**
 * @param  array{slug: string}  $e
 */
function enEstudioSuscripcion(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * @param  array{slug: string, acuerdo: string}  $m
 */
function renovacionHoy(array $m): void
{
    enEstudioSuscripcion($m, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->update(['proxima_cobro_en' => now()->toDateString()]));
}

/**
 * @param  array{slug: string, acuerdo: string}  $m
 * @return array{proxima: string|null, mora: string|null, motivo: string|null, pagos: int}
 */
function estadoRenovacion(array $m): array
{
    return enEstudioSuscripcion($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $mora = ProcesoDunningTenant::query()->where('acuerdo_id', $acuerdo->getKey())->latest('id')->first();

        return [
            'proxima' => $acuerdo->proxima_cobro_en?->toDateString(),
            'mora' => $mora?->estado->value,
            'motivo' => $mora?->ultimo_motivo,
            'pagos' => PagoTenant::query()->whereNotNull('domiciliacion_id')->where('estado', 'aprobado')->count(),
        ];
    });
}

/**
 * @param  array{slug: string}  $e
 */
function avisoMercadoPago(array $e, string $tipo, string $id): TestResponse
{
    $ts = '1742505638683';
    $firma = hash_hmac('sha256', 'id:'.strtolower($id).";request-id:req-9;ts:{$ts};", 'secreto-mp');

    return test()->postJson(
        "/api/v1/webhooks/tenant/{$e['slug']}/mercadopago?data.id={$id}&type={$tipo}",
        ['type' => $tipo, 'data' => ['id' => $id]],
        ['x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => 'req-9'],
    );
}

/**
 * @return array{slug: string, bearer: string, alumno: string, acuerdo: string}
 */
function suscritoConMercadoPago(): array
{
    $m = membresiaConPasarela('mercadopago', ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp']);
    test()->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();
    test()->pg->preapproval = 'authorized';
    avisoMercadoPago($m, 'subscription_preapproval', 'pre_1')->assertOk();

    return $m;
}

/**
 * @param  array<string, mixed>  $pago
 * @return array<string, mixed>
 */
function cuotaMercadoPago(int $id, int $intento, array $pago): array
{
    return [
        'id' => $id, 'preapproval_id' => 'pre_1', 'status' => 'processed', 'retry_attempt' => $intento,
        'transaction_amount' => 1299.0, 'currency_id' => 'MXN', 'payment' => $pago,
    ];
}

it('con Mercado Pago, el alumno autoriza la suscripción en Mercado Pago y queda activa al avisar', function (): void {
    $m = membresiaConPasarela('mercadopago', ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp']);

    $r = $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))
        ->assertOk()->json('data');
    expect($r['estado'])->toBe('redirect')
        ->and($r['checkout']['url'])->toBe('https://www.mercadopago.com.mx/subscriptions/checkout?preapproval_id=pre_1');
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/preapproval')
        && $q['status'] === 'pending' && $q['payer_email'] === 'vale@correo.mx' && $q['reason'] === 'Mensualidad'
        && $q['auto_recurring']['transaction_amount'] === 1299.0
        && $q['auto_recurring']['frequency'] === 1 && $q['auto_recurring']['frequency_type'] === 'months'
        && isset($q['auto_recurring']['start_date'], $q['auto_recurring']['end_date']));

    // Aún sin autorizar: no se cobra sola.
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', false);

    $this->pg->preapproval = 'authorized';
    avisoMercadoPago($m, 'subscription_preapproval', 'pre_1')->assertOk();

    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', true);
});

it('cada cuota que cobra Mercado Pago paga la renovación una sola vez; un rechazo abre la mora', function (): void {
    $m = suscritoConMercadoPago();
    renovacionHoy($m);

    $this->pg->cuotas = [cuotaMercadoPago(11, 0, ['id' => 5001, 'status' => 'approved', 'status_detail' => 'accredited'])];
    avisoMercadoPago($m, 'subscription_authorized_payment', '11')->assertOk();
    avisoMercadoPago($m, 'subscription_authorized_payment', '11')->assertOk();

    $estado = estadoRenovacion($m);
    expect($estado['proxima'])->toBe(now()->addMonth()->toDateString())
        ->and($estado['pagos'])->toBe(1)
        ->and($estado['mora'])->toBeNull();

    // El mes siguiente la tarjeta no tiene fondos.
    $this->travel(1)->months();
    $this->pg->cuotas[] = cuotaMercadoPago(12, 1, ['id' => 5002, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']);
    avisoMercadoPago($m, 'subscription_authorized_payment', '12')->assertOk();

    $estado = estadoRenovacion($m);
    expect($estado['mora'])->toBe('en_mora')
        ->and($estado['motivo'])->toContain('no tiene fondos suficientes')
        ->and($estado['proxima'])->toBe(now()->toDateString());
});

it('la corrida diaria no cobra por su cuenta lo suscrito: concilia lo que cobró Mercado Pago', function (): void {
    $m = suscritoConMercadoPago();
    renovacionHoy($m);
    $this->pg->cuotas = [cuotaMercadoPago(11, 0, ['id' => 5001, 'status' => 'approved'])];

    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();

    Http::assertNotSent(fn (Request $q): bool => str_ends_with($q->url(), '/checkout/preferences'));
    expect(estadoRenovacion($m)['proxima'])->toBe(now()->addMonth()->toDateString());
});

it('quitar el pago automático cancela la suscripción en Mercado Pago', function (): void {
    $m = suscritoConMercadoPago();

    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();

    Http::assertSent(fn (Request $q): bool => $q->method() === 'PUT' && str_ends_with($q->url(), '/preapproval/pre_1') && $q['status'] === 'canceled');
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', false);
});

/**
 * @return array{slug: string, bearer: string, alumno: string, acuerdo: string}
 */
function membresiaConOpenPay(): array
{
    return membresiaConPasarela('openpay', [
        'merchant_id' => 'm123', 'private_key' => 'sk_prueba', 'public_key' => 'pk_prueba',
        'webhook_user' => 'agendauno', 'webhook_password' => 'clave-webhook',
    ]);
}

/**
 * @param  array{slug: string, alumno: string, acuerdo: string}  $m
 */
function suscribirConOpenPay(array $m): TestResponse
{
    return test()->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [
        'token_id' => 'tok_1', 'device_session_id' => 'dev_1',
    ], conBearer($m['alumno']));
}

it('con OpenPay, la tarjeta se captura con OpenPay.js y la membresía queda suscrita', function (): void {
    $m = membresiaConOpenPay();

    // Primero: lo que necesita el formulario de OpenPay.js (solo llaves públicas).
    $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'formulario')
        ->assertJsonPath('data.formulario', ['proveedor' => 'openpay', 'merchant_id' => 'm123', 'public_key' => 'pk_prueba', 'sandbox' => true]);

    $proxima = (string) enEstudioSuscripcion($m, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString());
    suscribirConOpenPay($m)->assertOk()->assertJsonPath('data.estado', 'activa');

    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/customers/cus_op1/cards')
        && $q['token_id'] === 'tok_1' && $q['device_session_id'] === 'dev_1');
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/plans')
        && $q['amount'] === 1299.0 && $q['repeat_every'] === 1 && $q['repeat_unit'] === 'month');
    Http::assertSent(fn (Request $q): bool => str_ends_with($q->url(), '/customers/cus_op1/subscriptions')
        && $q['plan_id'] === 'plan_1' && $q['source_id'] === 'card_op1'
        && $q['trial_end_date'] === Carbon::parse($proxima)->subDay()->toDateString());

    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', true)
        ->assertJsonPath('data.membresias.0.tarjeta.ultimos4', '1111');
});

it('los cargos de la suscripción de OpenPay pagan la renovación; un cargo fallido abre la mora', function (): void {
    $m = membresiaConOpenPay();
    suscribirConOpenPay($m)->assertOk();
    renovacionHoy($m);

    $this->pg->cargosOp = [['id' => 'trxs1', 'status' => 'completed', 'amount' => 1299, 'transaction_type' => 'charge', 'customer_id' => 'cus_op1']];
    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();
    expect(estadoRenovacion($m)['proxima'])->toBe(now()->addMonth()->toDateString());

    $this->travel(1)->months();
    $this->pg->cargosOp[] = ['id' => 'trxs2', 'status' => 'failed', 'amount' => 1299, 'transaction_type' => 'charge', 'customer_id' => 'cus_op1'];
    $this->postJson("/api/v1/webhooks/tenant/{$m['slug']}/openpay", [
        'type' => 'subscription.charge.failed', 'transaction' => ['id' => 'trxs2', 'customer_id' => 'cus_op1'],
    ], ['Authorization' => 'Basic '.base64_encode('agendauno:clave-webhook')])->assertOk();

    $estado = estadoRenovacion($m);
    expect($estado['mora'])->toBe('en_mora')->and($estado['pagos'])->toBe(1);
});

it('quitar el pago automático cancela la suscripción en OpenPay', function (): void {
    $m = membresiaConOpenPay();
    suscribirConOpenPay($m)->assertOk();

    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();

    Http::assertSent(fn (Request $q): bool => $q->method() === 'DELETE' && str_ends_with($q->url(), '/customers/cus_op1/subscriptions/sub_1'));
});
