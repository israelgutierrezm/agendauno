<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Inicio del negocio: el día de hoy (su fecha LOCAL) con quién se espera, quién llegó
| y a quién falta pasar lista; y, según los permisos, cobros y renovaciones.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Reserva a un alumno nuevo (con su paquete) en una sesión; devuelve la reserva.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function reservarAlumnoNuevo(array $e, string $sesion, string $nombre): string
{
    $alumno = venderPackAMiembroTenant($e, 8000, $nombre);

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $alumno['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

it('el día local: esperados, llegaron y por marcar en las que ya empezaron', function (): void {
    // Se reserva temprano (6:00 en la Ciudad de México)…
    $this->travelTo('2026-10-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $manana = crearSesionTenant($e, $semilla, 10, '2026-10-01 08:00:00');
    crearSesionTenant($e, $semilla, 10, '2026-10-01 12:00:00');
    // 20:00 local ya es el 2 en UTC: sigue siendo de hoy.
    crearSesionTenant($e, $semilla, 10, '2026-10-01 20:00:00');
    // Mañana: no.
    crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');

    $llego = reservarAlumnoNuevo($e, $manana, 'Ana');
    $falto = reservarAlumnoNuevo($e, $manana, 'Bea');
    reservarAlumnoNuevo($e, $manana, 'Cris');
    // …y a las 10:30 se pasa lista.
    $this->travelTo('2026-10-01 16:30:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$llego}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$falto}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=2026-10-01", conBearer($e['bearer']))->assertOk();

    $r->assertJsonPath('data.fecha', '2026-10-01')
        ->assertJsonPath('data.modalidad', 'clases')
        ->assertJsonPath('data.libres', null)
        ->assertJsonPath('data.agenda.totales', [
            'sesiones' => 3, 'esperados' => 3, 'llegaron' => 1, 'sin_marcar' => 1,
            // 30 lugares; una lista por registrar (la que ya terminó).
            'capacidad' => 30, 'listas_pendientes' => 1, 'en_espera' => 0,
            'por_atender' => 0, 'por_cobrar' => 0,
        ])
        ->assertJsonCount(3, 'data.agenda.sesiones')
        ->assertJsonPath('data.agenda.sesiones.0.momento', 'termino')
        ->assertJsonPath('data.agenda.sesiones.0.sin_marcar', 1)
        ->assertJsonPath('data.agenda.sesiones.1.momento', 'proxima')
        ->assertJsonPath('data.agenda.sesiones.1.sin_marcar', 0);
    // El dueño ve también cobros y renovaciones.
    expect($r->json('data.cobros'))->toHaveKeys(['ordenes_pendientes', 'por_cobrar', 'en_mora'])
        ->and($r->json('data.renovaciones'))->toHaveKeys(['por_vencer', 'vencidas', 'dias']);
});

it('cada quien ve lo suyo: el instructor, sus clases y nada de cobros ni renovaciones', function (): void {
    $this->travelTo('2026-10-01 16:30:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 18:00:00',
        'duracion_minutos' => 60,
        'instructor_id' => usuarioIdPorEmail($e, 'coach@correo.mx'),
    ], conBearer($e['bearer']))->assertCreated();
    crearSesionTenant($e, $semilla, 10, '2026-10-01 19:00:00');

    $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=2026-10-01", conBearer($coach))
        ->assertOk()
        ->assertJsonCount(1, 'data.agenda.sesiones')
        ->assertJsonPath('data.agenda.sesiones.0.instructor', 'Personal')
        ->assertJsonPath('data.cobros', null)
        ->assertJsonPath('data.renovaciones', null);
});

it('las citas dicen a quién se atiende y lo pendiente de cobro sale en cobros', function (): void {
    $this->travelTo('2026-10-05 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($ana['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=2026-10-05", conBearer($e['bearer']))->assertOk();

    // Aún no llega su hora: se cobra al atenderla, todavía no se debe.
    expect($r->json('data.agenda.sesiones.0'))->toMatchArray(['tipo' => 'cita', 'cliente' => 'Ana', 'esperados' => 1])
        ->and($r->json('data.cobros.ordenes_pendientes'))->toBe(0)
        ->and($r->json('data.cobros.proximas'))->toBe(1);

    // Pasó sin pagarse: ya es por cobrar (lo mismo que dice «Por cobrar»).
    $this->travelTo('2026-10-05 18:00:00');
    $r = $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=2026-10-05", conBearer($e['bearer']))->assertOk();
    expect($r->json('data.cobros.ordenes_pendientes'))->toBe(1)
        ->and($r->json('data.cobros.por_cobrar'))->toBe([['moneda' => 'MXN', 'total_minor' => 25000]])
        ->and($r->json('data.cobros.proximas'))->toBe(0);
});

it('en citas responde quién sigue, qué falta por atender y cobrar, y dónde hay espacios libres', function (): void {
    // 9:00 en la Ciudad de México.
    $this->travelTo('2026-10-05 15:00:00');
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
    $sede = agendaSemilla($e);
    $corte = (string) $this->postJson("/api/v1/app/{$e['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Corte de cabello', 'duracion_minutos' => 30, 'precio_minor' => 25000],
    ]], conBearer($e['bearer']))->assertCreated()->json('data.0.id');
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $barbero = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $barbero, $sede['sucursal']);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $corte, 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 30,
    ], conBearer($ana['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=2026-10-05", conBearer($e['bearer']))->assertOk();

    $r->assertJsonPath('data.modalidad', 'citas')
        ->assertJsonPath('data.agenda.totales.por_atender', 1)
        ->assertJsonPath('data.agenda.totales.por_cobrar', 1)
        ->assertJsonPath('data.agenda.sesiones.0.cliente', 'Ana')
        ->assertJsonPath('data.agenda.sesiones.0.por_cobrar', true);
    $libres = $r->json('data.libres');
    expect($libres)->toHaveCount(1)
        ->and($libres[0])->toMatchArray(['profesional' => 'Personal', 'sucursal' => 'Roma Norte'])
        // Ya pasaron las 8:00 y las 8:30, y las 10:00 está ocupada.
        ->and($libres[0]['siguiente'])->toBe('2026-10-05T15:30:00+00:00')
        ->and($libres[0]['huecos'])->toBe(20);
});

it('la fecha va en formato de día', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy?fecha=01/10/2026", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['fecha'], 'meta.errors');
    $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.agenda.totales.sesiones', 0);
});
