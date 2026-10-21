<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Cobro del SaaS por modalidad (ADR 0019 y 0107): estudios de clases pagan por ALUMNOS
| ACTIVOS (con actividad en el mes), mes vencido con la medición congelada y sin cobrar
| la prueba gratis; la tarifa está en dólares y en México se cobra en pesos (a 20 en
| las pruebas). Los negocios de citas pagan su plan por adelantado (PlanCitasSaasTest);
| la medición de sus profesionales activos queda como referencia («quién cuenta»).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function periodoHoy(): string
{
    return now('America/Mexico_City')->format('Y-m');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function rentaDe(array $e): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk()->json('data');
}

/**
 * Una sesión de hoy (10:00 local) con el profesional indicado.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $sede
 */
function sesionDeHoyCon(array $e, array $sede, string $profesional, string $hora = '10:00'): void
{
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => now('America/Mexico_City')->format('Y-m-d')." {$hora}:00", 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertCreated();
}

it('clases: cobra la banda de alumnos activos del mes con IVA', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
        compraPagadaTenant($e, crearMiembroTenant($e, $nombre));
    }
    crearMiembroTenant($e, 'Sin actividad');

    emitirCargoDelMesEnCurso();
    $cargo = rentaDe($e)['cargos'][0];

    expect($cargo['metrica'])->toBe('alumnos_activos')
        ->and($cargo['cantidad'])->toBe(3)
        // La versión 4 es la del techo (2026_10_20_000200_techo_de_clases), en dólares:
        // hasta 40 alumnos, 21 USD = 420 pesos.
        ->and($cargo['tarifa_version'])->toBe(4)
        ->and($cargo['desglose']['subtotal_minor'])->toBe(42000)
        ->and($cargo['desglose']['iva_minor'])->toBe(6720)
        ->and($cargo['monto_minor'])->toBe(48720)
        ->and($cargo['estado'])->toBe('pendiente');
});

it('clases: quién cuenta va por páginas, se busca por nombre y dice por qué cuenta cada quien', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    $semilla = agendaSemilla($e);
    $ana = crearMiembroTenant($e, 'Ana');
    compraPagadaTenant($e, $ana);
    foreach (['2026-10-05 19:00:00', '2026-10-06 19:00:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, 10, $cuando);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
            ->assertCreated();
    }
    foreach (['Beto', 'Caro'] as $nombre) {
        compraPagadaTenant($e, crearMiembroTenant($e, $nombre));
    }
    crearMiembroTenant($e, 'Sin actividad');
    $url = "/api/v1/app/{$e['slug']}/renta/quien-cuenta";

    $primera = $this->getJson("{$url}?page=1&per_page=2", conBearer($e['bearer']))->assertOk();
    expect($primera->json('data.cantidad'))->toBe(3)
        ->and($primera->json('meta.total'))->toBe(3)
        ->and($primera->json('meta.ultima_pagina'))->toBe(2)
        ->and($primera->json('data.quienes.0'))->toMatchArray(['id' => $ana, 'nombre' => 'Ana', 'reservas' => 2, 'compras' => 1])
        ->and($primera->json('data.quienes.1'))->toMatchArray(['nombre' => 'Beto', 'reservas' => 0, 'compras' => 1]);
    $this->getJson("{$url}?page=2&per_page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(1, 'data.quienes')->assertJsonPath('data.quienes.0.nombre', 'Caro');
    // Buscar acota la lista, no lo que se cobra.
    $this->getJson("{$url}?page=1&q=car", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.cantidad', 3)->assertJsonPath('data.quienes.0.nombre', 'Caro');
    // Sin `page`, la lista completa como antes.
    expect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json())->not->toHaveKey('meta');
});

it('citas: cuenta como referencia a cada profesional que atendió, completo sin importar sus horas', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    $sede = agendaSemilla($e);
    foreach (['beto', 'carlos', 'diego'] as $n) {
        personalConSesion($e['slug'], $e['bearer'], "{$n}@barberia.mx", 'instructor');
    }
    $pros = collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('id')->all();

    // Dos atienden este mes; el tercero no tiene sesiones y no cuenta.
    sesionDeHoyCon($e, $sede, $pros[0], '10:00');
    sesionDeHoyCon($e, $sede, $pros[1], '11:00');
    // El segundo atiende solo 10 h a la semana: aun así cuenta completo.
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pros[1], 'sucursal_id' => $sede['sucursal'],
        'horarios' => [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'],
            ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'],
        ],
    ], conBearer($e['bearer']))->assertCreated();

    // Transparencia: el dueño ve quién atendió (se cobra por lo contratado, ADR 0107).
    $periodo = periodoHoy();
    $quien = $this->getJson("/api/v1/app/{$e['slug']}/renta/quien-cuenta?periodo={$periodo}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($quien['metrica'])->toBe('profesionales_activos')
        ->and($quien['cantidad'])->toBe(2)
        ->and($quien['quienes'])->toHaveCount(2)
        ->and($quien['quienes'][0])->not->toHaveKey('medio_tiempo')
        ->and($quien['detalle'])->not->toHaveKey('fte_milesimas');
    // Por páginas, cada profesional con las sesiones que atendió.
    $pagina = $this->getJson("/api/v1/app/{$e['slug']}/renta/quien-cuenta?periodo={$periodo}&page=1&per_page=1", conBearer($e['bearer']))->assertOk();
    expect($pagina->json('meta.total'))->toBe(2)
        ->and($pagina->json('data.quienes'))->toHaveCount(1)
        ->and($pagina->json('data.quienes.0.sesiones'))->toBe(1);
});

it('citas: un taller nuevo nace como cita y no suma personas atendidas fuera de cita', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');

    // Sin negocios mixtos (ADR 0104): aunque se pida cupo 20, es una cita de una persona.
    $taller = $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'capacidad' => 20,
        'inicia_en_local' => now('America/Mexico_City')->addDay()->format('Y-m-d').' 18:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.tipo', 'cita')->assertJsonPath('data.capacidad', 1)->json('data.id');
    $d = venderPackAMiembroTenant($e, 8000, 'Persona 1');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$taller}/reservas", ['persona_id' => $d['persona']], conBearer($e['bearer']))
        ->assertCreated();

    $uso = $this->getJson("/api/v1/app/{$e['slug']}/renta/quien-cuenta", conBearer($e['bearer']))->assertOk()->json('data');

    expect($uso['detalle']['personas_fuera_de_cita'])->toBe(0);
})->skip(fn (): bool => now('America/Mexico_City')->isLastOfMonth(), 'El taller de mañana caería en el siguiente mes.');

it('citas: las personas de una clase de antes de la modalidad excluyente se siguen midiendo', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    $taller = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => now('America/Mexico_City')->addDay()->format('Y-m-d').' 18:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    // Un taller grupal (cupo 20) que quedó de antes de la decisión (ADR 0018, 0104): la
    // tarifa publicada conserva la regla y agendauno:revisar-modalidades lo señala.
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => SesionTenant::query()->where('ulid', $taller)->update(['tipo' => 'clase', 'capacidad' => 20]),
    );

    // 12 personas en el taller: 1 profesional incluye 10 → 2 adicionales.
    for ($i = 1; $i <= 12; $i++) {
        $d = venderPackAMiembroTenant($e, 8000, "Persona {$i}");
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$taller}/reservas", ['persona_id' => $d['persona']], conBearer($e['bearer']))
            ->assertCreated();
    }

    $uso = $this->getJson("/api/v1/app/{$e['slug']}/renta/quien-cuenta", conBearer($e['bearer']))->assertOk()->json('data');

    expect($uso['detalle']['personas_fuera_de_cita'])->toBe(12);
})->skip(fn (): bool => now('America/Mexico_City')->isLastOfMonth(), 'El taller de mañana caería en el siguiente mes.');

it('un mes sin actividad queda sin cargo y no se puede pagar', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    activarStripePlataforma();

    emitirCargoDelMesEnCurso();
    $cargo = rentaDe($e)['cargos'][0];

    expect($cargo['estado'])->toBe('sin_cargo')->and($cargo['monto_minor'])->toBe(0);
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo['id']}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertJsonPath('code', 'RENT_CHARGE_NOT_PAYABLE');
});

it('no se cobran los días de prueba gratis: el mes en que termina se prorratea', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));

    // Prueba que termina el día 10 del mes en curso.
    $inicio = Carbon::createFromFormat('Y-m-d', periodoHoy().'-01');
    Estudio::query()->where('slug', $e['slug'])->update(['trial_termina_en' => $inicio->copy()->addDays(9)->toDateString()]);
    $dias = $inicio->daysInMonth;

    emitirCargoDelMesEnCurso();
    $desglose = rentaDe($e)['cargos'][0]['desglose'];

    // Se prorratea en dólares y se convierte a pesos (a 20).
    expect($desglose['prorrateo'])->toEqual(['dias_cobrables' => $dias - 10, 'dias_periodo' => $dias])
        ->and($desglose['subtotal_minor'])->toBe(intdiv(2100 * ($dias - 10), $dias) * 20);
});

it('mientras dura la prueba gratis el mes queda sin cargo', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx'); // prueba de 30 días desde hoy
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));

    emitirCargoDelMesEnCurso();

    expect(rentaDe($e)['cargos'][0]['estado'])->toBe('sin_cargo');
});

it('cobra mes vencido: congela la medición del mes cerrado y no cambia después', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);

    // Actividad a mitad del mes pasado.
    $this->travelTo(now('America/Mexico_City')->subMonthNoOverflow()->startOfMonth()->addDays(14)->setTime(12, 0));
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));
    $pasado = now('America/Mexico_City')->format('Y-m');
    alRelojDePrueba();

    // Sin --periodo: el mes anterior.
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    $cargo = rentaDe($e)['cargos'][0];
    expect($cargo['periodo'])->toBe($pasado)->and($cargo['cantidad'])->toBe(1);
    $this->assertDatabaseHas('mediciones_uso', ['periodo' => $pasado, 'cantidad' => 1, 'congelada' => true]);
});

it('los negocios nuevos reciben 30 días de prueba en ambas modalidades', function (): void {
    estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Barbería B', 'slug' => 'barberia-b', 'perfil_negocio' => 'barberia',
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo', 'contacto_email' => 'b@barberia.mx', 'contacto_telefono' => '5512345679',
        'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated();

    $hoy = now()->startOfDay();
    expect((int) $hoy->diffInDays(Estudio::query()->where('slug', 'pilates-a')->firstOrFail()->trial_termina_en))->toBe(30);
    expect((int) $hoy->diffInDays(Estudio::query()->where('slug', 'barberia-b')->firstOrFail()->trial_termina_en))->toBe(30);
});

it('el superadmin publica una versión nueva de la tarifa y los cargos la usan', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));

    $this->getJson('/api/v1/plataforma/tarifas', conPlataforma())
        ->assertOk()
        // La 1 es la inicial; la 2, la de los rangos de alumnos; la 3, la de dólares; la
        // 4, la del techo para estudios grandes.
        ->assertJsonPath('data.clases.vigente.version', 4)
        ->assertJsonPath('data.clases.vigente.definicion.moneda', 'USD')
        ->assertJsonPath('data.clases.vigente.definicion.bandas.0.hasta', 40)
        ->assertJsonPath('data.citas.vigente.definicion.niveles.individual.1', 900);

    // Una versión en pesos se cobra tal cual.
    $this->postJson('/api/v1/plataforma/tarifas/clases', [
        'moneda' => 'MXN', 'dias_prueba' => 30, 'iva_porcentaje' => 16,
        'bandas' => [['hasta' => 50, 'monto_minor' => 29900], ['hasta' => null, 'monto_minor' => 99900]],
    ], conPlataforma())->assertCreated()->assertJsonPath('data.version', 5);

    emitirCargoDelMesEnCurso();
    $cargo = rentaDe($e)['cargos'][0];

    expect($cargo['tarifa_version'])->toBe(5)
        ->and($cargo['desglose']['subtotal_minor'])->toBe(29900)
        ->and($cargo['tipo_cambio'])->toBeNull();
});

it('una tarifa sin techo o con topes desordenados se rechaza', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');

    $this->postJson('/api/v1/plataforma/tarifas/clases', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16,
        'bandas' => [['hasta' => 50, 'monto_minor' => 29900], ['hasta' => 100, 'monto_minor' => 59900]],
    ], conPlataforma())->assertStatus(422)->assertJsonPath('meta.errors.bandas.0', 'El último escalón debe quedar sin tope (el techo).');

    // Citas ya no se publica por tramos: por niveles (ADR 0107).
    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 14, 'iva_porcentaje' => 16,
        'tramos' => [['hasta' => null, 'unitario_minor' => 26900]],
    ], conPlataforma())->assertStatus(422)->assertJsonValidationErrors(['niveles', 'meses_anual'], 'meta.errors');

    $this->getJson('/api/v1/plataforma/tarifas', ['Accept' => 'application/json'])->assertUnauthorized();
});

it('clases: quien usó el servicio en el mes cuenta aunque al final del mes se archive o deje de ser facturable', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    $ana = crearMiembroTenant($e, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');
    compraPagadaTenant($e, $ana);
    compraPagadaTenant($e, $beto);
    $url = "/api/v1/app/{$e['slug']}/renta/quien-cuenta";
    expect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data.cantidad'))->toBe(2);

    // A fin de mes: Ana deja de ser facturable y Beto se archiva.
    $this->travelTo('2026-10-31 20:00:00');
    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", ['es_facturable' => false], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$beto}", ['archivado' => true], conBearer($e['bearer']))->assertOk();

    // Octubre los sigue contando: sí usaron el servicio.
    expect($this->getJson("{$url}?periodo=2026-10", conBearer($e['bearer']))->assertOk()->json('data.cantidad'))->toBe(2);
});
