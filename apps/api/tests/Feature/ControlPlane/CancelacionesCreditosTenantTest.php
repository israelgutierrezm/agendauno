<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| 1.4 de la fase 1: cancelaciones, asistencia y créditos. Quién cancela decide la
| política (solo el cliente se puede penalizar), la vista previa dice lo mismo que
| pasa, cancelar dos veces no devuelve dos créditos, no hay asistencia en una reserva
| cancelada ni cancelación de una reserva con asistencia, corregir una asistencia
| compensa el crédito y el historial explica cada cambio de saldo.
|
| Fechas fijas: hoy es 1 de enero de 2030 y la clase es el 7 (faltan 140 h).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alumna con un pack de 8 créditos y acceso a su cuenta.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string, derecho: string}
 */
function alumnaConCreditos(array $e, string $email = 'ana@correo.mx'): array
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    return ['bearer' => personalConSesion($e['slug'], $e['bearer'], $email, 'miembro'), 'persona' => $persona, 'derecho' => $derecho];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function reservaDeAlumna(array $e, string $sesion, string $persona): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array{saldo: int, disponible: int}
 */
function saldoDeAlumna(array $e, string $derecho): array
{
    $r = test()->getJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/movimientos", conBearer($e['bearer']))->assertOk();

    return ['saldo' => (int) $r->json('saldo'), 'disponible' => (int) $r->json('disponible')];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function politicaTardia(array $e, int $horas = 720, bool $penalizaNoShow = true): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", [
        'horas_limite' => $horas, 'penaliza_tarde' => true, 'penaliza_no_show' => $penalizaNoShow,
    ], conBearer($e['bearer']))->assertCreated();
}

it('recepción cancela sin penalizar salvo que lo haya pedido el cliente, y queda quién fue', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    politicaTardia($e);
    $ana = alumnaConCreditos($e);
    $sede = agendaSemilla($e);
    $lunes = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00'), $ana['persona']);
    $martes = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-08 08:00:00'), $ana['persona']);

    // Por defecto cancela el negocio: tardía, pero el crédito regresa.
    $this->getJson("/api/v1/app/{$e['slug']}/reservas/{$lunes}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.credito', 'devuelve')
        ->assertJsonPath('data.mensaje', 'Se devolverá 1 crédito. Cancela el negocio: sin penalización.');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$lunes}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 8000, 'disponible' => 7000]);

    // A petición del cliente: aplica su política (tardía → se cobra).
    $this->getJson("/api/v1/app/{$e['slug']}/reservas/{$martes}/cancelacion?por=cliente", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.credito', 'cobra')->assertJsonPath('data.a_tiempo', false);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$martes}/cancelar", ['por' => 'cliente'], conBearer($e['bearer']))->assertOk();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 7000, 'disponible' => 7000]);

    $historial = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana['persona']}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data.reservas'))->keyBy('id');
    expect($historial[$lunes]['cancelada_por'])->toBe('negocio')
        ->and($historial[$martes]['cancelada_por'])->toBe('cliente');
});

it('cancelar dos veces no cobra ni devuelve dos veces', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    politicaTardia($e);
    $ana = alumnaConCreditos($e);
    $reserva = reservaDeAlumna($e, crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-07 08:00:00'), $ana['persona']);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelar", [], conBearer($ana['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelar", [], conBearer($ana['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))->assertOk();

    // Un solo cobro por la cancelación tardía, y nada más.
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
    $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$ana['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.cancelable', false)->assertJsonPath('data.mensaje', 'Esta reserva ya está cancelada.');
});

it('la alumna ve antes de cancelar lo que pasará con su crédito', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = alumnaConCreditos($e);
    $sede = agendaSemilla($e);
    $reserva = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00'), $ana['persona']);

    // Política por defecto: 6 h; faltan 140 → a tiempo.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.cancelable', true)->assertJsonPath('data.credito', 'devuelve')
        ->assertJsonPath('data.unidades', 1000)->assertJsonPath('data.mensaje', 'Se devolverá 1 crédito.');

    // Una clase de hoy a las 10:00 (faltan 4 h) ya es tardía.
    $hoy = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-01 10:00:00'), $ana['persona']);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/cancelacion", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.credito', 'cobra')
        ->assertJsonPath('data.mensaje', 'Se cobrará 1 crédito: se cancela con menos de 6 h de anticipación.');

    // Otra persona no puede ver la de Ana.
    $beto = alumnaConCreditos($e, 'beto@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($beto['bearer']))->assertForbidden();
});

it('no hay asistencia en una reserva cancelada ni cancelación de una reserva o clase con asistencia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = alumnaConCreditos($e);
    $sede = agendaSemilla($e);
    $cancelada = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00'), $ana['persona']);
    $sesion = crearSesionTenant($e, $sede, 5, '2030-01-08 08:00:00');
    $atendida = reservaDeAlumna($e, $sesion, $ana['persona']);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cancelada}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cancelada}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'RESERVATION_NOT_CONFIRMED')
        ->assertJsonPath('message', 'Esta reserva está cancelada; no se puede marcar asistencia.');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$atendida}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$atendida}/cancelar", [], conBearer($ana['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RESERVATION_ATTENDED');
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.cancelable', false);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RESERVATION_ATTENDED');

    // Solo se cobró la clase a la que asistió.
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
});

it('corregir una asistencia compensa el crédito y lo explica en el historial', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    politicaTardia($e, 6, false);
    $ana = alumnaConCreditos($e);
    $reserva = reservaDeAlumna($e, crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-07 08:00:00'), $ana['persona']);
    $marcar = fn (string $estado) => $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => $estado], conBearer($e['bearer']));

    // No-show sin penalización: el crédito regresa.
    $marcar('ausente')->assertCreated();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
    // Sí vino: se cobra, como corrección.
    $marcar('presente')->assertCreated();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
    // Marcar lo mismo otra vez no cobra de nuevo.
    $marcar('presente')->assertCreated();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
    // No vino, después de todo: se devuelve.
    $marcar('ausente')->assertCreated();
    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 8000, 'disponible' => 8000]);

    $conceptos = collect($this->getJson("/api/v1/app/{$e['slug']}/derechos/{$ana['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertOk()->json('data'))->pluck('concepto')->all();
    expect($conceptos)->toBe(['Corrección de asistencia', 'Corrección de asistencia', 'Créditos del plan']);
});

it('la alumna ve por qué cambió su saldo, y solo el suyo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    politicaTardia($e);
    $ana = alumnaConCreditos($e);
    $sede = agendaSemilla($e);
    $clase = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00'), $ana['persona']);
    $tarde = reservaDeAlumna($e, crearSesionTenant($e, $sede, 5, '2030-01-08 08:00:00'), $ana['persona']);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$clase}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$tarde}/cancelar", [], conBearer($ana['bearer']))->assertOk();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$ana['derecho']}/movimientos", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('saldo', 6000);
    expect(collect($r->json('data'))->map(fn (array $m): array => [$m['concepto'], $m['unidades'], $m['clase']['nombre'] ?? null])->all())
        ->toBe([
            ['Cancelación tardía', -1000, 'Nivel 1'],
            ['Asistencia', -1000, 'Nivel 1'],
            ['Créditos del plan', 8000, null],
        ]);

    $beto = alumnaConCreditos($e, 'beto@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$ana['derecho']}/movimientos", conBearer($beto['bearer']))->assertNotFound();
});

it('antes de cancelar una clase se ve cuántas reservas y créditos regresan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = alumnaConCreditos($e);
    $beto = alumnaConCreditos($e, 'beto@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-07 08:00:00');
    $deAna = reservaDeAlumna($e, $sesion, $ana['persona']);
    reservaDeAlumna($e, $sesion, $beto['persona']);

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.reservas', 2)->assertJsonPath('data.unidades', 2000)
        ->assertJsonPath('data.mensaje', 'Se cancelarán 2 reservas y se devolverán 2 créditos.');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();

    expect(saldoDeAlumna($e, $ana['derecho']))->toBe(['saldo' => 8000, 'disponible' => 8000])
        ->and(saldoDeAlumna($e, $beto['derecho']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
    $historial = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana['persona']}/ficha", conBearer($e['bearer']))
        ->json('data.reservas'))->keyBy('id');
    expect($historial[$deAna]['cancelada_por'])->toBe('negocio');
});
