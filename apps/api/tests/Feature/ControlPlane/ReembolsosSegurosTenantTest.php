<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| 1.1 de la fase 1: devoluciones tolerantes a reintentos y fallos. La devolución se
| registra antes de pedirla a la pasarela y se pide con su propia llave: un doble clic
| o un reintento nunca devuelven dos veces; si la pasarela no responde queda
| "incierta" y se aclara con la misma llave o, pasado el tiempo, a mano.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->stripe = (object) ['respuestas' => [], 'llaves' => []];

    // Stripe falso: cada llamada a /refunds toma la siguiente respuesta de la fila
    // ('succeeded' | 'pending' | 'failed' | 'sin_respuesta' | 'error_500'). "Sin
    // respuesta" es un 504 de la pasarela: el fake de Laravel no puede lanzar una
    // excepción de conexión en este entorno (tumba PHP); una real sí se captura.
    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_ends_with($url, '/checkout/sessions')) {
            return Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);
        }
        if (str_contains($url, '/checkout/sessions/cs_1')) {
            return Http::response(['id' => 'cs_1', 'payment_intent' => 'pi_1']);
        }
        if (str_ends_with($url, '/refunds')) {
            $this->stripe->llaves[] = $request->header('Idempotency-Key')[0] ?? null;
            $respuesta = array_shift($this->stripe->respuestas) ?? 'succeeded';

            return match ($respuesta) {
                'sin_respuesta' => Http::response(['error' => ['message' => 'Gateway Timeout']], 504),
                'error_500' => Http::response(['error' => ['message' => 'api_error']], 500),
                default => Http::response(['id' => 're_1', 'status' => $respuesta]),
            };
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Pack de 8 créditos de "Ana" pagado en línea con Stripe.
 *
 * @return array{slug: string, bearer: string, persona: string, pago: string}
 */
function pagoConStripe(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
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

    return [...$e, 'persona' => $persona, 'pago' => $pago];
}

/**
 * @param  array{slug: string, bearer: string, persona: string}  $p
 */
function saldoDe(array $p): int
{
    return (int) test()->getJson("/api/v1/app/{$p['slug']}/miembros/{$p['persona']}/derechos", conBearer($p['bearer']))
        ->assertOk()->json('data.0.saldo');
}

/**
 * @param  array{slug: string, bearer: string}  $p
 * @return list<array<string, mixed>>
 */
function porConciliar(array $p): array
{
    return test()->getJson("/api/v1/app/{$p['slug']}/incidencias-cobro", conBearer($p['bearer']))->assertOk()->json('data');
}

it('doble clic en "Reembolsar": una sola devolución', function (): void {
    $p = pagoConStripe();

    foreach ([1, 2] as $_) {
        $this->postJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], [
            ...conBearer($p['bearer']), 'Idempotency-Key' => 'clic-1',
        ])->assertCreated()->assertJsonPath('data.estado', 'aprobado');
    }

    $this->getJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", conBearer($p['bearer']))->assertOk()->assertJsonCount(1, 'data');
    expect($this->stripe->llaves)->toHaveCount(1)
        ->and(saldoDe($p))->toBe(0);
});

it('si la pasarela no responde, queda incierta y se aclara con la misma llave sin devolver dos veces', function (): void {
    $p = pagoConStripe();
    $this->stripe->respuestas = ['sin_respuesta', 'succeeded'];

    $this->postJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($p['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'incierto');
    // Nada se aplicó todavía, no se puede pedir otra y queda por conciliar.
    expect(saldoDe($p))->toBe(8000)
        ->and(porConciliar($p))->toHaveCount(1)
        ->and(porConciliar($p)[0]['tipo'])->toBe('reembolso_incierto');
    $this->postJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Otra'], conBearer($p['bearer']))->assertStatus(422);

    // El proceso la vuelve a pedir con la MISMA llave: Stripe responde lo de la primera vez.
    $this->artisan('turnouno:conciliar-reembolsos')->assertSuccessful();

    $this->getJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", conBearer($p['bearer']))
        ->assertOk()->assertJsonPath('data.0.estado', 'aprobado')->assertJsonCount(1, 'data');
    expect($this->stripe->llaves)->toHaveCount(2)
        ->and($this->stripe->llaves[0])->toBe($this->stripe->llaves[1])
        ->and(saldoDe($p))->toBe(0)
        ->and(porConciliar($p))->toHaveCount(0);

    // Correrlo de nuevo no hace nada más.
    $this->artisan('turnouno:conciliar-reembolsos')->assertSuccessful();
    expect($this->stripe->llaves)->toHaveCount(2);
});

it('pasado el tiempo en que la pasarela recuerda la llave, se resuelve a mano y se aplica una sola vez', function (): void {
    $p = pagoConStripe();
    $this->stripe->respuestas = ['error_500'];
    $this->postJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($p['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'incierto');

    $this->travel(24)->hours();
    $this->artisan('turnouno:conciliar-reembolsos')->assertSuccessful();
    expect($this->stripe->llaves)->toHaveCount(1); // ya no se reintenta

    $incidencia = porConciliar($p)[0];
    expect($incidencia['detalle'])->toContain('Revisa en su panel');
    // Hay que decir si la pasarela la hizo.
    $this->postJson("/api/v1/app/{$p['slug']}/incidencias-cobro/{$incidencia['id']}/resolver", ['resolucion' => 'Revisado'], conBearer($p['bearer']))
        ->assertStatus(422);
    $this->postJson("/api/v1/app/{$p['slug']}/incidencias-cobro/{$incidencia['id']}/resolver", [
        'resolucion' => 'En el panel de Stripe aparece devuelta', 'reembolso' => 'aprobado',
    ], conBearer($p['bearer']))->assertOk()->assertJsonPath('data.estado', 'resuelta')->assertJsonPath('data.reembolso.estado', 'aprobado');
    // Repetirlo no vuelve a aplicar nada.
    $this->postJson("/api/v1/app/{$p['slug']}/incidencias-cobro/{$incidencia['id']}/resolver", [
        'resolucion' => 'Otra vez', 'reembolso' => 'aprobado',
    ], conBearer($p['bearer']))->assertOk();

    expect(saldoDe($p))->toBe(0)
        ->and(porConciliar($p))->toHaveCount(0);
});

it('si la pasarela la rechaza queda fallida con su motivo y no cuenta como devuelta', function (): void {
    $p = pagoConStripe();
    $this->stripe->respuestas = ['failed'];

    $this->postJson("/api/v1/app/{$p['slug']}/pagos/{$p['pago']}/reembolsos", ['motivo' => 'Se mudó'], conBearer($p['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'fallido')
        ->assertJsonPath('data.motivo_fallo', 'Stripe rechazó la devolución (failed).');

    $pago = collect($this->getJson("/api/v1/app/{$p['slug']}/pagos", conBearer($p['bearer']))->assertOk()->json('data'))->firstWhere('id', $p['pago']);
    expect($pago['reembolsado_minor'])->toBe(0)
        ->and($pago['reembolsable_minor'])->toBe(89900)
        ->and(saldoDe($p))->toBe(8000);
});
