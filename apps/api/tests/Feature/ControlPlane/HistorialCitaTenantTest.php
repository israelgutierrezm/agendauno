<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Detalle de una cita para recepción: el historial (agendada, cobro y su corrección,
| asistencia y su corrección, cancelación, con quién lo hizo) y el contacto del
| cliente, que solo ve quien puede ver miembros.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con una cita de pago para mañana, aún sin cobrar.
 *
 * @return array{e: array{slug: string, bearer: string}, dia: string, sesion: string, reserva: string, orden: string, barbero: string}
 */
function citaParaHistorial(): array
{
    $e = estudioConSesion('barberia-historial', 'dueno@barberia-historial.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    $barbero = personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-historial.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Diego Mora', 'email' => 'diego@correo.mx', 'celular' => '5512345678', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $dia = now()->addDay()->format('Y-m-d');

    $cita = test()->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $persona, 'oferta_id' => $sede['oferta'],
        'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 16:00:00",
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    return [
        'e' => $e, 'dia' => $dia, 'sesion' => (string) $cita['id'], 'reserva' => (string) $cita['cita']['reserva_id'],
        'orden' => (string) $cita['cita']['orden_id'], 'barbero' => $barbero,
    ];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array<string, mixed>>
 */
function historialDeCita(array $e, string $reserva, ?string $bearer = null): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/historial", conBearer($bearer ?? $e['bearer']))
        ->assertOk()->json('data');
}

it('cuenta lo que pasó con la cita, en orden y con quién lo hizo', function (): void {
    $c = citaParaHistorial();
    $base = "/api/v1/app/{$c['e']['slug']}";
    $this->postJson("{$base}/ordenes/{$c['orden']}/liquidar", ['metodo' => 'efectivo'], conBearer($c['e']['bearer']))->assertOk();
    $pago = (string) collect($this->getJson("{$base}/sesiones?desde={$c['dia']}&hasta={$c['dia']}", conBearer($c['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $c['sesion'])['cita']['pago']['id'];
    $this->putJson("{$base}/pagos/{$pago}/metodo", ['metodo' => 'transferencia', 'motivo' => 'Pagó por transferencia'], conBearer($c['e']['bearer']))
        ->assertOk();
    $this->travelTo(now()->addDay()->setTime(16, 5));
    $this->postJson("{$base}/reservas/{$c['reserva']}/asistencia", ['estado' => 'ausente'], conBearer($c['e']['bearer']))->assertCreated();
    $this->postJson("{$base}/reservas/{$c['reserva']}/asistencia", ['estado' => 'presente'], conBearer($c['e']['bearer']))->assertCreated();

    $historial = historialDeCita($c['e'], $c['reserva']);

    expect(array_column($historial, 'tipo'))
        ->toBe(['agendada', 'cobrada', 'metodo_corregido', 'asistencia', 'asistencia_corregida']);
    expect($historial[1]['detalle'])->toMatchArray(['monto_minor' => 25000, 'metodo' => 'efectivo', 'en_caja' => true]);
    expect($historial[1]['actor'])->not->toBeNull();
    expect($historial[2]['detalle'])->toMatchArray(['de' => 'efectivo', 'a' => 'transferencia', 'motivo' => 'Pagó por transferencia']);
    expect($historial[3]['detalle']['estado'])->toBe('ausente');
    expect($historial[4]['detalle']['estado'])->toBe('presente');
});

it('una cita cancelada dice quién la canceló', function (): void {
    $c = citaParaHistorial();
    $this->postJson("/api/v1/app/{$c['e']['slug']}/reservas/{$c['reserva']}/cancelar", ['por' => 'cliente'], conBearer($c['e']['bearer']))
        ->assertOk();

    $ultimo = collect(historialDeCita($c['e'], $c['reserva']))->last();
    expect($ultimo['tipo'])->toBe('cancelada');
    expect($ultimo['detalle']['por'])->toBe('cliente');
    expect($ultimo['actor'])->not->toBeNull();
});

it('el instructor ve el historial de sus citas, no el de otras', function (): void {
    $c = citaParaHistorial();
    historialDeCita($c['e'], $c['reserva'], $c['barbero']);

    $otro = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'otro@barberia-historial.mx', 'instructor');
    $this->getJson("/api/v1/app/{$c['e']['slug']}/reservas/{$c['reserva']}/historial", conBearer($otro))->assertForbidden();
});

it('el contacto del cliente solo lo ve quien puede ver miembros', function (): void {
    $c = citaParaHistorial();
    $base = "/api/v1/app/{$c['e']['slug']}";
    $cita = fn (string $bearer): array => collect($this->getJson("{$base}/sesiones?desde={$c['dia']}&hasta={$c['dia']}", conBearer($bearer))
        ->assertOk()->json('data'))->firstWhere('id', $c['sesion'])['cita'];

    expect($cita($c['e']['bearer']))->toMatchArray(['telefono' => '5512345678', 'email' => 'diego@correo.mx']);
    expect($cita($c['e']['bearer'])['cliente_id'])->toBeString();

    $rol = $this->postJson("{$base}/roles", ['nombre' => 'Solo agenda', 'permisos' => ['agenda.ver', 'reservas.ver']], conBearer($c['e']['bearer']))
        ->assertCreated()->json('data.clave');
    $agenda = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'agenda@barberia-historial.mx', $rol);
    expect($cita($agenda))->toMatchArray(['telefono' => null, 'email' => null]);
});
