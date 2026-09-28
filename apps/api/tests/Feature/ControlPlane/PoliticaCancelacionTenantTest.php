<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Politica de cancelacion/no-show configurable (R8): global y override por actividad,
| con deadline y penalizacion (tardia / no-show) configurables. La reserva CONGELA la
| politica al crearse (snapshot): cambiarla luego no afecta reservas ya hechas.
| Ver docs/audits/agendauno-competitive-audit.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function configurarPoliticaCancelacion(array $e, array $datos): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", $datos, conBearer($e['bearer']))
        ->assertCreated();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array{saldo: int, disponible: int}
 */
function saldosDerechoPolitica(array $e, string $persona): array
{
    $d = test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0');

    return ['saldo' => (int) $d['saldo'], 'disponible' => (int) $d['disponible']];
}

it('con penaliza_tarde=false una cancelacion tardia devuelve el credito retenido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // horas_limite alto => cualquier cancelacion de una sesion cercana es "tardia".
    configurarPoliticaCancelacion($e, ['horas_limite' => 720, 'penaliza_tarde' => false, 'penaliza_no_show' => true]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))->assertOk();

    // Tardia pero sin penalizacion: el credito vuelve completo.
    expect(saldosDerechoPolitica($e, $vp['persona']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
});

it('con penaliza_tarde=true una cancelacion tardia consume el credito', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    configurarPoliticaCancelacion($e, ['horas_limite' => 720, 'penaliza_tarde' => true, 'penaliza_no_show' => true]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // A petición del cliente (si cancela el negocio, nunca se penaliza).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", ['por' => 'cliente'], conBearer($e['bearer']))->assertOk();

    // Tardia y penalizada: el credito se consume (1000 = 1 sesion).
    expect(saldosDerechoPolitica($e, $vp['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
});

it('con penaliza_no_show=false un no-show devuelve el credito en vez de perderlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    configurarPoliticaCancelacion($e, ['horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => false]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))
        ->assertCreated();

    // No-show sin penalizacion: el credito retenido vuelve al miembro.
    expect(saldosDerechoPolitica($e, $vp['persona']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
});

it('el override por actividad prevalece sobre la politica global', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Global penaliza tarde; el override de la actividad, no.
    configurarPoliticaCancelacion($e, ['horas_limite' => 720, 'penaliza_tarde' => true, 'penaliza_no_show' => true]);

    // Semilla capturando el ulid de la actividad para el override.
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Pole'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Pole Sport'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $oferta = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", ['nombre' => 'Nivel 1', 'modalidad' => 'grupal', 'capacidad' => 12], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Org'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $sucursal = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", ['nombre' => 'Roma Norte', 'zona_horaria' => 'America/Mexico_City'], conBearer($e['bearer']))->assertCreated()->json('data.id');

    configurarPoliticaCancelacion($e, ['actividad_id' => $actividad, 'horas_limite' => 720, 'penaliza_tarde' => false, 'penaliza_no_show' => true]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $sucursal], 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))->assertOk();

    // El override (sin penalizacion) gana sobre la global: credito devuelto.
    expect(saldosDerechoPolitica($e, $vp['persona']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
});

it('la reserva usa el snapshot de la politica: cambiarla despues no afecta reservas ya hechas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    configurarPoliticaCancelacion($e, ['horas_limite' => 720, 'penaliza_tarde' => true, 'penaliza_no_show' => true]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // Se ablanda la politica DESPUES de reservar.
    configurarPoliticaCancelacion($e, ['horas_limite' => 720, 'penaliza_tarde' => false, 'penaliza_no_show' => true]);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", ['por' => 'cliente'], conBearer($e['bearer']))->assertOk();

    // Se aplica el snapshot (penalizaba) y no la config nueva: credito consumido.
    expect(saldosDerechoPolitica($e, $vp['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
});
