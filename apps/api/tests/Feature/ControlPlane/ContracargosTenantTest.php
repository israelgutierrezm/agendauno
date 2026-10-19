<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Contracargos (ADR 0168): el banco del cliente disputa un pago en línea y la pasarela
| lo avisa. Se marca en el pago y va a «por conciliar»; ganado, se cierra solo; perdido,
| cuenta como devolución y el negocio decide qué hacer con lo que se compró.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->cobros = (object) ['mp' => [], 'op' => []];
    $cobros = $this->cobros;
    Http::fake(function (Request $request) use ($cobros) {
        $ruta = (string) parse_url($request->url(), PHP_URL_PATH);
        // Stripe: la sesión de Checkout y la búsqueda de la sesión de un cobro.
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/sessions')) {
            return Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);
        }
        if ($request->method() === 'GET' && str_ends_with($ruta, '/checkout/sessions')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(['data' => ($q['payment_intent'] ?? '') === 'pi_1' ? [['id' => 'cs_1']] : []]);
        }
        // Mercado Pago: la preferencia y sus cobros.
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/preferences')) {
            return Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-1']);
        }
        if (preg_match('#/v1/payments/(\d+)$#', $ruta, $m) === 1) {
            return Http::response($cobros->mp[$m[1]] ?? [], isset($cobros->mp[$m[1]]) ? 200 : 404);
        }
        if (preg_match('#/v1/chargebacks/(\w+)$#', $ruta, $m) === 1) {
            return Http::response(['id' => $m[1], 'payments' => [701]]);
        }
        // OpenPay: el cargo.
        if ($request->method() === 'POST' && str_ends_with($ruta, '/charges')) {
            $cobros->op['trx1'] = ['id' => 'trx1', 'status' => 'charge_pending', 'order_id' => $request['order_id'], 'amount' => 899.0];

            return Http::response(['id' => 'trx1', 'status' => 'charge_pending', 'payment_method' => [
                'type' => 'redirect', 'url' => 'https://sandbox-api.openpay.mx/v1/m1/charges/trx1/card_capture',
            ]]);
        }
        if (preg_match('#/charges/(trx\d+)$#', $ruta, $m) === 1) {
            return Http::response($cobros->op[$m[1]] ?? [], isset($cobros->op[$m[1]]) ? 200 : 404);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un alumno abre el pago en línea de un paquete con esa pasarela; devuelve el negocio
 * y el id del pago.
 *
 * @param  array<string, string>  $llaves
 * @return array{slug: string, bearer: string, pago: string}
 */
function pagoParaContracargo(string $proveedor, array $llaves): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/{$proveedor}", ['activa' => true, 'modo' => 'test', 'credenciales' => $llaves], conBearer($e['bearer']))->assertOk();
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", ['metodo' => 'tarjeta'], conBearer($a['bearer']))
        ->assertCreated()->json('data.pago');

    return [...$e, 'pago' => $pago];
}

/** @return array{pago: PagoTenant, incidencias: list<array{tipo: string, estado: string, detalle: string|null}>, devoluciones: int} */
function estadoDelContracargo(string $slug, string $pago): array
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), function () use ($pago): array {
        $modelo = PagoTenant::query()->where('ulid', $pago)->firstOrFail();

        return [
            'pago' => $modelo,
            'incidencias' => IncidenciaCobroTenant::query()->where('pago_id', $modelo->getKey())->orderBy('id')->get()
                ->map(fn (IncidenciaCobroTenant $i): array => ['tipo' => $i->tipo, 'estado' => $i->estado, 'detalle' => $i->detalle])->all(),
            'devoluciones' => ReembolsoTenant::query()->where('pago_id', $modelo->getKey())->where('estado', 'aprobado')->count(),
        ];
    });
}

it('Stripe: la disputa abre el contracargo en «por conciliar»; perdido, cuenta como devolución una sola vez', function (): void {
    $c = pagoParaContracargo('stripe', ['secret_key' => 'sk_test_x']);
    $url = "/api/v1/webhooks/tenant/{$c['slug']}/stripe";
    $this->postJson($url, ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']]])->assertOk();

    // La disputa trae el PaymentIntent; el pago se guardó con la sesión: se ubica.
    $disputa = ['id' => 'dp_1', 'amount' => 89900, 'currency' => 'mxn', 'reason' => 'fraudulent', 'payment_intent' => 'pi_1',
        'evidence_details' => ['due_by' => now()->addDays(7)->getTimestamp()]];
    $this->postJson($url, ['type' => 'charge.dispute.created', 'data' => ['object' => $disputa]])->assertOk();
    $this->postJson($url, ['type' => 'charge.dispute.created', 'data' => ['object' => $disputa]])->assertOk();

    $estado = estadoDelContracargo($c['slug'], $c['pago']);
    expect($estado['pago']->contracargo_estado)->toBe('abierto')
        ->and($estado['pago']->contracargo_vence_en)->not->toBeNull()
        ->and($estado['incidencias'])->toHaveCount(1)
        ->and($estado['incidencias'][0]['tipo'])->toBe('contracargo')
        ->and($estado['incidencias'][0]['detalle'])->toContain('Responde en Stripe');
    $bitacora = $this->getJson("/api/v1/app/{$c['slug']}/auditorias?accion=pago.contracargo", conBearer($c['bearer']))->json('data.0.descripcion');
    expect($bitacora)->toBe('El banco del cliente disputa un pago de $899.00 MXN');

    // Perdido: el dinero se devolvió al cliente (cuenta en caja), una sola vez.
    $cerrada = ['type' => 'charge.dispute.closed', 'data' => ['object' => [...$disputa, 'status' => 'lost']]];
    $this->postJson($url, $cerrada)->assertOk();
    $this->postJson($url, $cerrada)->assertOk();
    $estado = estadoDelContracargo($c['slug'], $c['pago']);
    expect($estado['pago']->contracargo_estado)->toBe('perdido')
        ->and($estado['pago']->estado->value)->toBe('reembolsado')
        ->and($estado['devoluciones'])->toBe(1)
        // Sigue abierta: el negocio decide qué hacer con lo que se compró.
        ->and($estado['incidencias'][0]['estado'])->toBe('abierta')
        ->and($estado['incidencias'][0]['detalle'])->toContain('Se perdió el contracargo');
    $movimientos = collect($this->getJson("/api/v1/app/{$c['slug']}/pagos/movimientos", conBearer($c['bearer']))->assertOk()->json('data'));
    expect($movimientos->firstWhere('tipo', 'devolucion')['monto_minor'] ?? null)->toBe(-89900);
});

it('Mercado Pago: el cobro «charged_back» abre el contracargo y «reimbursed» lo gana', function (): void {
    $c = pagoParaContracargo('mercadopago', ['access_token' => 'APP_USR-prueba']);
    $url = "/api/v1/webhooks/tenant/{$c['slug']}/mercadopago";
    $cobro = ['id' => 701, 'status' => 'approved', 'external_reference' => $c['pago'], 'transaction_amount' => 899.0];
    $this->cobros->mp['701'] = $cobro;
    $this->postJson("{$url}?type=payment&data_id=701")->assertOk();

    $this->cobros->mp['701'] = [...$cobro, 'status' => 'charged_back', 'status_detail' => 'in_process'];
    $this->postJson("{$url}?type=chargebacks&data_id=cb1")->assertOk();
    expect(estadoDelContracargo($c['slug'], $c['pago'])['pago']->contracargo_estado)->toBe('abierto');

    $this->cobros->mp['701'] = [...$cobro, 'status' => 'charged_back', 'status_detail' => 'reimbursed'];
    $this->postJson("{$url}?type=payment&data_id=701")->assertOk();
    $estado = estadoDelContracargo($c['slug'], $c['pago']);
    expect($estado['pago']->contracargo_estado)->toBe('ganado')
        ->and($estado['pago']->estado->value)->toBe('aprobado')
        ->and($estado['devoluciones'])->toBe(0)
        ->and($estado['incidencias'][0]['estado'])->toBe('resuelta');
});

it('OpenPay: un contracargo aceptado se pierde y cuenta como devolución', function (): void {
    $c = pagoParaContracargo('openpay', ['merchant_id' => 'm1', 'private_key' => 'sk_op', 'public_key' => 'pk_op']);
    $url = "/api/v1/webhooks/tenant/{$c['slug']}/openpay";
    $this->cobros->op['trx1']['status'] = 'completed';
    $this->postJson($url, ['type' => 'charge.succeeded', 'transaction' => ['id' => 'trx1']])->assertOk();
    expect(estadoDelContracargo($c['slug'], $c['pago'])['pago']->estado->value)->toBe('aprobado');

    $this->postJson($url, ['type' => 'chargeback.created', 'transaction' => ['id' => 'trx1']])->assertOk();
    expect(estadoDelContracargo($c['slug'], $c['pago'])['pago']->contracargo_estado)->toBe('abierto');
    $this->postJson($url, ['type' => 'chargeback.accepted', 'transaction' => ['id' => 'trx1']])->assertOk();
    $estado = estadoDelContracargo($c['slug'], $c['pago']);
    expect($estado['pago']->contracargo_estado)->toBe('perdido')
        ->and($estado['devoluciones'])->toBe(1);
});
