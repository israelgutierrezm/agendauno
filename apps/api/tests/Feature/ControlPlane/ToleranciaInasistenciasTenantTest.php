<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Tolerancia de inasistencias (ADR 0043): las primeras faltas dentro de la ventana
| de días no cobran el crédito; a partir de la siguiente, sí. El negocio la ajusta en
| su política (general o por actividad) y el superadmin fija la de quien no tiene.
| Hoy es 1 de enero de 2030.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alumna con 8 créditos; devuelve persona y derecho.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, derecho: string}
 */
function alumnaParaFaltar(array $e): array
{
    $persona = crearMiembroTenant($e, 'Ana');
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000)], conBearer($e['bearer']))
        ->assertCreated()->json('data.derecho.id');

    return ['persona' => $persona, 'derecho' => $derecho];
}

/**
 * Reserva una clase en esa fecha y la marca como falta; devuelve el saldo después.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $sede
 * @param  array{persona: string, derecho: string}  $ana
 */
function faltaA(array $e, array $sede, array $ana, string $cuando): int
{
    $sesion = crearSesionTenant($e, $sede, 5, $cuando);
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))->assertCreated();

    return (int) test()->getJson("/api/v1/app/{$e['slug']}/derechos/{$ana['derecho']}/movimientos", conBearer($e['bearer']))->json('saldo');
}

it('tolera las primeras faltas de la ventana y cobra las siguientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", [
        'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true,
        'tolerancia_no_show' => 2, 'ventana_no_show_dias' => 30,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.ventana_no_show_dias', 30);
    $sede = agendaSemilla($e);
    $ana = alumnaParaFaltar($e);

    expect(faltaA($e, $sede, $ana, '2030-01-02 08:00:00'))->toBe(8000)   // 1.ª: tolerada
        ->and(faltaA($e, $sede, $ana, '2030-01-03 08:00:00'))->toBe(8000) // 2.ª: tolerada
        ->and(faltaA($e, $sede, $ana, '2030-01-04 08:00:00'))->toBe(7000); // 3.ª: se cobra
});

it('las faltas fuera de la ventana ya no cuentan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", [
        'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true,
        'tolerancia_no_show' => 1, 'ventana_no_show_dias' => 7,
    ], conBearer($e['bearer']))->assertCreated();
    $sede = agendaSemilla($e);
    $ana = alumnaParaFaltar($e);

    expect(faltaA($e, $sede, $ana, '2030-01-02 08:00:00'))->toBe(8000);
    // Tres semanas después: la anterior quedó fuera de los 7 días; vuelve a tolerarse.
    $this->travelTo('2030-01-22 12:00:00');
    expect(faltaA($e, $sede, $ana, '2030-01-23 08:00:00'))->toBe(8000)
        ->and(faltaA($e, $sede, $ana, '2030-01-24 08:00:00'))->toBe(7000);
});

it('corregir una falta tolerada a "llegó" cobra, y volver a falta la vuelve a tolerar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", [
        'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true, 'tolerancia_no_show' => 1,
    ], conBearer($e['bearer']))->assertCreated();
    $ana = alumnaParaFaltar($e);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-02 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))->json('data.id');
    $marcar = fn (string $estado) => $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => $estado], conBearer($e['bearer']))->assertCreated();
    $saldo = fn (): int => (int) $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$ana['derecho']}/movimientos", conBearer($e['bearer']))->json('saldo');

    $marcar('ausente');
    expect($saldo())->toBe(8000);
    $marcar('presente');
    expect($saldo())->toBe(7000);
    $marcar('ausente');
    expect($saldo())->toBe(8000);
});

it('sin política propia aplica la tolerancia que fija la plataforma, y el negocio la ve', function (): void {
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['cancelacion.tolerancia_no_show' => 1]], conPlataforma())->assertOk();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('por_defecto.tolerancia_no_show', 1)->assertJsonPath('por_defecto.ventana_no_show_dias', 30);
    $sede = agendaSemilla($e);
    $ana = alumnaParaFaltar($e);

    expect(faltaA($e, $sede, $ana, '2030-01-02 08:00:00'))->toBe(8000)
        ->and(faltaA($e, $sede, $ana, '2030-01-03 08:00:00'))->toBe(7000);
});
