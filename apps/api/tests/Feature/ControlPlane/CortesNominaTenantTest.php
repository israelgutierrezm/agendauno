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

    $ajustado = $this->getJson("{$url}/{$corte}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 40000)
        ->assertJsonPath('data.ajustes_minor', 20000)
        ->assertJsonPath('data.total_con_ajustes_minor', 60000)
        ->assertJsonPath('data.ajustes.0.motivo', 'Clase del 3 registrada tarde')
        ->json('data');
    // Ya ajustada, la diferencia no sigue pidiendo compensarse (R09): lo que falta es 0 y
    // la diferencia contra el corte original queda aparte.
    expect($ajustado['diferencias'][0])->toMatchArray([
        'en_corte_minor' => 40000, 'ajustes_minor' => 20000, 'reconocido_minor' => 60000,
        'hoy_minor' => 60000, 'diferencia_minor' => 0, 'diferencia_original_minor' => 20000,
    ]);

    $descripciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=equipo", conBearer($e['bearer']))->json('data'))->pluck('descripcion');
    expect($descripciones)->toContain('Cerró la nómina del 2026-10-01 al 2026-10-04')
        ->toContain('Registró el pago de la nómina del 2026-10-01 al 2026-10-04 (referencia SPEI 0099)')
        ->toContain('Ajustó la nómina del 2026-10-01 al 2026-10-04 de Personal: Clase del 3 registrada tarde');
});

it('la tarifa nueva no vale dentro de lo ya cerrado: no se sugiere pagar dos veces', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $datos = nominaConDosClasesImpartidas($e);
    $url = "/api/v1/app/{$e['slug']}/nomina/cortes";
    $corte = (string) $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $tarifa = fn (string $desde) => $this->putJson("/api/v1/app/{$e['slug']}/staff/{$datos['coach']}/esquema-pago", [
        'tipo' => 'por_clase', 'monto_minor' => 30000, 'moneda' => 'MXN', 'vigente_desde' => $desde,
    ], conBearer($e['bearer']));

    $tarifa('2026-10-02')->assertStatus(422)->assertJsonValidationErrors(['vigente_desde'], 'meta.errors')
        ->assertJsonPath('meta.errors.vigente_desde.0', 'La nómina de esta persona ya se cerró hasta el 04/10/2026: la tarifa nueva puede valer desde el 05/10/2026.');
    $tarifa('2026-10-05')->assertCreated();

    // El periodo cerrado se queda como se cerró: sin diferencias por la tarifa nueva.
    $detalle = $this->getJson("{$url}/{$corte}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($detalle['total_minor'])->toBe(40000)
        ->and(collect($detalle['diferencias'])->sum('diferencia_minor'))->toBe(0);
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

it('pago por asistente: corregir la asistencia después de pagar no cambia el corte ni pide pagar dos veces (ADR 0166)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = instructorConUlidParaCortes($e);
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'por_asistente', 'monto_minor' => 10000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, $semilla, 10, '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    $reservar = fn (string $nombre): string => (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => venderPackAMiembroTenant($e, 8000, $nombre)['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $ana = $reservar('Ana');
    $bea = $reservar('Bea');
    $marcar = fn (string $reserva, string $estado) => $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => $estado], conBearer($e['bearer']))->assertCreated();

    // En la clase solo se registra a Ana; se cierra y se paga: 1 asistente.
    $this->travelTo('2026-10-01 14:30:00');
    $marcar($ana, 'presente');
    $this->travelTo('2026-10-05 12:00:00');
    $url = "/api/v1/app/{$e['slug']}/nomina/cortes";
    $corte = (string) $this->postJson($url, ['desde' => '2026-10-01', 'hasta' => '2026-10-04'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 10000)->json('data.id');
    $this->postJson("{$url}/{$corte}/pagar", ['referencia' => 'SPEI 1'], conBearer($e['bearer']))->assertOk();
    $diferencia = fn (): array => $this->getJson("{$url}/{$corte}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 10000)->json('data.diferencias.0') ?? [];

    // Se registra tarde que Bea también vino: falta pagar una vez, no dos.
    $marcar($bea, 'presente');
    expect($diferencia())->toMatchArray(['en_corte_minor' => 10000, 'hoy_minor' => 20000, 'diferencia_minor' => 10000]);
    $this->postJson("{$url}/{$corte}/ajustes", ['usuario_id' => $coach, 'monto_minor' => 10000, 'motivo' => 'Bea registrada tarde'], conBearer($e['bearer']))->assertCreated();
    expect($diferencia())->toMatchArray(['ajustes_minor' => 10000, 'diferencia_minor' => 0]);

    // Si después se corrige (no vino), solo aparece lo que cambió: se reconoció de más.
    $marcar($bea, 'ausente');
    expect($diferencia())->toMatchArray(['reconocido_minor' => 20000, 'hoy_minor' => 10000, 'diferencia_minor' => -10000]);
});
