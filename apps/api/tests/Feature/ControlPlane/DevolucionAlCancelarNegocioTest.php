<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Devolución automática (ADR 0046): si el negocio así lo decide, cuando cancela una
| cita ya pagada en línea el pago se devuelve solo por la misma pasarela. Si cancela
| el cliente, o el negocio no lo activó, no se devuelve nada solo.
| Hoy es 1 de enero de 2030.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/checkout/sessions') => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            str_contains($url, '/checkout/sessions/cs_1') => Http::response(['id' => 'cs_1', 'payment_intent' => 'pi_1']),
            str_ends_with($url, '/refunds') => Http::response(['id' => 're_1', 'status' => 'succeeded']),
            default => Http::response([], 404),
        };
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Cita de $500 del lunes 7 que Ana agendó y pagó con Stripe.
 *
 * @return array{e: array{slug: string, bearer: string}, ana: array{slug: string, bearer: string}, reserva: string, sesion: string, orden: string, pago: string}
 */
function citaPagadaEnLinea(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'pro@correo.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();

    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $cita = test()->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => '2030-01-07 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($ana['bearer']))->assertCreated()->json('data');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['orden_id']}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();

    return ['e' => $e, 'ana' => $ana, 'reserva' => (string) $cita['id'], 'sesion' => (string) $cita['sesion_id'], 'orden' => (string) $cita['orden_id'], 'pago' => $pago];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, pago: string}  $c
 * @return list<string>
 */
function devolucionesDelPago(array $c): array
{
    return collect(test()->getJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/reembolsos", conBearer($c['e']['bearer']))->assertOk()->json('data'))
        ->pluck('estado')->all();
}

it('si el negocio lo activó, al cancelar una cita pagada en línea el pago se devuelve solo, una vez', function (): void {
    $c = citaPagadaEnLinea();
    $e = $c['e'];
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['cancelacion.devolver_pago_si_cancela_negocio' => 1]], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$c['sesion']}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.se_devuelven', 1)
        ->assertJsonPath('data.mensaje', 'Se cancelará 1 reserva. 1 ya está pagada en línea: el pago se devolverá automáticamente.');
    $this->getJson("/api/v1/app/{$e['slug']}/reservas/{$c['reserva']}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.mensaje', 'No usa créditos. Ya está pagada en línea: el pago se devolverá automáticamente.');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$c['sesion']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    // El relay entrega el evento; aunque lo entregue dos veces, se devuelve una.
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(devolucionesDelPago($c))->toBe(['aprobado']);
    $devoluciones = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'));
    expect($devoluciones)->toHaveCount(1)
        ->and((int) $devoluciones->first()[0]['amount'])->toBe(50000);
    // Devuelta completa: la orden queda cancelada.
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$c['orden']}", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('sin activarlo, el pago no se devuelve solo y la vista previa lo dice', function (): void {
    $c = citaPagadaEnLinea();
    $e = $c['e'];

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$c['sesion']}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.se_devuelven', 0)
        ->assertJsonPath('data.mensaje', 'Se cancelará 1 reserva. 1 ya está pagada: ese pago no se reembolsa automáticamente.');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$c['reserva']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(devolucionesDelPago($c))->toBe([]);
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'));
});

it('si cancela el cliente se aplica su política, no la devolución automática', function (): void {
    $c = citaPagadaEnLinea();
    $e = $c['e'];
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['cancelacion.devolver_pago_si_cancela_negocio' => 1]], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$c['reserva']}/cancelacion", conBearer($c['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.mensaje', 'No usa créditos. Ya está pagada: el pago no se reembolsa automáticamente.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$c['reserva']}/cancelar", [], conBearer($c['ana']['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(devolucionesDelPago($c))->toBe([]);
});
