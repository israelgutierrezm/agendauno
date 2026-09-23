<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| El NEGOCIO agenda citas (recepción, teléfono, mostrador) desde su agenda: la cita
| nace confirmada — no expira como el pago en línea — y, en un servicio de pago,
| queda la orden por cobrar en caja. El hueco del profesional debe estar libre.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con un servicio de pago, un barbero y un cliente dado de alta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cliente: string, dia: string}
 */
function barberiaConCliente(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    return [
        'e' => $e, 'sede' => $sede, 'pro' => $pro,
        'cliente' => crearMiembroTenant($e, 'Marco'),
        'dia' => now()->addDays(2)->format('Y-m-d'),
    ];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cliente: string, dia: string}  $ctx
 * @return array<string, mixed>
 */
function cargaCita(array $ctx, string $hora = '11:00'): array
{
    return [
        'persona_id' => $ctx['cliente'], 'oferta_id' => $ctx['sede']['oferta'],
        'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} {$hora}:00",
    ];
}

it('recepción agenda una cita confirmada con la orden por cobrar en caja', function (): void {
    $ctx = barberiaConCliente();
    $recep = personalConSesion($ctx['e']['slug'], $ctx['e']['bearer'], 'recep@barberia.mx', 'recepcionista');

    $cita = $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($recep))
        ->assertCreated()->json('data');

    expect($cita['tipo'])->toBe('cita');
    expect($cita['cita']['cliente'])->toBe('Marco');
    expect($cita['cita']['estado'])->toBe('confirmada');
    expect($cita['cita']['por_cobrar'])->toBeTrue();
    // Toma la duración del servicio (30 min).
    expect(strtotime($cita['termina_en']) - strtotime($cita['inicia_en']))->toBe(1800);

    // No expira como el pago en línea: sigue confirmada tras la ventana de pago.
    $this->travel(31)->minutes();
    $this->artisan('turnouno:expirar-reservas-pago')->assertSuccessful();
    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $cita['id']);
    expect($sesion['estado'])->toBe('programada');
    expect($sesion['cita']['estado'])->toBe('confirmada');
});

it('al cobrar en caja la cita deja de estar por cobrar', function (): void {
    $ctx = barberiaConCliente();
    $cita = $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($ctx['e']['bearer']))
        ->assertCreated()->json('data');

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/ordenes/{$cita['cita']['orden_id']}/liquidar", [
        'metodo' => 'efectivo',
    ], conBearer($ctx['e']['bearer']))->assertOk();

    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $cita['id']);
    expect($sesion['cita']['por_cobrar'])->toBeFalse();
    expect($sesion['cita']['estado'])->toBe('confirmada');
});

it('no agenda dos citas del mismo profesional a la misma hora', function (): void {
    $ctx = barberiaConCliente();
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($ctx['e']['bearer']))
        ->assertCreated();

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx, '11:15'), conBearer($ctx['e']['bearer']))
        ->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
});

it('quien no gestiona reservas no puede agendar citas', function (): void {
    $ctx = barberiaConCliente();
    $barbero = (string) $this->postJson("/api/v1/app/{$ctx['e']['slug']}/login", [
        'email' => 'barbero@barberia.mx', 'password' => 'secreto123',
    ])->assertOk()->json('data.token');

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($barbero))
        ->assertForbidden();
});

it('cancelar una cita sin cobrar cancela su orden y libera el horario', function (): void {
    $ctx = barberiaConCliente();
    $cita = $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($ctx['e']['bearer']))
        ->assertCreated()->json('data');

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/reservas/{$cita['cita']['reserva_id']}/cancelar", [], conBearer($ctx['e']['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/ordenes/{$cita['cita']['orden_id']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');

    // El mismo hueco vuelve a estar libre.
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", cargaCita($ctx), conBearer($ctx['e']['bearer']))
        ->assertCreated();
});
