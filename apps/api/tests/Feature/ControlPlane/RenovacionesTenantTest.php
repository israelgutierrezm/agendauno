<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Renovación de membresías: la deuda del periodo (orden de renovación), el intento de
| pago y la fecha de renovación van por separado. La fecha solo avanza cuando la
| deuda se paga; un intento pendiente o rechazado abre la mora y da seguimiento.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // Stripe falso: sesiones cs_1, cs_2… que se pueden vencer.
    $contador = (object) ['n' => 0];
    Http::fake(function (Request $request) use ($contador) {
        if (str_ends_with($request->url(), '/checkout/sessions')) {
            $contador->n++;

            return Http::response(['id' => 'cs_'.$contador->n, 'url' => 'https://checkout.stripe.com/c/pay/cs_'.$contador->n]);
        }

        return Http::response(['status' => 'expired']);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Membresía mensual de "Ana" que vence hoy, en un estudio que cobra con Stripe.
 *
 * @return array{slug: string, bearer: string, acuerdo: string, proxima: string}
 */
function membresiaPorRenovar(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    $hoy = now()->toDateString();
    enEstudioRenovacion($e, fn () => AcuerdoTenant::query()->where('ulid', $acuerdo)->update(['proxima_cobro_en' => $hoy]));

    return [...$e, 'acuerdo' => $acuerdo, 'proxima' => $hoy];
}

/**
 * @param  array{slug: string}  $e
 */
function enEstudioRenovacion(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * @param  array{slug: string, acuerdo: string}  $m
 * @return array{resultado: string, proxima: string|null, acuerdo: string, deudas: int, dunning: string|null, intentos: int|null}
 */
function renovarConStripe(array $m): array
{
    return enEstudioRenovacion($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $resultado = app(CobroRecurrenteTenant::class)->renovar($acuerdo, 'stripe');
        $acuerdo->refresh();
        $proceso = ProcesoDunningTenant::query()->where('acuerdo_id', $acuerdo->getKey())->latest('id')->first();

        return [
            'resultado' => $resultado,
            'proxima' => $acuerdo->proxima_cobro_en?->toDateString(),
            'acuerdo' => $acuerdo->estado->value,
            'deudas' => OrdenTenant::query()->where('renueva_acuerdo_id', $acuerdo->getKey())->where('estado', 'pendiente')->count(),
            'dunning' => $proceso?->estado->value,
            'intentos' => $proceso?->intentos,
        ];
    });
}

it('si el cobro queda pendiente, la fecha no avanza, la deuda queda y la mora da seguimiento', function (): void {
    $m = membresiaPorRenovar();

    $r = renovarConStripe($m);
    expect($r['resultado'])->toBe('pendiente')
        ->and($r['proxima'])->toBe($m['proxima'])      // no se adelantó sin cobrar
        ->and($r['deudas'])->toBe(1)
        ->and($r['dunning'])->toBe('en_mora');

    // El reintento reutiliza la misma deuda (no abre otra orden) y avanza la mora.
    $r = renovarConStripe($m);
    expect($r['deudas'])->toBe(1)->and($r['intentos'])->toBe(2)->and($r['proxima'])->toBe($m['proxima']);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/checkout/sessions/cs_1/expire'));
});

it('cuando el cliente paga (webhook), la fecha avanza un periodo y la mora se regulariza', function (): void {
    $m = membresiaPorRenovar();
    renovarConStripe($m);

    $this->postJson("/api/v1/webhooks/tenant/{$m['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();

    $estado = enEstudioRenovacion($m, fn (): array => [
        'proxima' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString(),
        'dunning' => ProcesoDunningTenant::query()->latest('id')->first()?->estado->value,
    ]);
    expect($estado['proxima'])->toBe(now()->addMonth()->toDateString())
        ->and($estado['dunning'])->toBe('regularizado');
});

it('si el cliente no paga, al vencer la gracia se suspende; al pagar en caja se reactiva', function (): void {
    $m = membresiaPorRenovar();
    renovarConStripe($m);

    $this->travel(8)->days();
    $this->artisan('turnouno:escalar-dunning')->assertSuccessful();
    expect(renovarConStripe($m)['acuerdo'])->toBe('suspendido');

    // Paga en recepción la orden de renovación pendiente.
    $orden = (string) enEstudioRenovacion($m, fn () => OrdenTenant::query()->where('estado', 'pendiente')->whereNotNull('renueva_acuerdo_id')->value('ulid'));
    $this->postJson("/api/v1/app/{$m['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($m['bearer']))->assertOk();

    $estado = enEstudioRenovacion($m, fn (): array => [
        'acuerdo' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->estado->value,
        'proxima' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString(),
    ]);
    expect($estado['acuerdo'])->toBe('activo')
        ->and($estado['proxima'] > now()->toDateString())->toBeTrue();
});

it('una sesión que vence no pierde la deuda ni el seguimiento', function (): void {
    $m = membresiaPorRenovar();
    renovarConStripe($m);

    $this->postJson("/api/v1/webhooks/tenant/{$m['slug']}/stripe", [
        'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_1']],
    ])->assertOk();

    $estado = enEstudioRenovacion($m, fn (): array => [
        'deudas' => OrdenTenant::query()->where('estado', 'pendiente')->whereNotNull('renueva_acuerdo_id')->count(),
        'dunning' => ProcesoDunningTenant::query()->latest('id')->first()?->estado->value,
        'proxima' => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString(),
    ]);
    expect($estado)->toBe(['deudas' => 1, 'dunning' => 'en_mora', 'proxima' => $m['proxima']]);
});
