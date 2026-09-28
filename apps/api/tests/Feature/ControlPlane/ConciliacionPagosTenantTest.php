<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Conciliación de pagos cuyo aviso (webhook) no llegó: agendauno:conciliar-pagos le
| pregunta a la pasarela cómo va cada intento y aplica lo que habría aplicado el aviso.
| Nunca cobra ni cancela; si el aviso llega después, no se duplica nada.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));

    // Stripe falso: cada sesión guarda su estado; cuenta las consultas.
    $this->stripe = (object) ['sesiones' => [], 'n' => 0, 'consultas' => 0, 'caida' => false];
    // OpenPay falso: el estado de cada cargo.
    $this->op = (object) ['cargos' => [], 'n' => 0];
    // Mercado Pago falso: los cobros por referencia.
    $this->mp = (object) ['cobros' => []];
    $stripe = $this->stripe;
    $op = $this->op;
    $mp = $this->mp;

    Http::fake(function (Request $request) use ($stripe, $op, $mp) {
        $ruta = (string) parse_url($request->url(), PHP_URL_PATH);

        // Stripe
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/sessions')) {
            $stripe->n++;
            $id = 'cs_'.$stripe->n;
            $stripe->sesiones[$id] = ['status' => 'open', 'payment_status' => 'unpaid', 'intent' => 'requires_payment_method'];

            return Http::response(['id' => $id, 'url' => "https://checkout.stripe.com/c/pay/{$id}"]);
        }
        if (preg_match('#/checkout/sessions/(cs_\d+)/expire$#', $ruta, $m) === 1) {
            if (($stripe->sesiones[$m[1]]['status'] ?? '') !== 'open') {
                return Http::response(['error' => ['message' => 'not open']], 400);
            }
            $stripe->sesiones[$m[1]]['status'] = 'expired';

            return Http::response(['id' => $m[1], 'status' => 'expired']);
        }
        if (preg_match('#/checkout/sessions/(cs_\d+)$#', $ruta, $m) === 1) {
            $stripe->consultas++;
            if ($stripe->caida) {
                return Http::response(['error' => ['message' => 'boom']], 500);
            }
            $s = $stripe->sesiones[$m[1]] ?? null;

            return $s === null ? Http::response([], 404) : Http::response([
                'id' => $m[1], 'status' => $s['status'], 'payment_status' => $s['payment_status'],
                'payment_intent' => ['id' => 'pi_de_'.$m[1], 'status' => $s['intent']],
            ]);
        }

        // OpenPay
        if ($request->method() === 'POST' && str_ends_with($ruta, '/charges')) {
            $op->n++;
            $id = 'trx'.$op->n;
            $op->cargos[$id] = ['id' => $id, 'status' => 'in_progress', 'order_id' => $request['order_id']];

            return Http::response($request['method'] === 'store'
                ? ['id' => $id, 'status' => 'in_progress', 'payment_method' => [
                    'type' => 'store', 'reference' => '000020TRN', 'barcode_url' => 'https://sandbox-api.openpay.mx/barcode/000020TRN',
                ]]
                : ['id' => $id, 'status' => 'charge_pending', 'payment_method' => [
                    'type' => 'redirect', 'url' => "https://sandbox-api.openpay.mx/v1/m123/charges/{$id}/card_capture",
                ]]);
        }
        if (preg_match('#/charges/(trx\d+)$#', $ruta, $m) === 1) {
            return Http::response($op->cargos[$m[1]] ?? [], isset($op->cargos[$m[1]]) ? 200 : 404);
        }

        // Mercado Pago
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/preferences')) {
            return Http::response(['id' => '111-pref', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=111-pref']);
        }
        if (str_ends_with($ruta, '/v1/payments/search')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            return Http::response(['results' => array_values(array_filter(
                $mp->cobros,
                fn (array $cobro): bool => $cobro['external_reference'] === ($consulta['external_reference'] ?? null),
            ))]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio con la pasarela dada activa y un alumno con un paquete por pagar.
 *
 * @param  array<string, string>  $llaves
 * @return array{slug: string, bearer: string, alumno: string, orden: string}
 */
function compraPorConciliar(string $proveedor, array $llaves): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/{$proveedor}", [
        'activa' => true, 'modo' => 'test', 'credenciales' => $llaves,
    ], conBearer($e['bearer']))->assertOk();
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    return [...$e, 'alumno' => $a['bearer'], 'orden' => $orden];
}

/**
 * El alumno abre el pago en línea; devuelve el id del pago.
 *
 * @param  array{slug: string, alumno: string, orden: string}  $c
 */
function abrirPagoPorConciliar(array $c, string $metodo = 'tarjeta'): string
{
    return (string) test()->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", ['metodo' => $metodo], conBearer($c['alumno']))
        ->assertCreated()->json('data.pago');
}

function enNegocioPorConciliar(string $slug, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), $fn);
}

function pagoPorConciliar(string $slug, string $ulid): PagoTenant
{
    return enNegocioPorConciliar($slug, fn (): PagoTenant => PagoTenant::query()->where('ulid', $ulid)->firstOrFail());
}

function estadoOrdenPorConciliar(string $slug, string $orden): string
{
    return enNegocioPorConciliar($slug, fn (): string => OrdenTenant::query()->where('ulid', $orden)->firstOrFail()->estado->value);
}

it('confirma un cobro de Stripe cuyo aviso no llegó y el aviso tardío ya no duplica nada', function (): void {
    $c = compraPorConciliar('stripe', ['secret_key' => 'sk_test_x']);
    $pago = abrirPagoPorConciliar($c);
    $this->stripe->sesiones['cs_1'] = ['status' => 'complete', 'payment_status' => 'paid', 'intent' => 'succeeded'];

    // Dentro de los minutos de gracia no se pregunta: el aviso normal llega en segundos.
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect($this->stripe->consultas)->toBe(0)
        ->and(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pendiente');

    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')
        ->expectsOutputToContain('Cobros confirmados: 1.')
        ->assertSuccessful();

    expect(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pagada')
        ->and(pagoPorConciliar($c['slug'], $pago)->estado->value)->toBe('aprobado');
    $bitacora = enNegocioPorConciliar($c['slug'], fn () => AuditoriaTenant::query()->where('accion', 'pago.conciliado')->count());
    expect($bitacora)->toBe(1)
        ->and(AlertaPlataforma::query()->where('tipo', 'aviso_de_pago_perdido')->value('clave'))->toBe('estudio-a:stripe');

    // El aviso llega por fin: no pasa nada más.
    $this->postJson("/api/v1/webhooks/tenant/{$c['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();

    enNegocioPorConciliar($c['slug'], function (): void {
        expect(PagoTenant::query()->where('estado', 'aprobado')->count())->toBe(1)
            ->and(IncidenciaCobroTenant::query()->count())->toBe(0)
            // El paquete se entregó una sola vez.
            ->and(AcuerdoTenant::query()->count())->toBe(1);
    });
    expect($this->stripe->consultas)->toBe(1);
});

it('si la sesión venció, cierra el intento y la compra sigue por pagar', function (): void {
    $c = compraPorConciliar('stripe', ['secret_key' => 'sk_test_x']);
    $pago = abrirPagoPorConciliar($c);
    $this->stripe->sesiones['cs_1']['status'] = 'expired';

    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->expectsOutputToContain('Cerrados: 1.')->assertSuccessful();

    expect(pagoPorConciliar($c['slug'], $pago)->estado->value)->toBe('rechazado')
        ->and(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pendiente');

    // Ya cerrado con la palabra de Stripe: no se vuelve a preguntar.
    $this->travel(3)->hours();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect($this->stripe->consultas)->toBe(1);
});

it('un pago en tienda en espera se sigue preguntando, cada vez más espaciado, y la caída de la pasarela no rompe nada', function (): void {
    $c = compraPorConciliar('stripe', ['secret_key' => 'sk_test_x']);
    $pago = abrirPagoPorConciliar($c, 'oxxo');
    $this->stripe->sesiones['cs_1'] = ['status' => 'complete', 'payment_status' => 'unpaid', 'intent' => 'requires_action'];

    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->expectsOutputToContain('En espera: 1.')->assertSuccessful();
    // La primera hora, en cada vuelta.
    $this->travel(5)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect($this->stripe->consultas)->toBe(2);

    // Pasada la hora, cada 30 minutos.
    $this->travel(2)->hours();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    $this->travel(5)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect($this->stripe->consultas)->toBe(3);

    // Stripe no responde: se anota y se reintenta después.
    $this->stripe->caida = true;
    $this->travel(31)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect(pagoPorConciliar($c['slug'], $pago)->estado->value)->toBe('pendiente');

    // Se pagó en la tienda y el aviso se perdió.
    $this->stripe->caida = false;
    $this->stripe->sesiones['cs_1'] = ['status' => 'complete', 'payment_status' => 'paid', 'intent' => 'succeeded'];
    $this->travel(31)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();
    expect(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pagada');
});

it('un pago en tienda de OpenPay cerrado de este lado al reintentar se cobra después y se reconoce', function (): void {
    $c = compraPorConciliar('openpay', [
        'merchant_id' => 'm123', 'private_key' => 'sk_prueba', 'public_key' => 'pk_prueba',
        'webhook_user' => 'agendauno', 'webhook_password' => 'clave-webhook',
    ]);
    $enTienda = abrirPagoPorConciliar($c, 'oxxo');
    // Cambia de opinión y abre el pago con tarjeta: el de tienda se cierra de este
    // lado (OpenPay no lo cancela), pero sigue pagable hasta que vence.
    $conTarjeta = abrirPagoPorConciliar($c, 'tarjeta');
    expect(pagoPorConciliar($c['slug'], $enTienda)->estado->value)->toBe('rechazado')
        ->and(pagoPorConciliar($c['slug'], $enTienda)->cerrado_sin_confirmar)->toBeTrue();

    // Lo paga en la tienda; el aviso no llega.
    $this->op->cargos['trx1']['status'] = 'completed';
    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();

    expect(pagoPorConciliar($c['slug'], $enTienda)->estado->value)->toBe('aprobado')
        ->and(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pagada')
        // El otro intento queda cerrado: no se puede pagar dos veces.
        ->and(pagoPorConciliar($c['slug'], $conTarjeta)->estado->value)->toBe('rechazado');
});

it('con Mercado Pago confirma con el id de su cobro', function (): void {
    $c = compraPorConciliar('mercadopago', ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp']);
    $pago = abrirPagoPorConciliar($c);
    $this->mp->cobros['501'] = ['id' => 501, 'status' => 'rejected', 'external_reference' => $pago];
    $this->mp->cobros['502'] = ['id' => 502, 'status' => 'approved', 'external_reference' => $pago];

    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();

    $conciliado = pagoPorConciliar($c['slug'], $pago);
    expect($conciliado->estado->value)->toBe('aprobado')
        ->and($conciliado->referencia_externa)->toBe('502')
        ->and(estadoOrdenPorConciliar($c['slug'], $c['orden']))->toBe('pagada');
});

it('la cita pagada cuyo aviso se perdió queda confirmada antes de que venza el apartado', function (): void {
    $this->travelTo('2026-10-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Bea', 'email' => 'bea@correo.mx',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ])->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", ['orden_id' => $cita['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta'])
        ->assertCreated();

    // Pagó al momento, pero el aviso de Stripe no llegó.
    $this->stripe->sesiones['cs_1'] = ['status' => 'complete', 'payment_status' => 'paid', 'intent' => 'succeeded'];
    $this->travel(6)->minutes();
    $this->artisan('agendauno:conciliar-pagos')->assertSuccessful();

    // Pasa la media hora del apartado: la cita ya estaba confirmada y no se libera.
    $this->travel(30)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    $estado = enNegocioPorConciliar($e['slug'], fn () => ReservaTenant::query()->where('ulid', $cita['reserva'])->firstOrFail()->estado->value);
    expect($estado)->toBe('confirmada');
});
