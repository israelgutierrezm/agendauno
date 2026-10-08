<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TipoCambio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Plan de un negocio de citas (ADR 0107): Individual, Premium o Pro por profesionales
| contratados, POR ADELANTADO y por periodos de calendario (mensual, o anual a 10
| meses). Subir cobra la diferencia de los días que faltan; bajar o cambiar a anual,
| desde el siguiente periodo. Precios en USD; a 20 pesos por dólar (tests/Pest.php).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Corre el emisor de la renta en ese momento (hora de la Ciudad de México). */
function emitirRentaEl(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/Mexico_City'));
    test()->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
}

function cargoDelPlan(string $periodo): CargoRenta
{
    return CargoRenta::query()->where('concepto', 'plan')->where('periodo', $periodo)->sole();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function cambiarPlanCitas(array $e, string $nivel, int $profesionales, string $periodicidad = 'mensual'): TestResponse
{
    return test()->putJson("/api/v1/app/{$e['slug']}/renta/plan", [
        'nivel' => $nivel, 'profesionales' => $profesionales, 'periodicidad' => $periodicidad,
    ], conBearer($e['bearer']));
}

it('al terminar la prueba sin elegir plan queda en Individual: el primer mes se prorratea y después se cobra cada mes por adelantado', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    Estudio::query()->where('slug', $e['slug'])->update(['trial_termina_en' => '2026-10-15']);

    emitirRentaEl('2026-10-16 12:00');
    $octubre = cargoDelPlan('2026-10');
    // 9 USD × 16/31 = 4.64 USD → 92.80 pesos + IVA.
    expect($octubre->cubre_desde?->toDateString())->toBe('2026-10-16')
        ->and($octubre->cubre_hasta?->toDateString())->toBe('2026-10-31')
        ->and($octubre->desglose['prorrateo'])->toBe(['dias_cobrables' => 16, 'dias_periodo' => 31])
        ->and($octubre->desglose['lineas'][0]['concepto'])->toBe('Plan Individual · 1 profesional')
        ->and($octubre->monto_tarifa_minor)->toBe(464 + 74)
        ->and($octubre->monto_minor)->toBe(9280 + 1485)
        ->and($octubre->moneda)->toBe('MXN');

    emitirRentaEl('2026-11-01 02:00');
    expect(cargoDelPlan('2026-11'))
        ->monto_minor->toBe(18000 + 2880)
        ->and(cargoDelPlan('2026-11')->cubre_hasta?->toDateString())->toBe('2026-11-30');

    // Correrlo otra vez no cobra de nuevo.
    emitirRentaEl('2026-11-01 09:00');
    expect(CargoRenta::query()->where('concepto', 'plan')->count())->toBe(2)
        ->and(Estudio::query()->where('slug', $e['slug'])->value('plan_nivel'))->toBe('individual');
});

it('sin elegir plan y con varios profesionales queda en Premium con los que tiene', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    foreach (['beto', 'carlos', 'diego'] as $nombre) {
        personalConSesion($e['slug'], $e['bearer'], "{$nombre}@barberia.mx", 'instructor');
    }

    emitirRentaEl('2026-11-01 09:00');

    expect(cargoDelPlan('2026-11')->desglose['lineas'][0]['concepto'])->toBe('Plan Premium · 3 profesionales')
        ->and(cargoDelPlan('2026-11')->monto_tarifa_minor)->toBe(2800 + 448);
});

it('en la prueba elegir plan no cobra nada; al terminar se cobra lo elegido', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    cambiarPlanCitas($e, 'pro', 2)
        ->assertOk()
        ->assertJsonPath('data.aplica', 'ahora')
        ->assertJsonPath('data.ajuste', null)
        ->assertJsonPath('data.plan.nivel', 'pro')
        ->assertJsonPath('data.plan.en_prueba', true)
        ->assertJsonPath('data.plan.limite_profesionales', 20);

    $renta = $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk()->json('data');
    expect($renta['cobro'])->toBe('plan')
        ->and($renta['tarifa'])->toBeNull()
        ->and($renta['plan']['moneda_cobro'])->toBe('MXN')
        ->and($renta['plan']['precios']['pro']['2'])->toBe(3300)
        ->and($renta['actual']['cubre_desde'])->toBe('2026-11-01')
        ->and($renta['actual']['cargo_estimado_minor'])->toBe(66000 + 10560)
        ->and($renta['cargos'])->toBe([]);

    emitirRentaEl('2026-11-01 09:00');
    expect(cargoDelPlan('2026-11')->monto_minor)->toBe(66000 + 10560);
});

it('en la prueba se elige plan aunque aún no haya tipo de cambio (no se cobra nada)', function (): void {
    TipoCambio::query()->delete();
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    cambiarPlanCitas($e, 'premium', 2, 'anual')->assertOk()->assertJsonPath('data.plan.periodicidad', 'anual');
    // Mientras tanto, lo que sigue se estima en dólares.
    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.actual.desglose.moneda', 'USD')
        ->assertJsonPath('data.actual.cargo_estimado_minor', 24000 + 3840);
});

it('subir de plan cobra la diferencia de los días que faltan; no se suman profesionales de más; bajar o pasar a anual aplica el siguiente periodo', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    emitirRentaEl('2026-10-01 09:00');
    expect(cargoDelPlan('2026-10')->monto_minor)->toBe(18000 + 2880);

    // Individual: un profesional.
    personalConSesion($e['slug'], $e['bearer'], 'beto@barberia.mx', 'instructor');
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Carlos', 'email' => 'carlos@barberia.mx', 'rol' => 'instructor'], conBearer($e['bearer']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROFESSIONAL_SEATS_EXCEEDED')
        ->assertJsonPath('meta.contratados', 1);

    // El 11 sube a Premium con 3: (28 − 9) USD × 21/31 días = 12.87 USD.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'America/Mexico_City'));
    cambiarPlanCitas($e, 'premium', 3)
        ->assertOk()
        ->assertJsonPath('data.aplica', 'ahora')
        ->assertJsonPath('data.ajuste.moneda', 'MXN')
        ->assertJsonPath('data.ajuste.monto_minor', 25740 + 4118)
        ->assertJsonPath('data.plan.limite_profesionales', 3);
    $ajuste = CargoRenta::query()->where('concepto', 'ajuste')->sole();
    expect($ajuste->cubre_desde?->toDateString())->toBe('2026-10-11')
        ->and($ajuste->cubre_hasta?->toDateString())->toBe('2026-10-31')
        ->and($ajuste->estado->value)->toBe('pendiente');

    personalConSesion($e['slug'], $e['bearer'], 'carlos@barberia.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'diego@barberia.mx', 'instructor');
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Eva', 'email' => 'eva@barberia.mx', 'rol' => 'instructor'], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('meta.contratados', 3);
    // Quien no atiende no ocupa lugar.
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Fer', 'email' => 'fer@barberia.mx', 'rol' => 'recepcionista'], conBearer($e['bearer']))
        ->assertCreated();

    // No se contratan menos de los que tiene.
    cambiarPlanCitas($e, 'premium', 2)->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_ALLOWED');
    // Pasar a anual (mismo precio al mes) aplica desde el siguiente periodo.
    cambiarPlanCitas($e, 'premium', 3, 'anual')
        ->assertOk()
        ->assertJsonPath('data.aplica', 'siguiente')
        ->assertJsonPath('data.ajuste', null)
        ->assertJsonPath('data.plan.periodicidad', 'mensual')
        ->assertJsonPath('data.plan.siguiente.periodicidad', 'anual');

    // Noviembre abre el año: 10 meses de Premium con 3 (28 USD).
    emitirRentaEl('2026-11-01 09:00');
    $anual = cargoDelPlan('2026-11');
    expect($anual->cubre_hasta?->toDateString())->toBe('2027-10-31')
        ->and($anual->monto_tarifa_minor)->toBe(28000 + 4480)
        ->and($anual->monto_minor)->toBe(560000 + 89600)
        ->and(Estudio::query()->where('slug', $e['slug'])->value('plan_periodicidad'))->toBe('anual');

    // Ya cubierto el año: diciembre no se cobra aparte.
    emitirRentaEl('2026-12-01 09:00');
    expect(CargoRenta::query()->where('concepto', 'plan')->count())->toBe(2);
});

it('solo quien gestiona el negocio cambia el plan y solo en negocios de citas', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@barberia.mx', 'recepcionista');
    $this->putJson("/api/v1/app/{$e['slug']}/renta/plan", ['nivel' => 'pro', 'profesionales' => 2, 'periodicidad' => 'mensual'], conBearer($recepcion))
        ->assertStatus(403);
    cambiarPlanCitas($e, 'individual', 2)->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_ALLOWED');
    cambiarPlanCitas($e, 'premium', 21)->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_ALLOWED');

    $clases = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    cambiarPlanCitas($clases, 'pro', 2)->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_ALLOWED');
});
