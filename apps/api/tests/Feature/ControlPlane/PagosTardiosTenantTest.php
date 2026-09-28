<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| 1.2 de la fase 1: un pago que llega después de vencer el apartado de una cita.
| "Pagado" no es "confirmado": se reconfirma solo si el horario sigue libre; si no,
| el dinero queda identificado, por conciliar, y se avisa al cliente y al equipo.
| La cita es el lunes 5 de octubre a las 10:00 de CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->stripe = (object) ['expiradas' => []];

    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_ends_with($url, '/expire')) {
            $this->stripe->expiradas[] = $url;

            return Http::response(['id' => 'cs_1', 'status' => 'expired']);
        }
        if (str_ends_with($url, '/checkout/sessions')) {
            return Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);
        }
        if (str_contains($url, '/checkout/sessions/')) {
            return Http::response(['id' => 'cs_1', 'payment_intent' => 'pi_1', 'status' => 'complete']);
        }
        if (str_ends_with($url, '/refunds')) {
            return Http::response(['id' => 're_1', 'status' => 'succeeded']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con Stripe y un barbero; "Bea" apartó una cita a las 10:00 y abrió el pago
 * en línea (cs_1), sin completarlo.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string, reserva: string, orden: string}
 */
function citaApartadaSinPagar(): array
{
    test()->travelTo('2026-10-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    $cita = citaPublicaDe($e, $sede, $coach, 'Bea', 'bea@correo.mx');
    test()->postJson("/api/v1/app/{$e['slug']}/citas/pagar", ['orden_id' => $cita['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta'])
        ->assertCreated();

    return ['e' => $e, 'sede' => $sede, 'coach' => $coach, 'reserva' => $cita['reserva'], 'orden' => $cita['orden_id']];
}

/**
 * @param  array{slug: string}  $e
 * @param  array{oferta: string, sucursal: string}  $sede
 * @return array{reserva: string, orden_id: string, estado: string}
 */
function citaPublicaDe(array $e, array $sede, string $coach, string $nombre, string $email): array
{
    return test()->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => $nombre, 'email' => $email,
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ])->assertCreated()->json('data');
}

/**
 * Stripe avisa (tarde) que se completó el pago de cs_1.
 *
 * @param  array{slug: string}  $e
 */
function llegaElPagoDeCs1(array $e): void
{
    test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();
}

/**
 * @param  array{slug: string}  $e
 */
function enNegocioTardio(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

it('al vencer el apartado se cierra el cobro abierto en la pasarela', function (): void {
    $c = citaApartadaSinPagar();

    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();

    expect($this->stripe->expiradas)->toHaveCount(1)
        ->and(enNegocioTardio($c['e'], fn () => ReservaTenant::query()->where('ulid', $c['reserva'])->value('motivo_cancelacion')))->toBe('vencio_pago')
        ->and(enNegocioTardio($c['e'], fn () => PagoTenant::query()->value('estado')->value))->toBe('rechazado');
});

it('si el pago llega tarde y el horario sigue libre, la cita se reconfirma', function (): void {
    $c = citaApartadaSinPagar();
    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();

    llegaElPagoDeCs1($c['e']);

    $estado = enNegocioTardio($c['e'], fn (): array => [
        'reserva' => ReservaTenant::query()->where('ulid', $c['reserva'])->value('estado')->value,
        'orden' => OrdenTenant::query()->where('ulid', $c['orden'])->value('estado')->value,
        'pago' => PagoTenant::query()->value('estado')->value,
    ]);
    expect($estado)->toBe(['reserva' => 'confirmada', 'orden' => 'pagada', 'pago' => 'aprobado'])
        ->and($this->getJson("/api/v1/app/{$c['e']['slug']}/incidencias-cobro", conBearer($c['e']['bearer']))->json('data'))->toBe([]);
});

it('si el horario ya lo ocupó otro cliente, el pago queda identificado y por conciliar sin desplazar a nadie', function (): void {
    $c = citaApartadaSinPagar();
    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    // Carlos toma ese mismo horario.
    $carlos = citaPublicaDe($c['e'], $c['sede'], $c['coach'], 'Carlos', 'carlos@correo.mx');

    llegaElPagoDeCs1($c['e']);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $estado = enNegocioTardio($c['e'], fn (): array => [
        'bea' => ReservaTenant::query()->where('ulid', $c['reserva'])->value('estado')->value,
        'carlos' => ReservaTenant::query()->where('ulid', $carlos['reserva'])->value('estado')->value,
        'orden' => OrdenTenant::query()->where('ulid', $c['orden'])->value('estado')->value,
        'pago' => PagoTenant::query()->where('referencia_externa', 'cs_1')->value('estado')->value,
        'aviso' => MensajeTenant::query()->where('destinatario', 'bea@correo.mx')->where('asunto', 'like', 'Recibimos tu pago%')->count(),
        'equipo' => MensajeTenant::query()->where('destinatario', 'a@correo.mx')->where('asunto', 'like', 'Pago tardío%')->count(),
    ]);
    expect($estado)->toBe([
        'bea' => 'cancelada', 'carlos' => 'pendiente_pago', 'orden' => 'cancelada', 'pago' => 'aprobado', 'aviso' => 1, 'equipo' => 1,
    ]);

    $incidencias = $this->getJson("/api/v1/app/{$c['e']['slug']}/incidencias-cobro", conBearer($c['e']['bearer']))->assertOk()->json('data');
    expect($incidencias)->toHaveCount(1)
        ->and($incidencias[0]['tipo'])->toBe('pago_tardio')
        ->and($incidencias[0]['monto_minor'])->toBe(25000);

    // El negocio lo devuelve desde la bandeja (una sola vez aunque se repita).
    foreach ([1, 2] as $_) {
        $this->postJson("/api/v1/app/{$c['e']['slug']}/incidencias-cobro/{$incidencias[0]['id']}/resolver", [
            'resolucion' => 'Se le avisó y se devolvió', 'accion' => 'devolver',
        ], conBearer($c['e']['bearer']))->assertOk();
    }
    $pago = (string) enNegocioTardio($c['e'], fn () => PagoTenant::query()->where('referencia_externa', 'cs_1')->value('ulid'));
    $this->getJson("/api/v1/app/{$c['e']['slug']}/pagos/{$pago}/reembolsos", conBearer($c['e']['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'aprobado');
});

it('un segundo cobro de una compra ya pagada queda por conciliar', function (): void {
    $c = citaApartadaSinPagar();
    llegaElPagoDeCs1($c['e']);

    // Otro intento de la misma compra que también se cobró.
    enNegocioTardio($c['e'], function () use ($c): void {
        $orden = OrdenTenant::query()->where('ulid', $c['orden'])->firstOrFail();
        PagoTenant::query()->create([
            'orden_id' => $orden->getKey(), 'proveedor' => 'stripe', 'metodo' => 'tarjeta',
            'estado' => 'pendiente', 'monto_minor' => 25000, 'moneda' => 'MXN', 'referencia_externa' => 'cs_2',
        ]);
    });
    $this->postJson("/api/v1/webhooks/tenant/{$c['e']['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_2', 'payment_status' => 'paid']],
    ])->assertOk();

    $incidencias = $this->getJson("/api/v1/app/{$c['e']['slug']}/incidencias-cobro", conBearer($c['e']['bearer']))->assertOk()->json('data');
    expect($incidencias)->toHaveCount(1)
        ->and($incidencias[0]['tipo'])->toBe('pago_duplicado')
        ->and(enNegocioTardio($c['e'], fn () => ReservaTenant::query()->where('ulid', $c['reserva'])->value('estado')->value))->toBe('confirmada');
});
