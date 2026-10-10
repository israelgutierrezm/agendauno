<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Domiciliación de la renta (ADR 0107): el dueño guarda su tarjeta en Stripe (modo
| `setup`) y cada cargo se cobra solo (`off_session`). Un rechazo se reintenta a los 3
| y a los 7 días de emitido; si la tarjeta pide autenticación, ya no.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * La sesión de Stripe en que el dueño guardó su tarjeta.
 *
 * @return array<string, mixed>
 */
function sesionTarjetaGuardada(string $estudioUlid): array
{
    return [
        'id' => 'cs_setup_1', 'mode' => 'setup', 'customer' => 'cus_dueno',
        'metadata' => ['estudio' => $estudioUlid, 'tipo' => 'domiciliacion_renta'],
        'setup_intent' => ['payment_method' => ['id' => 'pm_visa', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => 2031]]],
    ];
}

/**
 * Un negocio con su renta del mes pendiente y la tarjeta ya domiciliada.
 *
 * @return array{e: array{slug: string, bearer: string}, cargo: CargoRenta}
 */
function rentaConTarjeta(): array
{
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $ulid = cargoRentaPendiente($e);
    Estudio::query()->where('slug', $e['slug'])->update([
        'stripe_cliente_id' => 'cus_dueno', 'domiciliacion_metodo' => 'pm_visa', 'tarjeta_marca' => 'visa', 'tarjeta_ultimos4' => '4242',
    ]);

    return ['e' => $e, 'cargo' => CargoRenta::query()->where('ulid', $ulid)->sole()];
}

it('el dueño guarda su tarjeta en Stripe y al volver queda domiciliada', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $ulid = (string) Estudio::query()->where('slug', $e['slug'])->value('ulid');
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_dueno']),
        'api.stripe.com/v1/checkout/sessions/cs_setup_1*' => Http::response(sesionTarjetaGuardada($ulid)),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_setup_1']),
    ]);

    $this->postJson("/api/v1/app/{$e['slug']}/renta/tarjeta", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_setup_1');
    Http::assertSent(fn (PeticionHttp $r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['mode'] === 'setup' && $r['customer'] === 'cus_dueno'
        && str_contains((string) $r['success_url'], 'tarjeta=exito&sesion={CHECKOUT_SESSION_ID}'));

    $this->postJson("/api/v1/app/{$e['slug']}/renta/tarjeta/confirmar", ['sesion' => 'cs_setup_1'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tarjeta.marca', 'visa')
        ->assertJsonPath('data.tarjeta.ultimos4', '4242')
        ->assertJsonPath('data.tarjeta.vence', '09/2031');

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tarjeta.ultimos4', '4242')->assertJsonPath('data.domiciliacion_posible', true);
    // La sesión de otro negocio no se acepta.
    $otro = estudioConSesion('yoga-b', 'dueno@yoga.mx');
    $this->postJson("/api/v1/app/{$otro['slug']}/renta/tarjeta/confirmar", ['sesion' => 'cs_setup_1'], conBearer($otro['bearer']))
        ->assertStatus(422);
});

it('el aviso de Stripe de la sesión de guardado también deja la tarjeta', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Estudio::query()->where('slug', $e['slug'])->update(['stripe_cliente_id' => 'cus_dueno']);
    $ulid = (string) Estudio::query()->where('slug', $e['slug'])->value('ulid');
    Http::fake(['api.stripe.com/v1/checkout/sessions/cs_setup_1*' => Http::response(sesionTarjetaGuardada($ulid))]);

    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_setup_1', 'mode' => 'setup', 'payment_status' => 'no_payment_required']],
    ])->assertOk();

    expect(Estudio::query()->where('slug', $e['slug'])->value('domiciliacion_metodo'))->toBe('pm_visa');
});

it('la renta pendiente se cobra sola a la tarjeta domiciliada', function (): void {
    ['e' => $e, 'cargo' => $cargo] = rentaConTarjeta();
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_renta', 'status' => 'succeeded'])]);

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();

    expect($cargo->refresh())
        ->estado->value->toBe('pagado')
        ->referencia_pago->toBe('pi_renta');
    Http::assertSent(fn (PeticionHttp $r): bool => (string) $r['amount'] === '149900' && $r['currency'] === 'mxn'
        && $r['off_session'] === 'true' && $r['payment_method'] === 'pm_visa' && $r['customer'] === 'cus_dueno'
        && $r->header('Idempotency-Key')[0] === "agendauno-renta-{$cargo->ulid}-0");

    // Ya pagada: no se vuelve a cobrar.
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    Http::assertSentCount(1);
});

it('un rechazo se reintenta a los 3 y a los 7 días de emitido, y luego ya no', function (): void {
    ['cargo' => $cargo] = rentaConTarjeta();
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'payment_intent' => ['id' => 'pi_x']],
    ], 402)]);
    $emitido = $cargo->emitido_en;

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh())
        ->intentos_automaticos->toBe(1)
        ->error_cobro->toBe('insufficient_funds')
        ->and($cargo->proximo_intento_en?->toDateString())->toBe($emitido?->copy()->addDays(3)->toDateString());

    // Antes del reintento no se insiste.
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    Http::assertSentCount(1);

    $this->travelTo($emitido?->copy()->addDays(3)->addHour());
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh()->intentos_automaticos)->toBe(2)
        ->and($cargo->proximo_intento_en?->toDateString())->toBe($emitido?->copy()->addDays(7)->toDateString());

    $this->travelTo($emitido?->copy()->addDays(7)->addHour());
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh()->intentos_automaticos)->toBe(3)->and($cargo->proximo_intento_en)->toBeNull();

    $this->travelTo($emitido?->copy()->addDays(20));
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    Http::assertSentCount(3);
    expect($cargo->refresh()->estado->value)->toBe('pendiente');
});

it('si la tarjeta pide autenticación ya no se reintenta: el dueño paga a mano', function (): void {
    ['e' => $e, 'cargo' => $cargo] = rentaConTarjeta();
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'error' => ['code' => 'authentication_required', 'payment_intent' => ['id' => 'pi_3ds']],
        ], 402),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_manual', 'url' => 'https://checkout.stripe.com/c/pay/cs_manual']),
    ]);

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh())->error_cobro->toBe('authentication_required')->proximo_intento_en->toBeNull();

    $this->travelTo(now()->addDays(10));
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    Http::assertSentCount(1);

    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo->ulid}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_manual');
});

it('el dueño quita su tarjeta: se desliga en Stripe y ya no se cobra sola', function (): void {
    ['e' => $e, 'cargo' => $cargo] = rentaConTarjeta();
    Http::fake(['api.stripe.com/*' => Http::response([])]);

    $this->deleteJson("/api/v1/app/{$e['slug']}/renta/tarjeta", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tarjeta', null);
    Http::assertSent(fn (PeticionHttp $r): bool => str_ends_with($r->url(), '/payment_methods/pm_visa/detach'));

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh()->estado->value)->toBe('pendiente');
    Http::assertNotSent(fn (PeticionHttp $r): bool => str_contains($r->url(), '/payment_intents'));
});

it('un cobro que quedó en proceso y después falló cuenta como rechazo: el reintento va con otra llave', function (): void {
    ['cargo' => $cargo] = rentaConTarjeta();
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_lento*' => Http::response(['id' => 'pi_lento', 'status' => 'requires_payment_method']),
        'api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(['id' => 'pi_lento', 'status' => 'processing'])
            ->push(['id' => 'pi_nuevo', 'status' => 'succeeded']),
    ]);
    $emitido = $cargo->emitido_en;

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh())->referencia_pago->toBe('pi_lento')->intentos_automaticos->toBe(0);

    // La conciliación encuentra que falló: cuenta como un rechazo, con su reintento.
    $this->travel(15)->minutes();
    $this->artisan('agendauno:conciliar-renta')->assertSuccessful();
    expect($cargo->refresh())
        ->referencia_pago->toBeNull()
        ->intentos_automaticos->toBe(1)
        ->error_cobro->toBe('rechazado')
        ->and($cargo->proximo_intento_en?->toDateString())->toBe($emitido?->copy()->addDays(3)->toDateString());

    // Antes del reintento no se insiste; al llegar, va con otra llave (Stripe no repite la respuesta anterior).
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    $this->travelTo($emitido?->copy()->addDays(3)->addHour());
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh())->estado->value->toBe('pagado')->referencia_pago->toBe('pi_nuevo');
    $llaves = Http::recorded(fn (PeticionHttp $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/payment_intents'))
        ->map(fn (array $par): string => $par[0]->header('Idempotency-Key')[0])->values()->all();
    expect($llaves)->toBe(["agendauno-renta-{$cargo->ulid}-0", "agendauno-renta-{$cargo->ulid}-1"]);
});

it('el aviso de Stripe de un cobro en proceso que falló también lo cuenta como rechazo', function (): void {
    ['cargo' => $cargo] = rentaConTarjeta();
    $cargo->update(['metodo_pago' => 'stripe', 'referencia_pago' => 'pi_lento']);

    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_lento', 'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds']]],
    ])->assertOk();

    expect($cargo->refresh())
        ->referencia_pago->toBeNull()
        ->intentos_automaticos->toBe(1)
        ->error_cobro->toBe('insufficient_funds')
        ->estado->value->toBe('pendiente');
});

it('al guardar otra tarjeta, lo que la anterior no pagó se vuelve a intentar con ella', function (): void {
    ['e' => $e, 'cargo' => $cargo] = rentaConTarjeta();
    $sesion = sesionTarjetaGuardada((string) Estudio::query()->where('slug', $e['slug'])->value('ulid'));
    $sesion['setup_intent']['payment_method']['id'] = 'pm_master';
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(['error' => ['code' => 'authentication_required', 'payment_intent' => ['id' => 'pi_3ds']]], 402)
            ->push(['id' => 'pi_master', 'status' => 'succeeded']),
        'api.stripe.com/v1/checkout/sessions/cs_setup_1*' => Http::response($sesion),
        'api.stripe.com/*' => Http::response([]),
    ]);

    // La tarjeta guardada pide autenticación: ya no se reintenta sola.
    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh())->error_cobro->toBe('authentication_required')->proximo_intento_en->toBeNull();

    $this->postJson("/api/v1/app/{$e['slug']}/renta/tarjeta/confirmar", ['sesion' => 'cs_setup_1'], conBearer($e['bearer']))->assertOk();
    expect($cargo->refresh())->error_cobro->toBeNull()->proximo_intento_en->not->toBeNull();

    $this->artisan('agendauno:cobrar-renta-domiciliada')->assertSuccessful();
    expect($cargo->refresh()->estado->value)->toBe('pagado');
    Http::assertSent(fn (PeticionHttp $r): bool => str_ends_with($r->url(), '/payment_intents')
        && $r['payment_method'] === 'pm_master' && $r->header('Idempotency-Key')[0] === "agendauno-renta-{$cargo->ulid}-1");
});

it('si Stripe cobró pero el cargo no guardó la referencia, el aviso lo confirma por su metadata, una sola vez', function (): void {
    ['cargo' => $cargo] = rentaConTarjeta();
    $aviso = fn (int $monto) => $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_huerfano', 'amount_received' => $monto, 'metadata' => ['cargo_renta' => $cargo->ulid]]],
    ])->assertOk();

    // Con otro importe no se aplica.
    $aviso(100);
    expect($cargo->refresh()->estado->value)->toBe('pendiente');

    $aviso($cargo->monto_minor);
    expect($cargo->refresh())->estado->value->toBe('pagado')->referencia_pago->toBe('pi_huerfano');
    $pagadoEn = $cargo->pagado_en?->toIso8601String();

    $this->travel(1)->hours();
    $aviso($cargo->monto_minor);
    expect($cargo->refresh()->pagado_en?->toIso8601String())->toBe($pagadoEn);
});
