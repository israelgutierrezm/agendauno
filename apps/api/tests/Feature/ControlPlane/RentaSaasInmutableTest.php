<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| 1.6 de la fase 1: el cargo de renta del SaaS es estable y explicable. Se emite solo
| cuando el mes cerró en la zona del negocio, con la tarifa vigente en ese mes, guarda
| con qué se calculó, y una vez emitido no cambia aunque después cambien la tarifa o
| la prueba gratis, ni al volver a correr el proceso.
| El negocio está en CDMX (UTC-6): el 1 de enero a las 03:00 UTC aún es 31 de diciembre.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('turnouno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio de clases con la prueba gratis terminada y una alumna activa en diciembre.
 *
 * @return array{slug: string, bearer: string}
 */
function negocioActivoEnDiciembre(): array
{
    test()->travelTo('2029-12-20 18:00:00');
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));

    return $e;
}

function cargoDeDiciembre(): ?CargoRenta
{
    return CargoRenta::query()
        ->where('estudio_id', Estudio::query()->where('slug', 'pilates-a')->value('id'))
        ->where('periodo', '2029-12')
        ->first();
}

function publicarTarifaNueva(): void
{
    test()->postJson('/api/v1/plataforma/tarifas/clases', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16,
        'bandas' => [['hasta' => 50, 'monto_minor' => 29900], ['hasta' => null, 'monto_minor' => 99900]],
    ], conPlataforma())->assertCreated()->assertJsonPath('data.version', 2);
}

it('no emite el cargo de un mes que aún no cierra en la zona del negocio', function (): void {
    negocioActivoEnDiciembre();

    // 03:00 UTC del 1 de enero = 21:00 del 31 de diciembre en CDMX: diciembre sigue abierto.
    $this->travelTo('2030-01-01 03:00:00');
    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();
    $this->artisan('turnouno:generar-cargos-renta', ['--periodo' => '2029-12'])->assertSuccessful();
    expect(cargoDeDiciembre())->toBeNull();

    // 08:00 UTC = 02:00 del 1 de enero en CDMX: diciembre cerró.
    $this->travelTo('2030-01-01 08:00:00');
    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();

    $cargo = cargoDeDiciembre();
    expect($cargo)->not->toBeNull()
        ->and($cargo->alumnos_activos)->toBe(1)
        ->and($cargo->emitido_en)->not->toBeNull()
        ->and($cargo->regla_version)->not->toBeNull()
        ->and($cargo->medicion_id)->not->toBeNull();
    $this->assertDatabaseHas('mediciones_uso', ['id' => $cargo->medicion_id, 'periodo' => '2029-12', 'congelada' => true]);
});

it('un cargo emitido no cambia aunque después cambien la tarifa o la prueba gratis', function (): void {
    negocioActivoEnDiciembre();
    $this->travelTo('2030-01-02 12:00:00');
    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();
    $antes = cargoDeDiciembre()?->only(['monto_minor', 'tarifa_version', 'estado', 'emitido_en', 'desglose']);

    publicarTarifaNueva();
    Estudio::query()->where('slug', 'pilates-a')->update(['trial_termina_en' => '2030-12-31']);
    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();
    $this->artisan('turnouno:generar-cargos-renta', ['--periodo' => '2029-12'])->assertSuccessful();

    expect(cargoDeDiciembre()?->only(['monto_minor', 'tarifa_version', 'estado', 'emitido_en', 'desglose']))->toEqual($antes)
        ->and(CargoRenta::query()->where('periodo', '2029-12')->count())->toBe(1);
});

it('se cobra con la tarifa vigente en el mes, no con una publicada después', function (): void {
    negocioActivoEnDiciembre();
    $this->travelTo('2030-01-02 12:00:00');
    publicarTarifaNueva(); // vigente desde el 2 de enero

    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();

    expect(cargoDeDiciembre()?->tarifa_version)->toBe(1);
});
