<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Cortes de nómina: el periodo abierto se calcula al vuelo; al cerrarlo queda lo
| devengado por persona y quién lo cerró; después se registra el pago con su
| referencia y lo que cambie se ajusta aparte, sin tocar lo pagado.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** @param  array{slug: string, bearer: string}  $e */
function instructorConUlidParaCortes(array $e): string
{
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $lista = test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data');

    return (string) collect($lista)->firstWhere('nombre', 'Personal')['id'];
}

/**
 * Un coach a 200.00 por clase con dos clases impartidas (1 y 2 de octubre) y una
 * tercera el 3 que todavía no tiene a nadie. Devuelve el coach y esa tercera clase.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{coach: string, tercera: string}
 */
function nominaConDosClasesImpartidas(array $e): array
{
    $semilla = agendaSemilla($e);
    $coach = instructorConUlidParaCortes($e);
    test()->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'por_clase', 'monto_minor' => 20000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();
    foreach (['2026-10-01 08:00:00', '2026-10-02 08:00:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, cuando: $cuando);
        test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    }
    $tercera = crearSesionTenant($e, $semilla, cuando: '2026-10-03 08:00:00');
    test()->travelTo('2026-10-05 12:00:00');

    return ['coach' => $coach, 'tercera' => $tercera];
}

it('cerrar un periodo guarda lo devengado por persona y quién lo cerró; solo si ya terminó y sin encimarse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    nominaConDosClasesImpartidas($e);
    $url = "/api/v1/app/{$e['slug']}/nomina/cortes";

    $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-05'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['hasta'], 'meta.errors');

    $corte = $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'cerrado')
        ->assertJsonPath('data.total_minor', 40000)
        ->assertJsonPath('data.moneda', 'MXN')
        ->json('data');
    expect($corte['cerrado_por'])->not->toBeNull();

    $this->postJson($url, ['desde' => '2026-10-03', 'hasta' => '2026-10-04'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['desde'], 'meta.errors');

    $detalle = $this->getJson("{$url}/{$corte['id']}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($detalle['lineas'])->toHaveCount(1)
        ->and($detalle['lineas'][0])->toMatchArray(['tipo' => 'por_clase', 'monto_minor' => 20000, 'total_minor' => 40000])
        ->and($detalle['lineas'][0]['unidades'])->toEqual(2)
        ->and($detalle['diferencias'])->toBe([]);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 1);
});

it('lo pagado no se reabre: lo que cambie después se ajusta aparte y se ve la diferencia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $datos = nominaConDosClasesImpartidas($e);
    $url = "/api/v1/app/{$e['slug']}/nomina/cortes";
    $corte = (string) $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Antes de pagar no hay ajustes: se reabre y se vuelve a cerrar.
    $this->postJson("{$url}/{$corte}/ajustes", ['usuario_id' => $datos['coach'], 'monto_minor' => 20000, 'motivo' => 'Clase del 3'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['corte'], 'meta.errors');

    $this->postJson("{$url}/{$corte}/pagar", ['referencia' => 'SPEI 0099'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'pagado')->assertJsonPath('data.referencia_pago', 'SPEI 0099');
    $this->postJson("{$url}/{$corte}/pagar", ['referencia' => 'otra'], conBearer($e['bearer']))->assertStatus(422);
    $this->deleteJson("{$url}/{$corte}", [], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['corte'], 'meta.errors');

    // Se registra tarde que también dio la clase del 3: el corte no cambia, la diferencia se ve.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$datos['tercera']}/staff", ['usuario_id' => $datos['coach'], 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    $detalle = $this->getJson("{$url}/{$corte}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($detalle['total_minor'])->toBe(40000)
        ->and($detalle['diferencias'])->toHaveCount(1)
        ->and($detalle['diferencias'][0])->toMatchArray(['usuario_id' => $datos['coach'], 'en_corte_minor' => 40000, 'hoy_minor' => 60000, 'diferencia_minor' => 20000]);

    $this->postJson("{$url}/{$corte}/ajustes", ['usuario_id' => $datos['coach'], 'monto_minor' => 0, 'motivo' => 'x'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['monto_minor'], 'meta.errors');
    $this->postJson("{$url}/{$corte}/ajustes", ['usuario_id' => $datos['coach'], 'monto_minor' => 20000, 'motivo' => 'Clase del 3 registrada tarde'], conBearer($e['bearer']))
        ->assertCreated();

    $this->getJson("{$url}/{$corte}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 40000)
        ->assertJsonPath('data.ajustes_minor', 20000)
        ->assertJsonPath('data.total_con_ajustes_minor', 60000)
        ->assertJsonPath('data.ajustes.0.motivo', 'Clase del 3 registrada tarde');

    $descripciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=equipo", conBearer($e['bearer']))->json('data'))->pluck('descripcion');
    expect($descripciones)->toContain('Cerró la nómina del 2026-10-01 al 2026-10-04')
        ->toContain('Registró el pago de la nómina del 2026-10-01 al 2026-10-04 (referencia SPEI 0099)')
        ->toContain('Ajustó la nómina del 2026-10-01 al 2026-10-04 de Personal: Clase del 3 registrada tarde');
});

it('un periodo cerrado sin pagar se reabre y vuelve a calcularse al vuelo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    nominaConDosClasesImpartidas($e);
    $url = "/api/v1/app/{$e['slug']}/nomina/cortes";
    $corte = (string) $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->deleteJson("{$url}/{$corte}", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))->assertCreated();

    $descripciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=equipo", conBearer($e['bearer']))->json('data'))->pluck('descripcion');
    expect($descripciones)->toContain('Reabrió la nómina del 2026-10-01 al 2026-10-04');
});

it('los cortes exigen estudio.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/nomina/cortes", conBearer($instructor))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/nomina/cortes", ['desde' => '2026-09-01', 'hasta' => '2026-09-15'], conBearer($instructor))->assertForbidden();
});

it('la sesión dice si el negocio trabaja con equipo (para no ofrecerle la nómina a quien trabaja solo)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.con_equipo', false);

    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.con_equipo', true);
});
