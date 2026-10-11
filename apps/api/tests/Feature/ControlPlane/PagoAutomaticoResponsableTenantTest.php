<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Application\DeudaDeRenovacionTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Familias (ADR 0123) y pago automático (ADR 0129): el responsable paga con SU tarjeta
| las membresías de las personas a su cargo. La tarjeta y el cliente de Stripe son
| suyos; la membresía sigue siendo del participante. Sin la autorización vigente no
| paga por nadie, y retirarla quita esos pagos automáticos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');

    // Stripe falso: cada cliente con su id; cada sesión recuerda el suyo.
    $this->stripe = (object) ['clientes' => [], 'sesiones' => [], 'metadata' => [], 'cargos' => []];
    $stripe = $this->stripe;
    Http::fake(function (Request $request) use ($stripe) {
        $url = $request->url();
        if (str_ends_with($url, '/customers')) {
            $stripe->clientes[] = (string) ($request->data()['email'] ?? '');

            return Http::response(['id' => 'cus_'.count($stripe->clientes)]);
        }
        if ($request->method() === 'POST' && str_ends_with($url, '/checkout/sessions')) {
            $id = 'cs_'.(count($stripe->sesiones) + 1);
            $stripe->sesiones[$id] = ['modo' => (string) ($request->data()['mode'] ?? 'payment'), 'cliente' => (string) ($request->data()['customer'] ?? '')];

            return Http::response(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id]);
        }
        if ($request->method() === 'GET' && preg_match('#/checkout/sessions/(cs_\d+)#', $url, $m) === 1) {
            $sesion = $stripe->sesiones[$m[1]];
            $metodo = ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030]];

            return Http::response([
                'id' => $m[1], 'mode' => $sesion['modo'], 'customer' => $sesion['cliente'], 'status' => 'complete',
                'payment_status' => 'paid', 'metadata' => $stripe->metadata,
                'setup_intent' => $sesion['modo'] === 'setup' ? ['id' => 'seti_1', 'payment_method' => $metodo] : null,
                'payment_intent' => $sesion['modo'] === 'payment' ? ['id' => 'pi_compra', 'setup_future_usage' => 'off_session', 'payment_method' => $metodo] : null,
            ]);
        }
        if (str_ends_with($url, '/payment_intents')) {
            $stripe->cargos[] = (string) ($request->data()['customer'] ?? '');

            return Http::response(['id' => 'pi_auto_'.count($stripe->cargos), 'status' => 'succeeded']);
        }

        return Http::response(['status' => 'expired']);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioPagoResponsable(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

/**
 * Un negocio que cobra con Stripe y tiene familias; Laura (con cuenta) tiene a Sofía
 * (sin cuenta) a su cargo, con la autorización registrada en el mostrador. Sofía tiene
 * una membresía mensual pagada en recepción.
 *
 * @return array{slug: string, bearer: string, laura: string, lauraId: int, sofia: string, tutela: string, producto: string, acuerdo: string}
 */
function lauraConSofiaACargo(bool $autorizada = true): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => true], conPlataforma())->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $laura = alumnoConSesion($e, 'Laura', 'laura@correo.mx');
    [$lauraUlid, $lauraId] = enNegocioPagoResponsable(function (): array {
        $persona = PersonaTenant::query()->where('email', 'laura@correo.mx')->firstOrFail();

        return [(string) $persona->ulid, (int) $persona->getKey()];
    });
    $sofia = crearMiembroTenant($e, 'Sofía');
    $tutela = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$lauraUlid}/participantes", [
        'participante_id' => $sofia, 'parentesco' => 'madre_padre',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    if ($autorizada) {
        test()->postJson("/api/v1/app/{$e['slug']}/tutelas/{$tutela}/autorizar", ['version' => 1], conBearer($e['bearer']))->assertOk();
    }

    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $sofia, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $acuerdo = (string) enNegocioPagoResponsable(fn () => AcuerdoTenant::query()
        ->whereHas('persona', fn ($q) => $q->where('ulid', $sofia))->value('ulid'));

    return [...$e, 'laura' => $laura['bearer'], 'lauraId' => $lauraId, 'sofia' => $sofia, 'tutela' => $tutela, 'producto' => $producto, 'acuerdo' => $acuerdo];
}

/**
 * Laura activa el pago automático de la membresía de Sofía y autoriza su tarjeta en
 * Stripe (llega el aviso de la sesión).
 *
 * @param  array{slug: string, laura: string, acuerdo: string}  $f
 */
function lauraDomiciliaASofia(array $f): void
{
    test()->postJson("/api/v1/app/{$f['slug']}/mi/pago-automatico/{$f['acuerdo']}", [], conBearer($f['laura']))
        ->assertOk()->assertJsonPath('data.estado', 'redirect');
    test()->stripe->metadata = ['acuerdos' => $f['acuerdo']];
    test()->postJson("/api/v1/webhooks/tenant/{$f['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => array_key_last(test()->stripe->sesiones), 'mode' => 'setup', 'payment_status' => 'no_payment_required']],
    ])->assertOk();
}

/**
 * La membresía de Sofía vence hoy y corre el cobro de renovaciones.
 *
 * @param  array{acuerdo: string}  $f
 */
function renovarMembresiaDeSofia(array $f): string
{
    return (string) enNegocioPagoResponsable(function () use ($f): string {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $f['acuerdo'])->firstOrFail();
        $acuerdo->update(['proxima_cobro_en' => now()->toDateString()]);

        return app(CobroRecurrenteTenant::class)->renovar($acuerdo->refresh(), 'stripe');
    });
}

it('el responsable paga con su tarjeta la membresía de quien tiene a su cargo', function (): void {
    $f = lauraConSofiaACargo();

    $cuenta = $this->getJson("/api/v1/app/{$f['slug']}/mi/pago-automatico", conBearer($f['laura']))->assertOk()->json('data');
    expect($cuenta['membresias'])->toHaveCount(1)
        ->and($cuenta['membresias'][0])->toMatchArray(['id' => $f['acuerdo'], 'para' => ['id' => $f['sofia'], 'nombre' => 'Sofía'], 'automatico' => false]);

    lauraDomiciliaASofia($f);

    // La tarjeta se guarda en el cliente de Stripe de Laura, no de Sofía.
    expect($this->stripe->clientes)->toBe(['laura@correo.mx'])
        ->and($this->stripe->sesiones['cs_1'])->toBe(['modo' => 'setup', 'cliente' => 'cus_1']);
    $domiciliacion = enNegocioPagoResponsable(fn () => DomiciliacionTenant::query()->firstOrFail()->only(['persona_id', 'estado', 'ultimos4']));
    expect($domiciliacion)->toBe(['persona_id' => $f['lauraId'], 'estado' => 'activa', 'ultimos4' => '4242']);

    $despues = $this->getJson("/api/v1/app/{$f['slug']}/mi/pago-automatico", conBearer($f['laura']))->assertOk()->json('data');
    expect($despues['tarjeta'])->toBe(['marca' => 'visa', 'ultimos4' => '4242', 'expira' => '08/30'])
        ->and($despues['membresias'][0]['automatico'])->toBeTrue();

    // El negocio ve en Cobranza que la paga su responsable.
    $suscripcion = collect($this->getJson("/api/v1/app/{$f['slug']}/suscripciones", conBearer($f['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $f['acuerdo']);
    expect($suscripcion['pago_automatico']['paga'])->toStartWith('Laura');

    // La renovación de Sofía se cobra sola a la tarjeta de Laura.
    expect(renovarMembresiaDeSofia($f))->toBe('cobrado')
        ->and($this->stripe->cargos)->toBe(['cus_1']);
});

it('el negocio invita al pago automático a quien no tiene correo: le llega a su responsable', function (): void {
    $f = lauraConSofiaACargo();

    $this->postJson("/api/v1/app/{$f['slug']}/suscripciones/{$f['acuerdo']}/pago-automatico/solicitar", [], conBearer($f['bearer']))
        ->assertOk()->assertJsonPath('data.enviado', true);
});

it('sin correo ni responsable, no hay a quién invitar', function (): void {
    $f = lauraConSofiaACargo(autorizada: false);

    $this->postJson("/api/v1/app/{$f['slug']}/suscripciones/{$f['acuerdo']}/pago-automatico/solicitar", [], conBearer($f['bearer']))
        ->assertJsonPath('code', 'DIRECT_DEBIT_NOT_ALLOWED');
});

it('sin la autorización vigente no paga por esa persona', function (): void {
    $f = lauraConSofiaACargo(autorizada: false);

    $this->getJson("/api/v1/app/{$f['slug']}/mi/pago-automatico", conBearer($f['laura']))
        ->assertOk()->assertJsonCount(0, 'data.membresias');
    $this->postJson("/api/v1/app/{$f['slug']}/mi/pago-automatico/{$f['acuerdo']}", [], conBearer($f['laura']))->assertNotFound();
    expect($this->stripe->sesiones)->toBe([]);
});

it('retirar la autorización quita el pago automático y la renovación se avisa para pagarla', function (): void {
    $f = lauraConSofiaACargo();
    lauraDomiciliaASofia($f);

    $this->postJson("/api/v1/app/{$f['slug']}/mi/participantes/{$f['tutela']}/retirar", [], conBearer($f['laura']))->assertOk();

    expect(enNegocioPagoResponsable(fn () => DomiciliacionTenant::query()->firstOrFail()->estado))->toBe('cancelada');
    $this->getJson("/api/v1/app/{$f['slug']}/mi/pago-automatico", conBearer($f['laura']))
        ->assertOk()->assertJsonCount(0, 'data.membresias');
    $this->postJson("/api/v1/app/{$f['slug']}/mi/pago-automatico/{$f['acuerdo']}", [], conBearer($f['laura']))->assertNotFound();

    // La renovación ya no va a la tarjeta de Laura: queda para pagarse en línea.
    expect(renovarMembresiaDeSofia($f))->toBe('pendiente')
        ->and($this->stripe->cargos)->toBe([]);
});

it('si el negocio apaga familias, no se cobra a la tarjeta del responsable', function (): void {
    $f = lauraConSofiaACargo();
    lauraDomiciliaASofia($f);

    $this->putJson("/api/v1/plataforma/estudios/{$f['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => false], conPlataforma())->assertOk();

    expect(renovarMembresiaDeSofia($f))->toBe('pendiente')
        ->and($this->stripe->cargos)->toBe([])
        ->and(enNegocioPagoResponsable(fn () => DomiciliacionTenant::query()->firstOrFail()->estado))->toBe('cancelada');
});

it('al pagar la renovación de su hijo desde su cuenta, la tarjeta queda a nombre del responsable', function (): void {
    $f = lauraConSofiaACargo();
    $orden = (string) enNegocioPagoResponsable(function () use ($f): string {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $f['acuerdo'])->firstOrFail();
        $acuerdo->update(['proxima_cobro_en' => now()->toDateString()]);

        return (string) app(DeudaDeRenovacionTenant::class)->de($acuerdo->refresh())?->ulid;
    });

    // Laura, en la cuenta de Sofía, la paga con pago automático.
    $this->postJson("/api/v1/app/{$f['slug']}/mi/ordenes/{$orden}/cobrar", ['domiciliar' => true], conBearer($f['laura']) + ['X-Participante' => $f['sofia']])
        ->assertCreated()->assertJsonPath('data.checkout.tipo', 'redirect');
    expect($this->stripe->clientes)->toBe(['laura@correo.mx']);

    $this->postJson("/api/v1/webhooks/tenant/{$f['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => array_key_last($this->stripe->sesiones), 'mode' => 'payment', 'payment_status' => 'paid']],
    ])->assertOk();

    $domiciliacion = enNegocioPagoResponsable(fn () => DomiciliacionTenant::query()->firstOrFail()->only(['persona_id', 'estado']));
    expect($domiciliacion)->toBe(['persona_id' => $f['lauraId'], 'estado' => 'activa']);
});
