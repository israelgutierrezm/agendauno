<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| La renta también con Mercado Pago y OpenPay (ADR 0192). El superadmin las activa con
| sus llaves; el dueño elige con cuál paga. Un aviso de la pasarela solo dice qué
| cambió: el cobro se relee en su API antes de dar la renta por pagada, y si el aviso
| no llega, la conciliación lo encuentra.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // Lo que responden las pasarelas en la prueba: se cambia por prueba.
    $this->pasarela = (object) ['estado' => 'approved', 'cargo' => '', 'monto' => 0.0, 'pagos' => [], 'openpay' => 'completed'];

    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_contains($url, 'api.mercadopago.com/checkout/preferences')) {
            return Http::response(['id' => '123-pref', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=123-pref']);
        }
        if (str_contains($url, 'api.mercadopago.com/v1/payments/search')) {
            return Http::response(['results' => $this->pasarela->pagos]);
        }
        if (str_contains($url, 'api.mercadopago.com/v1/payments/')) {
            return Http::response([
                'id' => 987, 'status' => $this->pasarela->estado,
                'external_reference' => $this->pasarela->cargo, 'transaction_amount' => $this->pasarela->monto,
            ]);
        }
        if (str_contains($url, 'sandbox-api.openpay.mx') && $request->method() === 'POST') {
            return Http::response(['id' => 'tr_renta', 'payment_method' => ['url' => 'https://sandbox-api.openpay.mx/v1/m1/charges/tr_renta/card_capture']]);
        }
        if (str_contains($url, 'sandbox-api.openpay.mx')) {
            return Http::response([
                'id' => 'tr_renta', 'status' => $this->pasarela->openpay, 'amount' => $this->pasarela->monto,
                'order_id' => $this->pasarela->cargo.'-261101120000',
            ]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array<string, string>  $credenciales
 */
function activarPasarelaDeRenta(string $proveedor, array $credenciales): void
{
    test()->putJson("/api/v1/plataforma/pasarelas/{$proveedor}", [
        'activa' => true, 'modo' => 'test', 'credenciales' => $credenciales,
    ], conPlataforma())->assertOk()->assertJsonPath('data.activa', true);
}

it('el dueño paga su renta con Mercado Pago y el aviso, releído en la API, la confirma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cargo = cargoRentaPendiente($e);

    // Sin su access token no se activa.
    $this->putJson('/api/v1/plataforma/pasarelas/mercadopago', ['activa' => true, 'modo' => 'test'], conPlataforma())
        ->assertUnprocessable()->assertJsonValidationErrors(['credenciales.access_token'], 'meta.errors');
    activarPasarelaDeRenta('mercadopago', ['access_token' => 'TEST-token-plataforma']);

    $renta = $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk();
    expect($renta->json('data.pasarelas'))->toBe(['mercadopago']);
    $monto = (int) $renta->json('data.cargos.0.monto_minor');

    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'mercadopago'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.checkout.url', 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=123-pref');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'checkout/preferences')
        && $r['external_reference'] === $cargo
        && str_ends_with((string) $r['notification_url'], '/webhooks/plataforma/mercadopago'));

    // Un aviso de un cobro aún pendiente (p. ej. un ticket de OXXO) no la paga.
    $this->pasarela->cargo = $cargo;
    $this->pasarela->monto = $monto / 100;
    $this->pasarela->estado = 'pending';
    $this->postJson('/api/v1/webhooks/plataforma/mercadopago?type=payment&data.id=987')->assertOk();
    expect(CargoRenta::query()->where('ulid', $cargo)->value('estado')?->value)->toBe('pendiente');

    // Aprobado en Mercado Pago: pagada.
    $this->pasarela->estado = 'approved';
    $this->postJson('/api/v1/webhooks/plataforma/mercadopago?type=payment&data.id=987')->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.cargos.0.estado', 'pagado');
    expect(CargoRenta::query()->where('ulid', $cargo)->value('metodo_pago'))->toBe('mercadopago');
});

it('si el aviso de Mercado Pago no llega, la conciliación encuentra el cobro aprobado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cargo = cargoRentaPendiente($e);
    activarPasarelaDeRenta('mercadopago', ['access_token' => 'TEST-token-plataforma']);
    $monto = (int) $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->json('data.cargos.0.monto_minor');
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'mercadopago'], conBearer($e['bearer']))->assertCreated();

    $this->pasarela->pagos = [['id' => 555, 'status' => 'approved', 'transaction_amount' => $monto / 100]];
    $this->travel(11)->minutes();
    $this->artisan('agendauno:conciliar-renta')->expectsOutputToContain('confirmados: 1')->assertSuccessful();

    expect(CargoRenta::query()->where('ulid', $cargo)->first(['estado', 'referencia_pago'])?->toArray())
        ->toMatchArray(['estado' => 'pagado', 'referencia_pago' => '555']);
});

it('el dueño paga con OpenPay; el aviso necesita el usuario y la contraseña de la plataforma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cargo = cargoRentaPendiente($e);
    activarPasarelaDeRenta('stripe', ['secret_key' => 'sk_test_plat']);
    activarPasarelaDeRenta('openpay', ['merchant_id' => 'm1', 'private_key' => 'sk_openpay', 'webhook_user' => 'agendauno', 'webhook_password' => 'clave-del-aviso']);

    // Con dos activas, el dueño elige.
    $renta = $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk();
    expect($renta->json('data.pasarelas'))->toBe(['stripe', 'openpay']);

    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'openpay'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.referencia', 'tr_renta')
        ->assertJsonPath('data.checkout.tipo', 'redirect');

    $this->pasarela->cargo = $cargo;
    $this->pasarela->monto = (int) $renta->json('data.cargos.0.monto_minor') / 100;
    $aviso = ['type' => 'charge.succeeded', 'transaction' => ['id' => 'tr_renta']];

    // Sin sus credenciales, no se acepta.
    $this->postJson('/api/v1/webhooks/plataforma/openpay', $aviso, ['Authorization' => 'Basic '.base64_encode('agendauno:otra')])
        ->assertUnauthorized();
    expect(CargoRenta::query()->where('ulid', $cargo)->value('estado')?->value)->toBe('pendiente');

    $this->postJson('/api/v1/webhooks/plataforma/openpay', $aviso, ['Authorization' => 'Basic '.base64_encode('agendauno:clave-del-aviso')])
        ->assertOk();
    expect(CargoRenta::query()->where('ulid', $cargo)->first(['estado', 'metodo_pago'])?->toArray())
        ->toMatchArray(['estado' => 'pagado', 'metodo_pago' => 'openpay']);
});
