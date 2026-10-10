<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Reglas que decide cada negocio para su cliente (ADR 0042 y 0115), no fijas en código:
| cuándo abre y cierra la reserva de una clase, con cuánta anticipación y hasta cuándo
| se agenda una cita en línea, si se agenda sin cuenta, si una reseña se publica sin
| revisarla y si el cliente puede cancelar después del límite sin costo. El negocio
| siempre puede hacerlo desde el panel. Reloj fijo: 2026-10-01 06:00 en la Ciudad de
| México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, int>  $valores
 */
function reglasDelNegocioPrueba(array $e, array $valores): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => $valores], conBearer($e['bearer']))->assertOk();
}

/**
 * Una alumna con cuenta y un paquete de clases.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string}
 */
function alumnaConPaqueteReglas(array $e): array
{
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();

    return ['bearer' => $vale['bearer'], 'persona' => $persona];
}

/**
 * Una barbería con un servicio de 30 min y un barbero que atiende de 08:00 a 20:00.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, barbero: string}
 */
function barberiaConAgendaReglas(): array
{
    $e = estudioConSesion('barberia-reglas', 'b@correo.mx', 'barberia');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@correo.mx', 'instructor');
    $barbero = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $barbero, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'barbero' => $barbero];
}

it('la reserva de una clase abre y cierra cuando lo decide el negocio', function (): void {
    $e = estudioConSesion('estudio-reglas', 'r@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnaConPaqueteReglas($e);
    reglasDelNegocioPrueba($e, ['reservas.dias_apertura' => 3, 'reservas.minutos_cierre' => 120]);

    // En 5 días: aún no abre para el cliente…
    $lejana = crearSesionTenant($e, $semilla, 10, '2026-10-06 10:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $lejana], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_NOT_OPEN');
    // …pero el negocio sí la reserva.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$lejana}/reservas", ['persona_id' => $vale['persona']], conBearer($e['bearer']))
        ->assertCreated();

    // En 2 días: abierta.
    $cercana = crearSesionTenant($e, $semilla, 10, '2026-10-03 10:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $cercana], conBearer($vale['bearer']))->assertCreated();

    // En una hora: ya cerró (cierra 2 h antes).
    $proxima = crearSesionTenant($e, $semilla, 10, '2026-10-01 07:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $proxima], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_NOT_OPEN');
});

it('una cita en línea respeta la anticipación mínima y el horizonte del negocio', function (): void {
    ['e' => $e, 'sede' => $sede, 'barbero' => $barbero] = barberiaConAgendaReglas();
    reglasDelNegocioPrueba($e, ['citas.minutos_anticipacion_minima' => 180, 'citas.dias_maximos_adelante' => 7]);
    $consulta = fn (string $fecha): string => "/api/v1/app/{$e['slug']}/citas/disponibilidad?".http_build_query([
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero, 'fecha' => $fecha,
    ]);

    // Son las 06:00: con 3 h de anticipación, el primer horario es a las 09:00.
    $hoy = $this->getJson($consulta('2026-10-01'))->assertOk()->json('data.slots');
    expect($hoy[0]['inicia_local'])->toBe('2026-10-01T09:00');
    // A 9 días, nada (agenda hasta 7 días adelante).
    expect($this->getJson($consulta('2026-10-10'))->assertOk()->json('data.slots'))->toBe([]);

    $cita = fn (string $cuando): array => [
        'nombre' => 'Cliente', 'email' => 'cliente@correo.mx', 'oferta_id' => $sede['oferta'],
        'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero, 'inicia_en_local' => $cuando, 'duracion_minutos' => 30,
    ];
    $this->postJson("/api/v1/app/{$e['slug']}/citas", $cita('2026-10-01 08:00:00'))
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_NOT_OPEN');
    $this->postJson("/api/v1/app/{$e['slug']}/citas", $cita('2026-10-10 10:00:00'))
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_NOT_OPEN');
    $this->postJson("/api/v1/app/{$e['slug']}/citas", $cita('2026-10-02 10:00:00'))->assertCreated();

    // El negocio ve (y agenda) sin esos límites.
    $panel = $this->getJson("/api/v1/app/{$e['slug']}/disponibilidad?".http_build_query([
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero, 'fecha' => '2026-10-01',
    ]), conBearer($e['bearer']))->assertOk()->json('data.slots');
    expect($panel[0]['inicia_local'])->toBe('2026-10-01T08:00');

    // La web y la app reciben las reglas para su calendario.
    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.reglas.minutos_anticipacion_minima', 180)
        ->assertJsonPath('data.reglas.dias_maximos_adelante', 7)
        ->assertJsonPath('data.reglas.agendar_sin_cuenta', true);
});

it('el negocio decide si se agenda sin cuenta', function (): void {
    ['e' => $e, 'sede' => $sede, 'barbero' => $barbero] = barberiaConAgendaReglas();
    reglasDelNegocioPrueba($e, ['citas.agendar_sin_cuenta' => 0]);

    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->assertJsonPath('data.reglas.agendar_sin_cuenta', false);
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente', 'email' => 'cliente@correo.mx', 'oferta_id' => $sede['oferta'],
        'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero, 'inicia_en_local' => '2026-10-02 10:00:00', 'duracion_minutos' => 30,
    ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_REQUIRED');
});

it('un servicio sin duración propia se ofrece con la del negocio', function (): void {
    ['e' => $e, 'sede' => $sede] = barberiaConAgendaReglas();
    reglasDelNegocioPrueba($e, ['citas.duracion_defecto' => 45]);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'duracion_minutos' => null], conBearer($e['bearer']))->assertOk();

    $servicio = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data.servicios'))
        ->firstWhere('id', $sede['oferta']);
    expect($servicio['duracion_minutos'])->toBe(45);
});

it('con revisión, una reseña nueva espera a que el negocio la apruebe', function (): void {
    $e = estudioConSesion('estudio-reglas', 'r@correo.mx');
    $vale = alumnaConPaqueteReglas($e);
    reglasDelNegocioPrueba($e, ['resenas.publicar_sin_revisar' => 0]);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-02 10:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vale['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->travel(2)->days();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/resena", ['calificacion' => 5, 'comentario' => 'Muy buena'], conBearer($vale['bearer']))
        ->assertCreated();
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.resenas.total'))->toBe(0);
    expect($this->getJson("/api/v1/app/{$e['slug']}/resenas", conBearer($e['bearer']))->assertOk()->json('data.0.visible'))->toBeFalse();
});

it('pasado el límite, el cliente cancela solo si el negocio lo permite', function (): void {
    $e = estudioConSesion('estudio-reglas', 'r@correo.mx');
    $vale = alumnaConPaqueteReglas($e);
    reglasDelNegocioPrueba($e, ['cancelacion.cliente_cancela_tarde' => 0]);
    // En 4 horas: ya pasó el límite sin costo (12 h por omisión).
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 10:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($vale['bearer']))
        ->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($vale['bearer']))
        ->assertOk()->assertJsonPath('data.cancelable', false);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelar", [], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'CANCELLATION_CLOSED');
    // El negocio sí puede cancelarla.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))->assertOk();
});
