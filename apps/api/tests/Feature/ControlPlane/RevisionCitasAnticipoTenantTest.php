<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| El dinero de las citas con anticipo (ADR 0161), de punta a punta: el resto no se
| paga antes que el anticipo, en caja se cobra todo lo que falta junto, si el negocio
| cancela se devuelve todo lo pagado en línea, y cobrar se autoriza por la cita (su
| sede y su profesional), no por la ficha del cliente.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // Cada sesión de pago de Stripe con su id; las devoluciones se aprueban.
    $sesiones = 0;
    Http::fake(function (Request $request) use (&$sesiones) {
        $url = $request->url();
        if (str_ends_with($url, '/checkout/sessions')) {
            $sesiones++;

            return Http::response(['id' => "cs_{$sesiones}", 'url' => "https://checkout.stripe.com/c/pay/cs_{$sesiones}"]);
        }
        if (preg_match('~/checkout/sessions/(cs_\d+)~', $url, $m) === 1) {
            return Http::response(['id' => $m[1], 'payment_intent' => 'pi_'.$m[1]]);
        }
        if (str_ends_with($url, '/refunds')) {
            return Http::response(['id' => 're_'.md5((string) $request->body()), 'status' => 'succeeded']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con un corte de $250 y anticipo de $100, un barbero, cobro en línea con
 * Stripe y una clienta con cuenta que agenda para dentro de tres días.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string, ana: string, cita: array<string, mixed>}
 */
function citaConAnticipoParaRevisar(): array
{
    $e = estudioConSesion('barberia-revision', 'dueno@barberia-revision.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-revision.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30, 'anticipo_minor' => 10000,
    ], conBearer($e['bearer']))->assertOk();
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    activarCobroEnLinea($e);
    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    $ana = alumnoConSesion($e, 'Ana', 'ana@barberia-revision.mx');
    $cita = (array) test()->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => "{$dia} 10:00:00", 'duracion_minutos' => 30,
    ], conBearer($ana['bearer']))->assertCreated()->json('data');

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => $dia, 'ana' => $ana['bearer'], 'cita' => $cita];
}

/** Stripe completa el cobro de esa sesión de pago. */
function stripeCompletaSesion(string $slug, string $sesion): void
{
    test()->postJson("/api/v1/webhooks/tenant/{$slug}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $sesion, 'payment_status' => 'paid']],
    ])->assertOk();
}

/**
 * La cita como la ve la agenda del negocio.
 *
 * @param  array{e: array{slug: string, bearer: string}, dia: string}  $ctx
 * @return array<string, mixed>
 */
function citaEnAgendaParaRevisar(array $ctx): array
{
    return (array) collect(test()->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data'))->first(fn (array $s): bool => ($s['cita'] ?? null) !== null)['cita'];
}

it('el resto no se paga antes que el anticipo: no se ofrece ni se acepta', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];

    // Por pagar: solo el anticipo; la cita no ofrece el resto todavía.
    $pendientes = collect($this->getJson("/api/v1/app/{$slug}/mi/ordenes/pendientes", conBearer($ctx['ana']))->assertOk()->json('data'));
    expect($pendientes->pluck('total_minor')->all())->toBe([10000]);
    $reserva = collect($this->getJson("/api/v1/app/{$slug}/mi/perfil", conBearer($ctx['ana']))->assertOk()->json('data.reservas'))
        ->firstWhere('id', $ctx['cita']['id']);
    expect($reserva['cita']['saldo_orden_id'])->toBeNull()
        ->and($reserva['cita']['saldo_minor'])->toBe(15000);

    // Aunque se pida el resto directamente, no se cobra.
    $resto = collect($this->getJson("/api/v1/app/{$slug}/ordenes?estado=pendiente", conBearer($ctx['e']['bearer']))->json('data'))
        ->firstWhere('total_minor', 15000)['id'];
    $this->postJson("/api/v1/app/{$slug}/mi/ordenes/{$resto}/cobrar", [], conBearer($ctx['ana']))
        ->assertStatus(422)->assertJsonPath('meta.errors.orden.0', 'Primero paga el anticipo: con él se aparta tu cita; el resto se paga después.');

    // Pagado el anticipo, ya se ofrece y se paga.
    $this->postJson("/api/v1/app/{$slug}/mi/ordenes/{$ctx['cita']['orden_id']}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
    stripeCompletaSesion($slug, 'cs_1');
    expect(collect($this->getJson("/api/v1/app/{$slug}/mi/ordenes/pendientes", conBearer($ctx['ana']))->json('data'))->pluck('total_minor')->all())
        ->toBe([15000]);
    $this->postJson("/api/v1/app/{$slug}/mi/ordenes/{$resto}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
});

it('si el negocio cancela, se devuelven el anticipo y el resto pagados en línea', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    $this->putJson("/api/v1/app/{$slug}/parametros", ['valores' => ['cancelacion.devolver_pago_si_cancela_negocio' => 1]], conBearer($ctx['e']['bearer']))
        ->assertOk();
    $this->postJson("/api/v1/app/{$slug}/mi/ordenes/{$ctx['cita']['orden_id']}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
    stripeCompletaSesion($slug, 'cs_1');
    $resto = (string) collect($this->getJson("/api/v1/app/{$slug}/mi/perfil", conBearer($ctx['ana']))->json('data.reservas'))
        ->firstWhere('id', $ctx['cita']['id'])['cita']['saldo_orden_id'];
    $this->postJson("/api/v1/app/{$slug}/mi/ordenes/{$resto}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
    stripeCompletaSesion($slug, 'cs_2');

    $reserva = (string) citaEnAgendaParaRevisar($ctx)['reserva_id'];
    $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/cancelar", ['por' => 'negocio'], conBearer($ctx['e']['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $devoluciones = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'))
        ->map(fn (array $par): int => (int) $par[0]['amount'])->sort()->values()->all();
    expect($devoluciones)->toBe([10000, 15000]);
});

it('en caja, con el anticipo sin pagar, se cobra todo lo que falta junto', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    expect(citaEnAgendaParaRevisar($ctx))->toMatchArray(['por_cobrar' => true, 'por_cobrar_minor' => 25000]);
    $reserva = (string) citaEnAgendaParaRevisar($ctx)['reserva_id'];

    $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo'], conBearer($ctx['e']['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 25000);
    expect(citaEnAgendaParaRevisar($ctx))->toMatchArray([
        'estado' => 'confirmada', 'estado_pago' => 'pagada', 'por_cobrar' => false, 'por_cobrar_minor' => null,
    ]);
});

it('cobrar se autoriza por la cita: su sede y su profesional, no la ficha del cliente', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    $reserva = (string) citaEnAgendaParaRevisar($ctx)['reserva_id'];
    // Otra sede: la recepción queda acotada a la de la cita (la clienta no tiene sede).
    agendaSemilla($ctx['e']);
    $recep = personalConSesion($slug, $ctx['e']['bearer'], 'recep@barberia-revision.mx', 'recepcionista');
    asignarSucursal($ctx['e'], usuarioIdPorEmail($ctx['e'], 'recep@barberia-revision.mx'), $ctx['sede']['sucursal']);
    // Un profesional con un rol propio que cobra, pero no es su cita.
    $rol = (string) $this->postJson("/api/v1/app/{$slug}/roles", [
        'nombre' => 'Barbero que cobra', 'faceta' => 'instructor',
        'permisos' => ['agenda.ver', 'reservas.ver', 'ordenes.ver', 'ordenes.gestionar', 'productos.ver', 'miembros.ver', 'derechos.ver'],
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data.clave');
    $otro = personalConSesion($slug, $ctx['e']['bearer'], 'otro@barberia-revision.mx', $rol);

    $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo'], conBearer($otro))->assertForbidden();
    $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo'], conBearer($recep))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 25000);
});

/**
 * Pagados en línea el anticipo ($100) y el resto ($150) de la cita; devuelve el id de la
 * reserva.
 *
 * @param  array{e: array{slug: string, bearer: string}, ana: string, cita: array<string, mixed>}  $ctx
 */
function citaPagadaCompletaParaRevisar(array $ctx): string
{
    $slug = $ctx['e']['slug'];
    test()->postJson("/api/v1/app/{$slug}/mi/ordenes/{$ctx['cita']['orden_id']}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
    stripeCompletaSesion($slug, 'cs_1');
    $resto = (string) collect(test()->getJson("/api/v1/app/{$slug}/mi/perfil", conBearer($ctx['ana']))->json('data.reservas'))
        ->firstWhere('id', $ctx['cita']['id'])['cita']['saldo_orden_id'];
    test()->postJson("/api/v1/app/{$slug}/mi/ordenes/{$resto}/cobrar", [], conBearer($ctx['ana']))->assertCreated();
    stripeCompletaSesion($slug, 'cs_2');

    return (string) $ctx['cita']['id'];
}

/** @return list<int> los montos que se pidió devolver a Stripe, de menor a mayor */
function devolucionesDeStripeParaRevisar(): array
{
    return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/refunds'))
        ->map(fn (array $par): int => (int) $par[0]['amount'])->sort()->values()->all();
}

it('si cancela tarde, el negocio se queda solo con el anticipo y le devuelve el resto', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    $this->putJson("/api/v1/app/{$slug}/parametros", ['valores' => ['cancelacion.cliente_cancela_tarde' => 1]], conBearer($ctx['e']['bearer']))->assertOk();
    $reserva = citaPagadaCompletaParaRevisar($ctx);

    // Una hora antes de la cita.
    $this->travelTo(CarbonImmutable::parse("{$ctx['dia']} 09:00:00", 'America/Mexico_City'));
    $this->getJson("/api/v1/app/{$slug}/mi/reservas/{$reserva}/cancelacion", conBearer($ctx['ana']))->assertOk()
        ->assertJsonPath('data.mensaje', fn (string $m): bool => str_contains($m, 'el anticipo no se devuelve') && str_contains($m, '$150.00'));
    $this->postJson("/api/v1/app/{$slug}/mi/reservas/{$reserva}/cancelar", [], conBearer($ctx['ana']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(devolucionesDeStripeParaRevisar())->toBe([15000]);
});

it('si no llega, el negocio se queda solo con el anticipo y le devuelve el resto', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    citaPagadaCompletaParaRevisar($ctx);
    $reserva = (string) citaEnAgendaParaRevisar($ctx)['reserva_id'];

    // Pasada la cita, la recepción ve qué pasa antes de marcarla.
    $this->travelTo(CarbonImmutable::parse("{$ctx['dia']} 11:00:00", 'America/Mexico_City'));
    $this->getJson("/api/v1/app/{$slug}/reservas/{$reserva}/inasistencia", conBearer($ctx['e']['bearer']))->assertOk()
        ->assertJsonPath('data.conserva', 'anticipo')
        ->assertJsonPath('data.conserva_minor', 10000)
        ->assertJsonPath('data.devuelve_minor', 15000)
        ->assertJsonPath('data.devuelve_en_linea_minor', 15000);
    $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/asistencia", ['estado' => 'ausente'], conBearer($ctx['e']['bearer']))->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(devolucionesDeStripeParaRevisar())->toBe([15000]);
});

it('en caja, anticipo y resto juntos con varias formas: el ticket trae las dos y el cambio sale del efectivo', function (): void {
    $ctx = citaConAnticipoParaRevisar();
    $slug = $ctx['e']['slug'];
    $reserva = (string) citaEnAgendaParaRevisar($ctx)['reserva_id'];

    // Anticipo $100 + resto $150 = $250: tarjeta $100 y efectivo $150; entrega $200.
    $datos = $this->postJson("/api/v1/app/{$slug}/reservas/{$reserva}/cobro", [
        'metodo' => 'efectivo', 'recibido_minor' => 20000, 'clave_intento' => 'anticipo-mixto-1',
        'partes' => [['metodo' => 'tarjeta', 'monto_minor' => 10000], ['metodo' => 'efectivo', 'monto_minor' => 15000]],
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data');

    expect($datos['servicio_minor'])->toBe(25000)
        ->and($datos['metodo'])->toBe('mixto')
        ->and($datos['cambio_minor'])->toBe(5000)
        ->and(collect($datos['partes'])->pluck('monto_minor', 'metodo')->sortKeys()->all())->toBe(['efectivo' => 15000, 'tarjeta' => 10000]);
});
