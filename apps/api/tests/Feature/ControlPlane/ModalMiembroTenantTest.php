<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Application\ExportarDatosPersonaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\NotaPersonaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| El detalle de un cliente en Miembros (el modal): estadísticas del periodo, notas
| internas del equipo (con su alcance y sus derechos ARCO), la última clase en el
| listado, el orden por nombre y la exportación a CSV.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Reserva a la persona en la sesión (como el negocio) y devuelve el id de la reserva.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function reservarParaModalMiembro(array $e, string $sesion, string $persona): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * Corre algo en la base del negocio.
 *
 * @template T
 *
 * @param  callable(): T  $hacer
 * @return T
 */
function enNegocioModalMiembro(string $slug, callable $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), $hacer);
}

it('el resumen cuenta lo del periodo: asistencias, reservadas, canceladas y faltas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    $r1 = reservarParaModalMiembro($e, crearSesionTenant($e, $semilla, 10, '2026-10-01 08:00:00'), $ana);
    $r2 = reservarParaModalMiembro($e, crearSesionTenant($e, $semilla, 10, '2026-10-01 09:00:00'), $ana);
    $r3 = reservarParaModalMiembro($e, crearSesionTenant($e, $semilla, 10, '2026-10-01 10:00:00'), $ana);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$r1}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$r2}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$r3}/cancelar", [], conBearer($e['bearer']))->assertOk();

    // Antes de que pasen, no cuentan (el periodo es hacia atrás desde ahora).
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estadisticas.reservadas', 0);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/Mexico_City'));
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen?dias=30", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estadisticas', [
            'dias' => 30, 'asistencias' => 1, 'reservadas' => 3, 'canceladas' => 1, 'no_asistio' => 1,
        ]);

    // Ocho días después, en una ventana de 7 días ya no hay nada.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'America/Mexico_City'));
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen?dias=7", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estadisticas.reservadas', 0)->assertJsonPath('data.estadisticas.dias', 7);
});

it('el equipo anota y borra notas de un cliente; quien imparte solo lee las de sus clientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    $beto = crearMiembroTenant($e, 'Beto');
    $coachBearer = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $sesion = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    reservarParaModalMiembro($e, $sesion, $ana);

    $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", ['texto' => ''], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.texto.0', fn (string $m): bool => $m !== '');
    $nota = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", ['texto' => '  Prefiere clases por la tarde.  '], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.texto', 'Prefiere clases por la tarde.')
        ->json('data.id');

    // Quien imparte ve las notas de su cliente, pero no escribe ni ve las de otros.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", conBearer($coachBearer))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nota);
    $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", ['texto' => 'Hola'], conBearer($coachBearer))->assertStatus(403);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/notas", conBearer($coachBearer))->assertStatus(403);

    // Una nota de otra persona no se borra por esta ruta.
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/notas/{$nota}", [], conBearer($e['bearer']))->assertNotFound();
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas/{$nota}", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('las notas salen en la exportación de sus datos y se borran si pide cancelarlos (ARCO)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/notas", ['texto' => 'Lesión en el hombro.'], conBearer($e['bearer']))->assertCreated();

    $exportadas = enNegocioModalMiembro($e['slug'], fn (): array => app(ExportarDatosPersonaTenant::class)
        ->para(PersonaTenant::query()->where('ulid', $ana)->sole())['notas_del_negocio']);
    expect($exportadas)->toHaveCount(1)->and($exportadas[0]['texto'])->toBe('Lesión en el hombro.');

    $quedan = enNegocioModalMiembro($e['slug'], function () use ($ana): int {
        $persona = PersonaTenant::query()->where('ulid', $ana)->sole();
        $bajas = app(BajaDePersonaTenant::class);
        $bajas->atender($bajas->solicitar($persona, null), null);

        return NotaPersonaTenant::query()->count();
    });
    expect($quedan)->toBe(0);
});

it('el listado trae la última clase con su nombre y se ordena por nombre', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto')['persona'];
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    $reserva = reservarParaModalMiembro($e, crearSesionTenant($e, $semilla, 10, '2026-10-01 08:00:00'), $ana);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/miembros?tipo=miembro&page=1&resumen=1&orden=nombre", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $ana)
        ->assertJsonPath('data.1.id', $beto)
        ->assertJsonPath('data.0.resumen.ultima.clase', 'Nivel 1')
        ->assertJsonPath('data.1.resumen.ultima', null)
        ->json('data.0.resumen.ultima.inicia_en');
    expect(CarbonImmutable::parse($lista)->equalTo(CarbonImmutable::parse('2026-10-01 08:00', 'America/Mexico_City')))->toBeTrue();

    $this->getJson("/api/v1/app/{$e['slug']}/miembros?tipo=miembro&page=1&orden=-nombre", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.id', $beto);
});

it('exporta a CSV lo que se ve (con BOM, sin fórmulas) y queda en la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    crearMiembroTenant($e, 'Beto');
    crearMiembroTenant($e, '=HIPERVINCULO("x")');
    $coachBearer = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $csv = $this->get("/api/v1/app/{$e['slug']}/miembros/exportar?q=Ana", conBearer($e['bearer']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();
    expect($csv)->toStartWith("\u{FEFF}Nombre,Correo,Celular,Sucursal,Plan,Vence,Saldo,Ultima clase,Estado,Alta\n")
        ->and($csv)->toContain('Ana')
        ->and($csv)->not->toContain('Beto');

    // Un nombre que Excel tomaría por fórmula sale como texto.
    $todos = (string) $this->get("/api/v1/app/{$e['slug']}/miembros/exportar", conBearer($e['bearer']))->assertOk()->getContent();
    expect($todos)->toContain("\"'=HIPERVINCULO(\"\"x\"\")\"");

    // Exportar datos personales es para quien gestiona clientes.
    $this->get("/api/v1/app/{$e['slug']}/miembros/exportar", conBearer($coachBearer))->assertStatus(403);

    $registradas = enNegocioModalMiembro($e['slug'], fn (): int => DB::connection('tenant')->table('auditorias')->where('accion', 'miembros.exportados')->count());
    expect($registradas)->toBe(2);
});
