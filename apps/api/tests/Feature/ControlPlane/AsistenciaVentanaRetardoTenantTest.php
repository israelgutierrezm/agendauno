<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| ADR 0101: la asistencia se registra desde unos minutos antes de que empiece la clase
| o cita (configurable, 30 por omisión); «llegó» puede ser con retardo (cuenta como
| asistencia); al terminar de pasar lista o al terminar la clase, quien no tiene
| registro «no se presentó» (lo segundo, si el negocio lo tiene encendido).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un alumno con paquete y lugar en la sesión; devuelve su reserva.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function reservaParaPasarLista(array $e, string $nombre, string $sesion): string
{
    $persona = crearMiembroTenant($e, $nombre);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e, 8000), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * El negocio ajusta un parámetro de asistencia.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, int>  $valores
 */
function parametrosDeAsistencia(array $e, array $valores): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => $valores], conBearer($e['bearer']))->assertOk();
}

/**
 * La asistencia de cada reserva de la sesión, por reserva.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, array<string, mixed>>
 */
function asistenciasDeLaSesion(array $e, string $sesion): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->json('data'))->keyBy('id')->all();
}

it('la asistencia se registra desde los minutos configurados antes de que empiece', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    parametrosDeAsistencia($e, ['asistencia.minutos_antes' => 30]);
    // La clase es a las 8:00 y son las 6:00.
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $reserva = reservaParaPasarLista($e, 'Ana', $sesion);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'ATTENDANCE_NOT_OPEN')
        ->assertJsonFragment(['message' => 'La asistencia se registra desde las 07:30.']);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 07:31', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();
});

it('la lista trae la clase misma: la pantalla del pase de lista se abre sola', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    parametrosDeAsistencia($e, ['asistencia.minutos_antes' => 15]);
    $sesion = crearSesionTenant($e, agendaSemilla($e), capacidad: 12);

    $respuesta = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('meta.sesion.id', $sesion)
        ->assertJsonPath('meta.sesion.tipo', 'clase')
        ->assertJsonPath('meta.sesion.capacidad', 12)
        ->assertJsonPath('meta.sesion.estado', 'programada')
        ->assertJsonPath('meta.sesion.zona_horaria', 'America/Mexico_City')
        ->assertJsonPath('meta.empezo', false);
    // La clase es a las 8:00: la lista abre a las 7:45.
    expect(CarbonImmutable::parse((string) $respuesta->json('meta.asistencia_desde'))
        ->equalTo(CarbonImmutable::parse('2026-10-01 07:45', 'America/Mexico_City')))->toBeTrue();
});

it('un retardo cuenta como asistencia y se puede corregir sin mover créditos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $reserva = reservaParaPasarLista($e, 'Ana', $sesion);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente', 'retardo' => true], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'presente')
        ->assertJsonPath('data.retardo', true);
    expect(asistenciasDeLaSesion($e, $sesion)[$reserva])->toMatchArray(['asistencia' => 'presente', 'retardo' => true]);

    // Corregir a «llegó a tiempo»: sigue presente, sin retardo.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.retardo', false);
    // «No vino» nunca lleva retardo.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'ausente', 'retardo' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.retardo', false);
});

it('terminar de pasar lista deja «no se presentó» a quien no tiene registro, ya empezada la clase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $vino = reservaParaPasarLista($e, 'Ana', $sesion);
    $falto = reservaParaPasarLista($e, 'Beto', $sesion);

    // Antes de empezar, la lista no se termina.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/terminar-lista", [], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ATTENDANCE_NOT_OPEN');

    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:10', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$vino}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/terminar-lista", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.no_se_presentaron', 1);

    $lista = asistenciasDeLaSesion($e, $sesion);
    expect($lista[$vino]['asistencia'])->toBe('presente')
        ->and($lista[$falto]['asistencia'])->toBe('ausente');
});

it('al terminar la clase, quien no tiene registro «no se presentó» (si el negocio lo tiene encendido)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $vino = reservaParaPasarLista($e, 'Ana', $sesion);
    $falto = reservaParaPasarLista($e, 'Beto', $sesion);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:05', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$vino}/asistencia", ['estado' => 'presente', 'retardo' => true], conBearer($e['bearer']))->assertCreated();

    // Aún no termina (8:00–9:00): nada cambia.
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    expect(asistenciasDeLaSesion($e, $sesion)[$falto]['asistencia'])->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:05', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    $lista = asistenciasDeLaSesion($e, $sesion);
    expect($lista[$falto])->toMatchArray(['asistencia' => 'ausente', 'asistencia_automatica' => true])
        // Lo que alguien ya registró no lo toca.
        ->and($lista[$vino])->toMatchArray(['asistencia' => 'presente', 'retardo' => true, 'asistencia_automatica' => false]);
});

it('con la inasistencia automática apagada, al terminar no se marca nada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    parametrosDeAsistencia($e, ['asistencia.no_asistio_al_terminar' => 0]);
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $falto = reservaParaPasarLista($e, 'Beto', $sesion);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:05', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    expect(asistenciasDeLaSesion($e, $sesion)[$falto]['asistencia'])->toBeNull();
});
