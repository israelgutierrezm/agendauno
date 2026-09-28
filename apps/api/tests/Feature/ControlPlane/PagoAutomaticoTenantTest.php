<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Pago automático (domiciliación) con Stripe: el alumno autoriza su tarjeta en la
| página de Stripe (nunca la captura el sistema) y cada renovación se cobra sola, sin
| que esté presente. Un rechazo del banco abre la mora con el motivo; si Stripe no
| responde no es culpa del alumno y el reintento no cobra dos veces.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));

    // Stripe falso y configurable por prueba.
    $this->stripe = (object) [
        'sesiones' => 0,
        'modo' => [],          // cs_N => 'setup' | 'payment'
        'cargo' => 'exito',    // exito | fondos | caido
        'cargos' => 0,
    ];
    $stripe = $this->stripe;
    Http::fake(function (Request $request) use ($stripe) {
        $url = $request->url();

        if (str_ends_with($url, '/customers')) {
            return Http::response(['id' => 'cus_1']);
        }
        if ($request->method() === 'POST' && str_ends_with($url, '/checkout/sessions')) {
            $stripe->sesiones++;
            $id = 'cs_'.$stripe->sesiones;
            $stripe->modo[$id] = (string) ($request->data()['mode'] ?? 'payment');

            return Http::response(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id]);
        }
        if ($request->method() === 'GET' && preg_match('#/checkout/sessions/(cs_\d+)#', $url, $m) === 1) {
            $metodo = ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030]];
            $modo = $stripe->modo[$m[1]] ?? 'payment';

            return Http::response([
                'id' => $m[1], 'mode' => $modo, 'customer' => 'cus_1',
                'metadata' => $stripe->metadata ?? [],
                'setup_intent' => $modo === 'setup' ? ['id' => 'seti_1', 'payment_method' => $metodo] : null,
                'payment_intent' => $modo === 'payment' ? ['id' => 'pi_compra', 'setup_future_usage' => 'off_session', 'payment_method' => $metodo] : null,
            ]);
        }
        if (str_ends_with($url, '/payment_intents')) {
            $stripe->cargos++;

            return match ($stripe->cargo) {
                'caido' => Http::response(['error' => ['message' => 'Server error']], 500),
                'fondos' => Http::response(['error' => [
                    'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
                    'payment_intent' => ['id' => 'pi_rechazo_'.$stripe->cargos, 'status' => 'requires_payment_method'],
                ]], 402),
                default => Http::response(['id' => 'pi_auto_'.$stripe->cargos, 'status' => 'succeeded']),
            };
        }

        return Http::response(['status' => 'expired']);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function enEstudioPago(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Estudio que cobra con Stripe, con una membresía mensual y un alumno que ya la
 * tiene (pagada en recepción).
 *
 * @return array{slug: string, bearer: string, alumno: string, producto: string, acuerdo: string}
 */
function alumnoConMembresia(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
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

    return [...$e, 'alumno' => $a['bearer'], 'producto' => $producto, 'acuerdo' => $acuerdo];
}

/**
 * La membresía vence hoy y corre el cobro de renovaciones.
 *
 * @param  array{slug: string, acuerdo: string}  $m
 */
function renovarHoy(array $m): string
{
    return enEstudioPago($m, function () use ($m): string {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        if ($acuerdo->proxima_cobro_en?->isFuture()) {
            $acuerdo->update(['proxima_cobro_en' => now()->toDateString()]);
        }

        return app(CobroRecurrenteTenant::class)->renovar($acuerdo->refresh(), 'stripe');
    });
}

/**
 * El alumno autoriza su tarjeta en Stripe (modo setup) para la membresía.
 *
 * @param  array{slug: string, alumno: string, acuerdo: string}  $m
 */
function autorizarTarjeta(array $m): void
{
    $r = test()->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))
        ->assertOk()->json('data');
    expect($r['estado'])->toBe('redirect')->and($r['checkout']['url'])->toStartWith('https://checkout.stripe.com/');

    test()->stripe->metadata = ['acuerdos' => $m['acuerdo']];
    test()->postJson("/api/v1/webhooks/tenant/{$m['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_'.test()->stripe->sesiones, 'mode' => 'setup', 'payment_status' => 'no_payment_required']],
    ])->assertOk();
}

it('el alumno autoriza su tarjeta y la renovación se cobra sola', function (): void {
    $m = alumnoConMembresia();

    $antes = $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))->assertOk()->json('data');
    expect($antes['disponible'])->toBeTrue()
        ->and($antes['tarjeta'])->toBeNull()
        ->and($antes['membresias'][0]['automatico'])->toBeFalse();

    autorizarTarjeta($m);

    // La sesión de Stripe es para guardar la tarjeta (sin cobrar), ligada a su cliente.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['mode'] === 'setup' && $r['customer'] === 'cus_1' && $r['metadata']['acuerdos'] === $m['acuerdo']);

    $despues = $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))->assertOk()->json('data');
    expect($despues['tarjeta'])->toBe(['marca' => 'visa', 'ultimos4' => '4242', 'expira' => '08/30'])
        ->and($despues['membresias'][0]['automatico'])->toBeTrue();

    expect(renovarHoy($m))->toBe('cobrado');

    // Cargo sin el alumno presente, con idempotencia por orden e intento.
    $orden = (string) enEstudioPago($m, fn () => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->value('ulid'));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment_intents')
        && $r['off_session'] === 'true' && $r['confirm'] === 'true' && $r['customer'] === 'cus_1'
        && $r['payment_method'] === 'pm_1' && $r['amount'] === 129900
        && $r->header('Idempotency-Key')[0] === "domiciliacion_{$orden}_1");

    $estado = enEstudioPago($m, fn (): array => [
        'proxima' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString(),
        'orden' => OrdenTenant::query()->where('ulid', $orden)->firstOrFail()->estado->value,
        'pago' => PagoTenant::query()->whereNotNull('domiciliacion_id')->firstOrFail()->only(['estado', 'referencia_externa']),
        'mora' => ProcesoDunningTenant::query()->count(),
    ]);
    expect($estado['proxima'])->toBe(now()->addMonth()->toDateString())
        ->and($estado['orden'])->toBe('pagada')
        ->and($estado['pago']['referencia_externa'])->toBe('pi_auto_1')
        ->and($estado['mora'])->toBe(0);
});

it('otra membresía usa la tarjeta ya autorizada sin volver a Stripe', function (): void {
    $m = alumnoConMembresia();
    autorizarTarjeta($m);

    $orden = (string) $this->postJson("/api/v1/app/{$m['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $m['producto'], 'cantidad' => 1]],
    ], conBearer($m['alumno']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$m['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($m['bearer']))->assertOk();
    $segunda = collect($this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))->json('data.membresias'))
        ->firstWhere('automatico', false)['id'];

    $sesiones = $this->stripe->sesiones;
    $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$segunda}", [], conBearer($m['alumno']))
        ->assertOk()->assertJsonPath('data.estado', 'activa')->assertJsonPath('data.checkout', null);
    expect($this->stripe->sesiones)->toBe($sesiones);
});

it('si el banco rechaza el cargo automático, entra la mora con el motivo y el reintento vuelve a la tarjeta', function (): void {
    $m = alumnoConMembresia();
    autorizarTarjeta($m);

    $this->stripe->cargo = 'fondos';
    expect(renovarHoy($m))->toBe('fallido');

    $estado = enEstudioPago($m, fn (): array => [
        'mora' => ProcesoDunningTenant::query()->firstOrFail()->only(['estado', 'ultimo_motivo']),
        'proxima' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString(),
        'aviso' => EventoOutboxTenant::query()->where('tipo', 'cobro.fallido')->count(),
    ]);
    expect($estado['mora']['estado']->value)->toBe('en_mora')
        ->and($estado['mora']['ultimo_motivo'])->toContain('Visa terminación 4242')->toContain('no tiene fondos suficientes')
        ->and($estado['proxima'])->toBe(now()->toDateString())
        ->and($estado['aviso'])->toBe(1);
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.error', 'La tarjeta no tiene fondos suficientes.');

    // Al reintento ya hay fondos: se cobra, se regulariza y se limpia el error.
    $this->stripe->cargo = 'exito';
    $this->travel(1)->days();
    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();

    $orden = (string) enEstudioPago($m, fn () => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->value('ulid'));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment_intents')
        && $r->header('Idempotency-Key')[0] === "domiciliacion_{$orden}_2");
    $final = enEstudioPago($m, fn (): array => [
        'mora' => ProcesoDunningTenant::query()->firstOrFail()->estado->value,
        'error' => DomiciliacionTenant::query()->firstOrFail()->ultimo_error,
    ]);
    expect($final['mora'])->toBe('regularizado')->and($final['error'])->toBeNull();
});

it('si Stripe no responde, no hay mora y el reintento usa la misma llave (no cobra dos veces)', function (): void {
    $m = alumnoConMembresia();
    autorizarTarjeta($m);

    $this->stripe->cargo = 'caido';
    expect(renovarHoy($m))->toBe('fallido');
    expect(enEstudioPago($m, fn (): array => [ProcesoDunningTenant::query()->count(), PagoTenant::query()->whereNotNull('domiciliacion_id')->count()]))
        ->toBe([0, 0]);

    $this->stripe->cargo = 'exito';
    expect(renovarHoy($m))->toBe('cobrado');

    $orden = (string) enEstudioPago($m, fn () => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->value('ulid'));
    $llaves = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/payment_intents'))
        ->map(fn (array $par): string => $par[0]->header('Idempotency-Key')[0])->unique()->values()->all();
    expect($llaves)->toBe(["domiciliacion_{$orden}_1"]);
});

it('al pagar una membresía se puede dejar el pago automático activado', function (): void {
    $m = alumnoConMembresia();

    $orden = (string) $this->postJson("/api/v1/app/{$m['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $m['producto'], 'cantidad' => 1]],
    ], conBearer($m['alumno']))->assertCreated()->assertJsonPath('data.recurrente', true)->json('data.id');

    $this->postJson("/api/v1/app/{$m['slug']}/mi/ordenes/{$orden}/cobrar", [
        'metodo' => 'oxxo', 'domiciliar' => true,
    ], conBearer($m['alumno']))->assertCreated();

    // Solo tarjeta (OXXO no se domicilia), con su cliente y autorizada para después.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['mode'] === 'payment' && $r['customer'] === 'cus_1'
        && $r['payment_method_types'] === ['card']
        && $r['payment_intent_data']['setup_future_usage'] === 'off_session');

    $this->postJson("/api/v1/webhooks/tenant/{$m['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid']],
    ])->assertOk();

    $membresias = collect($this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))->json('data.membresias'));
    expect($membresias)->toHaveCount(2)
        ->and($membresias->where('automatico', true))->toHaveCount(1);
});

it('una compra que no se renueva no admite pago automático', function (): void {
    $m = alumnoConMembresia();
    $pack = crearPackTenant($m, 8000);

    $orden = (string) $this->postJson("/api/v1/app/{$m['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($m['alumno']))->assertCreated()->assertJsonPath('data.recurrente', false)->json('data.id');

    $this->postJson("/api/v1/app/{$m['slug']}/mi/ordenes/{$orden}/cobrar", ['domiciliar' => true], conBearer($m['alumno']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('domiciliar', 'meta.errors');
});

it('quitar el pago automático desliga la tarjeta y la renovación vuelve a avisarse', function (): void {
    $m = alumnoConMembresia();
    autorizarTarjeta($m);

    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment_methods/pm_1/detach'));

    // Sin tarjeta: la renovación abre la página de pago y se avisa al alumno.
    expect(renovarHoy($m))->toBe('pendiente');
    expect($this->stripe->cargos)->toBe(0);
});

it('el alumno solo puede domiciliar sus propias membresías', function (): void {
    $m = alumnoConMembresia();
    $otro = alumnoConSesion($m, 'Beto', 'beto@correo.mx');

    $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($otro['bearer']))->assertNotFound();
    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($otro['bearer']))->assertNotFound();
});

it('el negocio ve quién paga en automático, lo invita a activarlo o se lo quita', function (): void {
    $m = alumnoConMembresia();

    $this->postJson("/api/v1/app/{$m['slug']}/suscripciones/{$m['acuerdo']}/pago-automatico/solicitar", [], conBearer($m['bearer']))
        ->assertOk();
    $invitacion = enEstudioPago($m, fn () => EventoOutboxTenant::query()->where('tipo', 'pago_automatico.solicitado')->firstOrFail()->payload);
    expect($invitacion['producto'])->toBe('Mensualidad')->and($invitacion['enlace'])->toContain('/entrar?estudio=estudio-a');

    autorizarTarjeta($m);
    $this->getJson("/api/v1/app/{$m['slug']}/suscripciones", conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('pago_automatico_disponible', true)
        ->assertJsonPath('data.0.pago_automatico.ultimos4', '4242');

    $this->deleteJson("/api/v1/app/{$m['slug']}/suscripciones/{$m['acuerdo']}/pago-automatico", [], conBearer($m['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$m['slug']}/suscripciones", conBearer($m['bearer']))
        ->assertJsonPath('data.0.pago_automatico', null);
});
