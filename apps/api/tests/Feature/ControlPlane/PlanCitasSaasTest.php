<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TipoCambio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
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
        // toEqual: en MySQL la columna JSON reordena las llaves.
        ->and($octubre->desglose['prorrateo'])->toEqual(['dias_cobrables' => 16, 'dias_periodo' => 31])
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

it('el superadmin ve el plan del negocio en su ficha y lo cambia, cobrando o no la diferencia', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    emitirRentaEl('2026-10-01 09:00'); // Individual, octubre pagado por adelantado
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'America/Mexico_City'));

    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.plan_citas.nivel', 'individual')
        ->assertJsonPath('data.plan_citas.cubierto_hasta', '2026-10-31');

    // Cortesía: sube a Premium con 3 hoy, sin cobrar los días que faltan.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/plan", [
        'nivel' => 'premium', 'profesionales' => 3, 'periodicidad' => 'mensual', 'cobrar_diferencia' => false,
    ], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.aplica', 'ahora')
        ->assertJsonPath('data.ajuste', null)
        ->assertJsonPath('data.plan.nivel', 'premium')
        ->assertJsonPath('data.plan.limite_profesionales', 3);
    expect(CargoRenta::query()->where('concepto', 'ajuste')->count())->toBe(0);

    // Con las reglas de siempre: subir a Pro cobra la diferencia.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/plan", [
        'nivel' => 'pro', 'profesionales' => 3, 'periodicidad' => 'mensual',
    ], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.aplica', 'ahora')
        ->assertJsonPath('data.ajuste.moneda', 'MXN');
    expect(CargoRenta::query()->where('concepto', 'ajuste')->count())->toBe(1);

    $clases = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $this->putJson("/api/v1/plataforma/estudios/{$clases['slug']}/plan", [
        'nivel' => 'pro', 'profesionales' => 2, 'periodicidad' => 'mensual',
    ], conPlataforma())->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_ALLOWED');
    $this->getJson("/api/v1/plataforma/estudios/{$clases['slug']}", conPlataforma())->assertOk()->assertJsonPath('data.plan_citas', null);
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/plan", ['nivel' => 'pro'], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('lo que no llega al cargo mínimo no se cobra: el periodo queda cubierto sin cargo y la subida aplica sin ajuste', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    // La prueba termina un día antes del fin de mes: 9 USD × 1/31 = 0.29 USD → 5.80 pesos + IVA (< 10 pesos).
    Estudio::query()->where('slug', $e['slug'])->update(['trial_termina_en' => '2026-10-30']);

    emitirRentaEl('2026-10-31 09:00');
    expect(cargoDelPlan('2026-10')->estado->value)->toBe('sin_cargo')
        ->and(cargoDelPlan('2026-10')->monto_minor)->toBeLessThan(1000)
        ->and(Estudio::query()->where('slug', $e['slug'])->sole()->plan_cubierto_hasta?->toDateString())->toBe('2026-10-31');
    // No cuenta para suspender.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00', 'America/Mexico_City')->addDays(40));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(Estudio::query()->where('slug', $e['slug'])->sole()->estado->value)->not->toBe('suspended');

    // Noviembre se cobra completo; el último día sube a Premium con 2: (24 − 9) USD × 1/30 = 0.50 USD
    // → 11.60 pesos con IVA, debajo del mínimo que fijó la plataforma (20 pesos).
    emitirRentaEl('2026-11-01 09:00');
    expect(cargoDelPlan('2026-11')->estado->value)->toBe('pendiente');
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['renta.cargo_minimo_mxn_centavos' => 2000]], conPlataforma())->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'America/Mexico_City'));
    cambiarPlanCitas($e, 'premium', 2)
        ->assertOk()
        ->assertJsonPath('data.aplica', 'ahora')
        ->assertJsonPath('data.ajuste', null)
        ->assertJsonPath('data.plan.nivel', 'premium');
    expect(CargoRenta::query()->where('concepto', 'ajuste')->count())->toBe(0);
});

it('un cargo emitido tarde vence a los días para pagar desde que se emite, no nace vencido', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $citas = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    Estudio::query()->where('slug', $citas['slug'])->update(['trial_termina_en' => '2026-09-30']);
    $clases = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($clases);
    $this->putJson("/api/v1/plataforma/estudios/{$clases['slug']}", ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900], conPlataforma())->assertOk();

    // El emisor no corrió hasta el 20 (p. ej. esperó el tipo de cambio).
    emitirRentaEl('2026-10-20 09:00');

    expect(cargoDelPlan('2026-10')->cubre_desde?->toDateString())->toBe('2026-10-01')
        ->and(cargoDelPlan('2026-10')->vence_en?->toDateString())->toBe('2026-10-30')
        // Mes vencido (septiembre): tampoco vence antes de emitirse.
        ->and(CargoRenta::query()->whereHas('estudio', fn ($q) => $q->where('slug', $clases['slug']))
            ->where('periodo', '2026-09')->sole()->vence_en?->toDateString())->toBe('2026-10-30');
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(Estudio::query()->where('estado', 'suspended')->count())->toBe(0);
});

it('extender la prueba de un negocio que ya pagó aplaza su siguiente cobro', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    emitirRentaEl('2026-10-01 09:00'); // octubre cubierto

    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 30], conPlataforma())->assertOk();
    expect(Estudio::query()->where('slug', $e['slug'])->sole()->trial_termina_en?->toDateString())->toBe('2026-11-10');

    // Noviembre no se cobra mientras dura la prueba; al terminar, se cobra lo que falta del mes.
    emitirRentaEl('2026-11-01 09:00');
    expect(CargoRenta::query()->where('concepto', 'plan')->count())->toBe(1);
    emitirRentaEl('2026-11-11 09:00');
    expect(cargoDelPlan('2026-11')->cubre_desde?->toDateString())->toBe('2026-11-11')
        ->and(cargoDelPlan('2026-11')->cubre_hasta?->toDateString())->toBe('2026-11-30')
        ->and(cargoDelPlan('2026-11')->desglose['prorrateo'])->toEqual(['dias_cobrables' => 20, 'dias_periodo' => 30]);
});

it('un año que empieza el 29 de febrero cubre un año completo y el siguiente no se recorre', function (): void {
    $planes = app(PlanCitasSaas::class);
    $estudio = (new Estudio)->forceFill(['zona_horaria' => 'America/Mexico_City', 'plan_cubierto_hasta' => '2028-02-28']);

    $this->travelTo(CarbonImmutable::parse('2028-02-29 09:00', 'America/Mexico_City'));
    $primero = $planes->siguientePeriodo($estudio, 'anual');
    expect([$primero['desde']->toDateString(), $primero['hasta']->toDateString()])->toBe(['2028-02-29', '2029-02-28']);

    $estudio->forceFill(['plan_cubierto_hasta' => '2029-02-28']);
    $this->travelTo(CarbonImmutable::parse('2029-03-01 09:00', 'America/Mexico_City'));
    $segundo = $planes->siguientePeriodo($estudio, 'anual');
    expect([$segundo['desde']->toDateString(), $segundo['hasta']->toDateString()])->toBe(['2029-03-01', '2030-02-28']);
});
