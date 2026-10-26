<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Clases recurrentes sin fecha final, su fin y cancelar varias fechas (ADR 0223): sin
| fecha final se generan hasta el horizonte del negocio y la tarea diaria las sigue
| extendiendo; el fin se mueve hacia adelante (para terminarla antes se cancelan sus
| fechas desde un día); al cerrar un día se pueden cancelar sus clases ya programadas.
| Cada fecha cancelada devuelve créditos y avisa, como si se cancelara sola.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Lo de la base del negocio de la prueba. */
function enNegocioSeries(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-series')->sole(), $hacer);
}

/**
 * Una clase de lunes a domingo a las 18:00, sin fecha final, con sus fechas generadas.
 *
 * @return array{e: array{slug: string, bearer: string}, semilla: array<string, string>, serie: string}
 */
function serieSinFin(): array
{
    $e = estudioConSesion('estudio-series', 'duena@series.mx');
    $semilla = agendaSemilla($e);
    $serie = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'hora_local' => '18:00', 'duracion_minutos' => 60,
        'capacidad' => 10, 'vigente_desde' => CarbonImmutable::now('America/Mexico_City')->toDateString(), 'vigente_hasta' => null,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", [
        'desde' => CarbonImmutable::now('America/Mexico_City')->toDateString(),
    ], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'semilla' => $semilla, 'serie' => $serie];
}

/** La fecha local de dentro de n días. */
function diaSeries(int $n): string
{
    return CarbonImmutable::now('America/Mexico_City')->addDays($n)->toDateString();
}

it('sin fecha final genera hasta el horizonte del negocio y el fin solo se mueve hacia adelante', function (): void {
    ['e' => $e, 'serie' => $serie] = serieSinFin();
    // Hoy a las 18:00 y los 30 días siguientes.
    expect(enNegocioSeries(fn () => SesionTenant::query()->count()))->toBe(31);

    $fin = "/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/fin";
    $this->putJson($fin, ['vigente_hasta' => diaSeries(10)], conBearer($e['bearer']))->assertStatus(422);
    $this->putJson($fin, ['vigente_hasta' => diaSeries(40)], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.vigente_hasta', diaSeries(40));
    $this->putJson($fin, ['vigente_hasta' => null], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.vigente_hasta', null);
});

it('cancelar esta y las siguientes: dice a cuántas personas afecta, cancela y devuelve créditos', function (): void {
    ['e' => $e, 'serie' => $serie] = serieSinFin();
    $vp = venderPackAMiembroTenant($e, 3000);
    $sesion = (string) enNegocioSeries(fn () => SesionTenant::query()->whereDate('fecha_serie', diaSeries(6))->value('ulid'));
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $terminar = "/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/terminar";
    $this->postJson($terminar, ['desde' => diaSeries(5), 'previsualizar' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sesiones', 26)->assertJsonPath('data.reservas', 1);
    $this->postJson($terminar, ['desde' => diaSeries(5)], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.canceladas', 26);

    expect(enNegocioSeries(fn () => ReservaTenant::query()->value('estado')?->value))->toBe('cancelada')
        ->and(enNegocioSeries(fn () => SesionTenant::query()->where('estado', 'programada')->count()))->toBe(5);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/derechos", conBearer($e['bearer']))->assertJsonPath('data.0.disponible', 3000);
    // Ya no se repite desde ahí: la tarea diaria no las vuelve a crear.
    $this->artisan('agendauno:generar-agenda')->assertSuccessful();
    expect(enNegocioSeries(fn () => SesionTenant::query()->where('estado', 'programada')->count()))->toBe(5);
});

it('al cerrar un día se pueden cancelar sus clases ya programadas', function (): void {
    ['e' => $e] = serieSinFin();
    $app = "/api/v1/app/{$e['slug']}";

    $this->getJson("{$app}/excepciones-horario/afectadas?fecha=".diaSeries(3), conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sesiones', 1)->assertJsonPath('data.reservas', 0);
    // Sin pedirlo, la fecha ya programada se queda.
    $this->postJson("{$app}/excepciones-horario", ['fecha' => diaSeries(3), 'motivo' => 'Puente'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('meta.cancelacion', null);
    $this->postJson("{$app}/excepciones-horario", ['fecha' => diaSeries(4), 'cancelar_sesiones' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('meta.cancelacion.canceladas', 1);

    expect(enNegocioSeries(fn () => SesionTenant::query()->whereDate('fecha_serie', diaSeries(3))->value('estado')?->value))->toBe('programada')
        ->and(enNegocioSeries(fn () => SesionTenant::query()->whereDate('fecha_serie', diaSeries(4))->value('estado')?->value))->toBe('cancelada');
});
