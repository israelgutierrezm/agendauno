<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Citas PÚBLICAS (guest, sin cuenta): un cliente reserva una cita desde el escaparate y
| paga en línea; el webhook de la pasarela la confirma. Reusa el motor de citas + el
| cobro/pasarelas que ya existen. Solo para estudios en el directorio.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio en directorio con oferta de PAGO, Stripe activo y un instructor.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string}
 */
function estudioGuestCitas(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'coach' => $coach];
}

it('un cliente sin cuenta agenda una cita y paga en línea; el webhook la confirma', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_cita_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_cita_1',
        ], 200),
    ]);
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioGuestCitas();

    // Guest agenda SIN cuenta (sin bearer).
    $r = $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente Guest', 'email' => 'guest@correo.mx',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ])->assertCreated()->json('data');
    expect($r['estado'])->toBe('pendiente_pago');
    expect($r['orden_id'])->not->toBeNull();
    expect($r['total_minor'])->toBe(25000);

    // Guest paga en línea (sin cuenta) → pendiente + checkout de Stripe.
    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", [
        'orden_id' => $r['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ])->assertCreated()->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_cita_1');
    // Al terminar regresa a la página pública de citas del negocio.
    Http::assertSent(fn ($r): bool => str_ends_with($r['success_url'], "/agendar/{$e['slug']}?pago=exito"));

    // El webhook de Stripe confirma el pago → la reserva pasa a confirmada.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_cita_1', 'payment_status' => 'paid']],
    ])->assertOk();

    // La reserva quedó confirmada (fulfillment del webhook sobre la orden de la sesión).
    $estudioTenant = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudioTenant);
    $reserva = ReservaTenant::query()->where('ulid', $r['reserva'])->first();
    app(GestorDeConexionTenant::class)->desconectar();

    expect($reserva)->not->toBeNull();
    expect($reserva->estado->value)->toBe('confirmada');
});

it('el pago público de una cita rechaza el efectivo (solo en línea)', function (): void {
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioGuestCitas();

    $r = $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente Guest', 'email' => 'guest2@correo.mx',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 11:00:00', 'duracion_minutos' => 60,
    ])->assertCreated()->json('data');

    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", [
        'orden_id' => $r['orden_id'], 'proveedor' => 'efectivo',
    ])->assertStatus(422);
});
