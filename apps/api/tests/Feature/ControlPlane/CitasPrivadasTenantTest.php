<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Una CITA es una sesión privada materializada para una persona: no se lista a otros
| miembros ni en el escaparate, nadie más puede reservarla, y cuando su reserva
| termina (cancelada o sin pagar a tiempo) libera el horario del profesional. Las
| reservas pendientes de pago son visibles para su titular y para el staff.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio con un servicio de PAGO agendable como cita y un profesional.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, hora: string}
 */
function estudioConServicioDeCitas(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'hora' => now()->addDays(3)->format('Y-m-d').' 10:00:00'];
}

/**
 * Agenda una cita como miembro y devuelve la reserva presentada.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, hora: string}  $ctx
 * @return array<string, mixed>
 */
function agendarCitaComo(array $ctx, string $bearer): array
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => $ctx['hora'], 'duracion_minutos' => 30,
    ], conBearer($bearer))->assertCreated()->json('data');
}

it('la cita de un cliente no aparece en la agenda de otros miembros ni en el escaparate', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $beto = alumnoConSesion($ctx['e'], 'Beto', 'beto@correo.mx');
    agendarCitaComo($ctx, $ana['bearer']);

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/mi/agenda", conBearer($beto['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    $publico = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/escaparate")->assertOk()->json('data');
    expect($publico['proximas_sesiones'])->toBe([]);
    expect($publico['estudio']['tiene_citas'])->toBeTrue();
});

it('las clases abiertas siguen apareciendo en la agenda del miembro', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    crearSesionTenant($ctx['e'], $ctx['sede'], null, now()->addDays(5)->format('Y-m-d').' 08:00:00');
    agendarCitaComo($ctx, $ana['bearer']);

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/mi/agenda", conBearer($ana['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('nadie más puede reservar ni esperar el lugar de una cita ajena', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $beto = alumnoConSesion($ctx['e'], 'Beto', 'beto@correo.mx');
    $cita = agendarCitaComo($ctx, $ana['bearer']);

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/reservas", [
        'sesion_id' => $cita['sesion_id'], 'esperar' => true,
    ], conBearer($beto['bearer']))->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
});

it('el cliente ve su cita pendiente de pago con la orden a pagar', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $cita = agendarCitaComo($ctx, $ana['bearer']);

    $reservas = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->assertOk()->json('data.reservas');

    expect($reservas)->toHaveCount(1);
    expect($reservas[0]['estado'])->toBe('pendiente_pago');
    expect($reservas[0]['orden_id'])->toBe($cita['orden_id']);
});

it('cancelar una cita pendiente de pago libera el horario y cancela su orden', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $beto = alumnoConSesion($ctx['e'], 'Beto', 'beto@correo.mx');
    $cita = agendarCitaComo($ctx, $ana['bearer']);

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/reservas/{$cita['id']}/cancelar", [], conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');

    $orden = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/mi/ordenes", conBearer($ana['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $cita['orden_id']);
    expect($orden['estado'])->toBe('cancelada');

    // El mismo horario vuelve a estar libre para otro cliente.
    expect(agendarCitaComo($ctx, $beto['bearer'])['estado'])->toBe('pendiente_pago');
});

it('una cita que no se paga a tiempo libera el horario del profesional', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $beto = alumnoConSesion($ctx['e'], 'Beto', 'beto@correo.mx');
    agendarCitaComo($ctx, $ana['bearer']);

    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();

    expect(agendarCitaComo($ctx, $beto['bearer'])['estado'])->toBe('pendiente_pago');
});

it('el staff ve en su agenda a quién atiende cada cita y la cuenta como ocupada', function (): void {
    $ctx = estudioConServicioDeCitas();
    $ana = alumnoConSesion($ctx['e'], 'Ana', 'ana@correo.mx');
    $cita = agendarCitaComo($ctx, $ana['bearer']);
    $dia = substr($ctx['hora'], 0, 10);

    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$dia}&hasta={$dia}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $cita['sesion_id']);

    expect($sesion['tipo'])->toBe('cita');
    expect($sesion['ocupados'])->toBe(1);
    expect($sesion['cita']['cliente'])->toBe('Ana');
    expect($sesion['cita']['estado'])->toBe('pendiente_pago');
    expect($sesion['cita']['asistencia'])->toBeNull();

    // El roster de la cita también la muestra (recepción puede cobrarla).
    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones/{$cita['sesion_id']}/reservas", conBearer($ctx['e']['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'pendiente_pago');
});

it('el cliente agenda desde su cuenta: ve servicios y horarios libres aunque el negocio no esté en el directorio', function (): void {
    $ctx = estudioConServicioDeCitas();
    $e = $ctx['e'];
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $ctx['pro'],
        'sucursal_id' => $ctx['sede']['sucursal'],
        'horarios' => [['dia_semana' => (int) now()->addDays(3)->isoWeekday(), 'hora_inicio' => '09:00', 'hora_fin' => '12:00']],
    ], conBearer($e['bearer']))->assertCreated();
    $cliente = alumnoConSesion($e);

    $opciones = $this->getJson("/api/v1/app/{$e['slug']}/mi/citas/opciones", conBearer($cliente['bearer']))
        ->assertOk()->json('data');
    expect(collect($opciones['servicios'])->pluck('id'))->toContain($ctx['sede']['oferta'])
        ->and(collect($opciones['instructores'])->pluck('id'))->toContain($ctx['pro']);

    $fecha = now()->addDays(3)->format('Y-m-d');
    $slots = $this->getJson("/api/v1/app/{$e['slug']}/mi/citas/disponibilidad?instructor_id={$ctx['pro']}&sucursal_id={$ctx['sede']['sucursal']}&fecha={$fecha}&duracion_minutos=30", conBearer($cliente['bearer']))
        ->assertOk()->json('data.slots');
    expect($slots)->not->toBeEmpty();

    // El personal sin perfil de alumno no usa estas rutas.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/citas/opciones", conBearer($e['bearer']))->assertForbidden();
});
