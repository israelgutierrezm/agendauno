<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Facades\File;

/*
| Pago-para-reservar (citas): en una oferta con política `pago`, reservar crea una
| reserva PENDIENTE DE PAGO que RETIENE el cupo (no-sobreventa) + una orden por la
| sesión; al PAGAR esa orden, la reserva se CONFIRMA (fulfillment). Reusa el motor de
| órdenes/pagos existente. No requiere membresía: el acceso lo habilita el pago.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estado actual de una reserva (leído en la BD del estudio). R-citas.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function estadoReservaTenant(array $e, string $ulid): string
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    $reserva = ReservaTenant::query()->where('ulid', $ulid)->first();
    app(GestorDeConexionTenant::class)->desconectar();

    return $reserva instanceof ReservaTenant ? $reserva->estado->value : '';
}

/**
 * Antigüedad artificial de una reserva (para probar la expiración del pendiente).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function backdatearReservaTenant(array $e, string $ulid, int $minutos): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    ReservaTenant::query()->where('ulid', $ulid)->update(['created_at' => now()->subMinutes($minutos)]);
    app(GestorDeConexionTenant::class)->desconectar();
}

it('reserva pendiente de pago retiene el cupo y se confirma al pagar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    // La oferta pasa a PAGO-para-reservar, con su precio.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    // Sesión con cupo 1 (para probar la retención del lugar).
    $sesion = crearSesionTenant($e, $sede, 1);

    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $b = alumnoConSesion($e, 'Beto', 'beto@correo.mx');

    // Alumno A reserva-y-paga → PENDIENTE de pago + orden a pagar.
    $r = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($a['bearer']))
        ->assertCreated()->json('data');
    expect($r['estado'])->toBe('pendiente_pago');
    expect($r['orden_id'])->not->toBeNull();

    // El cupo está RETENIDO: el alumno B no puede reservar (lleno) mientras A no paga.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($b['bearer']))
        ->assertStatus(409);

    // Al pagar la orden de la sesión (aquí, liquidación de staff), la reserva se CONFIRMA.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$r['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();

    expect(estadoReservaTenant($e, $r['id']))->toBe('confirmada');
});

it('sin pago no se confirma: la reserva sigue pendiente y el cupo sigue retenido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, $sede, 1);

    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $r = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($a['bearer']))
        ->assertCreated()->json('data');

    // Sin pagar, sigue pendiente.
    expect(estadoReservaTenant($e, $r['id']))->toBe('pendiente_pago');
});

it('expira una reserva pendiente no pagada a tiempo y libera el cupo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, $sede, 1);

    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $b = alumnoConSesion($e, 'Beto', 'beto@correo.mx');

    $r = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($a['bearer']))
        ->assertCreated()->json('data');

    // La reserva lleva > 30 min sin pagar → el relay debe liberarla.
    backdatearReservaTenant($e, $r['id'], 31);
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();

    // Quedó cancelada y el cupo se liberó: Beto ya puede reservar.
    expect(estadoReservaTenant($e, $r['id']))->toBe('cancelada');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($b['bearer']))
        ->assertCreated();
});
