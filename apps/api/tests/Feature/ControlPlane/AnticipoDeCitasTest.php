<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Anticipo de una cita (ADR 0161). Un servicio pide para apartar en línea un monto o un
| porcentaje de su precio; el cliente paga el anticipo (o todo, si lo elige) y el resto
| queda por cobrar en el negocio. Si cancela a tiempo, lo pagado en línea se le
| devuelve solo; si cancela tarde, el negocio lo conserva. Lo que agenda el negocio no
| lleva anticipo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
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
 * Barbería con un corte de $250 (30 min) que pide anticipo, un barbero que atiende a
 * diario y cobro en línea con Stripe.
 *
 * @param  array<string, int|null>  $anticipo  `anticipo_minor` o `anticipo_pct`
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}
 */
function barberiaConAnticipo(array $anticipo): array
{
    $e = estudioConSesion('barberia-anticipo', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30, ...$anticipo,
    ], conBearer($e['bearer']))->assertOk();
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    activarCobroEnLinea($e);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => now('America/Mexico_City')->addDays(3)->format('Y-m-d')];
}

/**
 * Agenda en la página pública a las 10:00 del día de la prueba.
 *
 * @param  array{e: array{slug: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}  $ctx
 * @param  array<string, mixed>  $extra
 */
function agendarConAnticipo(array $ctx, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx',
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} 10:00:00", 'duracion_minutos' => 30,
        ...$extra,
    ]);
}

/** El cliente paga en línea la orden (Stripe completa el cobro). */
function pagarAnticipoEnLinea(string $slug, string $orden): void
{
    test()->postJson("/api/v1/app/{$slug}/citas/pagar", ['orden_id' => $orden])->assertCreated();
    test()->postJson("/api/v1/webhooks/tenant/{$slug}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();
}

/**
 * La cita como la ve la agenda del negocio.
 *
 * @param  array{e: array{slug: string, bearer: string}, dia: string}  $ctx
 * @return array<string, mixed>
 */
function citaEnAgendaConAnticipo(array $ctx): array
{
    $sesiones = test()->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data');

    return (array) collect($sesiones)->first(fn (array $s): bool => ($s['cita'] ?? null) !== null)['cita'];
}

it('con anticipo se aparta pagando el anticipo y el resto queda por cobrar al terminar', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];

    $servicio = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data.servicios'))
        ->firstWhere('id', $ctx['sede']['oferta']);
    expect($servicio['anticipo_minor'])->toBe(10000);

    $cita = agendarConAnticipo($ctx)->assertCreated()->json('data');
    expect($cita)->toMatchArray(['estado' => 'pendiente_pago', 'total_minor' => 10000, 'saldo_minor' => 15000]);
    // El enlace para pagar después dice lo mismo.
    $this->getJson("/api/v1/app/{$e['slug']}/citas/orden/{$cita['orden_id']}")->assertOk()
        ->assertJsonPath('data.total_minor', 10000)->assertJsonPath('data.saldo_minor', 15000);

    pagarAnticipoEnLinea($e['slug'], $cita['orden_id']);
    expect(citaEnAgendaConAnticipo($ctx))->toMatchArray([
        'estado' => 'confirmada', 'estado_pago' => 'por_cobrar', 'por_cobrar' => true,
        'total_minor' => 25000, 'anticipo_minor' => 10000, 'por_cobrar_minor' => 15000,
    ]);

    // Al terminar se cobra solo el saldo.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 15000)->assertJsonPath('data.total_minor', 15000);
    expect(citaEnAgendaConAnticipo($ctx))->toMatchArray([
        'estado_pago' => 'pagada', 'por_cobrar' => false, 'por_cobrar_minor' => null, 'total_minor' => 25000,
    ]);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertUnprocessable();
});

it('si no llega, la confirmación dice que el anticipo no se devuelve', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];
    $cita = agendarConAnticipo($ctx)->assertCreated()->json('data');
    $efecto = fn (): array => $this->getJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/inasistencia", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // Sin pagar aún, no hay nada que conservar.
    expect($efecto())->toMatchArray(['con_credito' => false, 'conserva' => null, 'conserva_minor' => null]);

    pagarAnticipoEnLinea($e['slug'], $cita['orden_id']);
    expect($efecto())->toMatchArray([
        'con_credito' => false, 'pierde_credito' => false,
        'conserva' => 'anticipo', 'conserva_minor' => 10000, 'moneda' => 'MXN',
    ]);
});

it('el anticipo puede ser un porcentaje y el cliente puede elegir pagar todo', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_pct' => 30]);
    $e = $ctx['e'];

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$ctx['sede']['oferta']}", ['lugares' => 0], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo_pct', 30)->assertJsonPath('data.anticipo_minor', null)
        ->assertJsonPath('data.anticipo_resultante_minor', 7500);

    agendarConAnticipo($ctx)->assertCreated()
        ->assertJsonPath('data.total_minor', 7500)->assertJsonPath('data.saldo_minor', 17500);
    agendarConAnticipo($ctx, ['inicia_en_local' => "{$ctx['dia']} 12:00:00", 'pagar' => 'todo'])->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente_pago')
        ->assertJsonPath('data.total_minor', 25000)->assertJsonPath('data.saldo_minor', null);
});

it('si el cliente cancela a tiempo, el anticipo pagado en línea se le devuelve solo y el saldo se cancela', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} 10:00:00", 'duracion_minutos' => 30,
    ], conBearer($ana['bearer']))->assertCreated()->json('data');
    pagarAnticipoEnLinea($e['slug'], (string) $cita['orden_id']);
    expect($cita['cita']['saldo_minor'])->toBe(15000);

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelacion", conBearer($ana['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo', 'devuelve')
        ->assertJsonPath('data.mensaje', 'El anticipo pagado en línea se devolverá automáticamente.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelar", [], conBearer($ana['bearer']))->assertOk();
    // Aunque el relay lo entregue dos veces, se devuelve una.
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $devoluciones = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'));
    expect($devoluciones)->toHaveCount(1)
        ->and((int) $devoluciones->first()[0]['amount'])->toBe(10000);
    $ordenes = collect($this->getJson("/api/v1/app/{$e['slug']}/ordenes?estado=cancelada", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('total_minor')->sort()->values()->all();
    // El anticipo (devuelto completo) y el saldo, que ya no se cobrará.
    expect($ordenes)->toBe([10000, 15000]);
});

it('si el cliente cancela tarde, el negocio conserva el anticipo', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} 10:00:00", 'duracion_minutos' => 30,
    ], conBearer($ana['bearer']))->assertCreated()->json('data');
    pagarAnticipoEnLinea($e['slug'], (string) $cita['orden_id']);

    // Dos horas antes (el límite sin costo es de 6 h).
    $this->travelTo(now('America/Mexico_City')->setDateFrom($ctx['dia'])->setTime(8, 0));
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelacion", conBearer($ana['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo', 'cobra')
        ->assertJsonPath('data.mensaje', 'Se cancela con menos de 6 h de anticipación: el anticipo no se devuelve.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelar", [], conBearer($ana['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'));
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['orden_id']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'pagada');
});

it('si no paga el anticipo a tiempo, se libera el horario y el saldo se cancela', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];
    agendarConAnticipo($ctx)->assertCreated();

    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();

    $estados = collect($this->getJson("/api/v1/app/{$e['slug']}/ordenes", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('estado')->unique()->values()->all();
    expect($estados)->toBe(['cancelada']);
    // El horario vuelve a estar libre.
    agendarConAnticipo($ctx, ['email' => 'otra@correo.mx', 'nombre' => 'Otra'])->assertCreated();
});

it('lo que agenda el negocio no lleva anticipo: una sola orden por cobrar', function (): void {
    $ctx = barberiaConAnticipo(['anticipo_minor' => 10000]);
    $e = $ctx['e'];
    $persona = crearMiembroTenant($e, 'Marco');

    $cita = $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'persona_id' => $persona, 'sucursal_id' => $ctx['sede']['sucursal'],
        'instructor_id' => $ctx['pro'], 'inicia_en_local' => "{$ctx['dia']} 10:00:00",
    ], conBearer($e['bearer']))->assertCreated()->json('data.cita');

    expect($cita)->toMatchArray([
        'estado' => 'confirmada', 'por_cobrar' => true, 'total_minor' => 25000, 'anticipo_minor' => null, 'por_cobrar_minor' => 25000,
    ]);
});

it('el anticipo es un monto menor que el precio o un porcentaje, no los dos', function (): void {
    $ctx = barberiaConAnticipo([]);
    $e = $ctx['e'];
    $url = "/api/v1/app/{$e['slug']}/ofertas/{$ctx['sede']['oferta']}";

    $this->putJson($url, ['lugares' => 0, 'anticipo_minor' => 25000], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['anticipo_minor'], 'meta.errors');
    $this->putJson($url, ['lugares' => 0, 'anticipo_minor' => 5000, 'anticipo_pct' => 20], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['anticipo_minor'], 'meta.errors');
    $this->putJson($url, ['lugares' => 0, 'anticipo_pct' => 100], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['anticipo_pct'], 'meta.errors');

    // Elegir uno borra el otro; null lo quita.
    $this->putJson($url, ['lugares' => 0, 'anticipo_pct' => 40], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo_resultante_minor', 10000);
    $this->putJson($url, ['lugares' => 0, 'anticipo_minor' => 5000, 'anticipo_pct' => null], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo_pct', null)->assertJsonPath('data.anticipo_resultante_minor', 5000);
    $this->putJson($url, ['lugares' => 0, 'anticipo_minor' => null], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.anticipo_minor', null)->assertJsonPath('data.anticipo_resultante_minor', null);
});
