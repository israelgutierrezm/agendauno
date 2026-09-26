<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Cobro del SaaS por modalidad (ADR 0019): estudios de clases pagan por ALUMNOS
| ACTIVOS (con actividad en el mes); negocios de citas por PROFESIONALES ACTIVOS
| (medio tiempo = 0.5) más personas atendidas fuera de cita. Tarifas versionadas,
| cobro mes vencido con la medición congelada y sin cobrar la prueba gratis.
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
        ->and($cargo['tarifa_version'])->toBe(1)
        ->and($cargo['desglose']['subtotal_minor'])->toBe(33900)
        ->and($cargo['desglose']['iva_minor'])->toBe(5424)
        ->and($cargo['monto_minor'])->toBe(39324)
        ->and($cargo['estado'])->toBe('pendiente');
});

it('citas: cobra por profesional activo, con medio tiempo a 0.5', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
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
    // El segundo atiende 10 h a la semana: medio tiempo.
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pros[1], 'sucursal_id' => $sede['sucursal'],
        'horarios' => [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'],
            ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'],
        ],
    ], conBearer($e['bearer']))->assertCreated();

    $periodo = emitirCargoDelMesEnCurso();
    $cargo = rentaDe($e)['cargos'][0];

    // 1º completo ($269) + 2º a medio tiempo (0.5 × $226).
    expect($cargo['metrica'])->toBe('profesionales_activos')
        ->and($cargo['cantidad'])->toBe(2)
        ->and($cargo['desglose']['subtotal_minor'])->toBe(26900 + 11300);

    // Transparencia: el dueño ve quién cuenta y quién es medio tiempo.
    $quien = $this->getJson("/api/v1/app/{$e['slug']}/renta/quien-cuenta?periodo={$periodo}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($quien['metrica'])->toBe('profesionales_activos')
        ->and($quien['quienes'])->toHaveCount(2)
        ->and(collect($quien['quienes'])->where('medio_tiempo', true))->toHaveCount(1)
        ->and($quien['detalle']['fte_milesimas'])->toBe(1500);
});

it('citas: las personas atendidas fuera de cita por encima de lo incluido se cobran', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
    terminarPrueba($e);
    $sede = agendaSemilla($e);
    // Un taller (clase grupal) de mañana con cupo 20.
    personalConSesion($e['slug'], $e['bearer'], 'beto@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    $taller = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'capacidad' => 20,
        'inicia_en_local' => now('America/Mexico_City')->addDay()->format('Y-m-d').' 18:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // 12 personas en el taller: 1 profesional incluye 10 → 2 adicionales.
    for ($i = 1; $i <= 12; $i++) {
        $d = venderPackAMiembroTenant($e, 8000, "Persona {$i}");
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$taller}/reservas", ['persona_id' => $d['persona']], conBearer($e['bearer']))
            ->assertCreated();
    }

    $uso = $this->getJson("/api/v1/app/{$e['slug']}/facturacion", conBearer($e['bearer']))->assertOk()->json('data.uso');

    expect($uso['detalle']['personas_fuera_de_cita'])->toBe(12)
        ->and($uso['desglose']['subtotal_minor'])->toBe(26900 + 2 * 900);
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

    expect($desglose['prorrateo'])->toEqual(['dias_cobrables' => $dias - 10, 'dias_periodo' => $dias])
        ->and($desglose['subtotal_minor'])->toBe(intdiv(33900 * ($dias - 10), $dias));
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
    $this->travelBack();

    // Sin --periodo: el mes anterior.
    $this->artisan('turnouno:generar-cargos-renta')->assertSuccessful();
    $cargo = rentaDe($e)['cargos'][0];
    expect($cargo['periodo'])->toBe($pasado)->and($cargo['cantidad'])->toBe(1);
    $this->assertDatabaseHas('mediciones_uso', ['periodo' => $pasado, 'cantidad' => 1, 'congelada' => true]);
});

it('los días de prueba dependen de la modalidad: 30 en clases y 14 en citas', function (): void {
    estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Barbería B', 'slug' => 'barberia-b', 'perfil_negocio' => 'barberia',
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo', 'contacto_email' => 'b@barberia.mx', 'contacto_telefono' => '5512345679',
        'acepta_terminos' => true,
    ])->assertCreated();

    $hoy = now()->startOfDay();
    expect((int) $hoy->diffInDays(Estudio::query()->where('slug', 'pilates-a')->firstOrFail()->trial_termina_en))->toBe(30);
    expect((int) $hoy->diffInDays(Estudio::query()->where('slug', 'barberia-b')->firstOrFail()->trial_termina_en))->toBe(14);
});

it('el superadmin publica una versión nueva de la tarifa y los cargos la usan', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));

    $this->getJson('/api/v1/plataforma/tarifas', conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.clases.vigente.version', 1)
        ->assertJsonPath('data.citas.vigente.definicion.tramos.0.unitario_minor', 26900);

    $this->postJson('/api/v1/plataforma/tarifas/clases', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16,
        'bandas' => [['hasta' => 50, 'monto_minor' => 29900], ['hasta' => null, 'monto_minor' => 99900]],
    ], conPlataforma())->assertCreated()->assertJsonPath('data.version', 2);

    emitirCargoDelMesEnCurso();
    $cargo = rentaDe($e)['cargos'][0];

    expect($cargo['tarifa_version'])->toBe(2)->and($cargo['desglose']['subtotal_minor'])->toBe(29900);
});

it('una tarifa sin techo o con topes desordenados se rechaza', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    $this->postJson('/api/v1/plataforma/tarifas/clases', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16,
        'bandas' => [['hasta' => 50, 'monto_minor' => 29900], ['hasta' => 100, 'monto_minor' => 59900]],
    ], conPlataforma())->assertStatus(422)->assertJsonPath('meta.errors.bandas.0', 'El último escalón debe quedar sin tope (el techo).');

    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 14, 'iva_porcentaje' => 16,
        'tramos' => [['hasta' => 5, 'unitario_minor' => 100], ['hasta' => 3, 'unitario_minor' => 50], ['hasta' => null, 'unitario_minor' => 0]],
        'personas_incluidas_por_profesional' => 10, 'tope_personas_incluidas' => 100,
        'extra_por_persona_minor' => 900, 'horas_medio_tiempo' => 20,
    ], conPlataforma())->assertStatus(422)->assertJsonPath('meta.errors.tramos.0', 'Los topes deben ir de menor a mayor.');

    $this->getJson('/api/v1/plataforma/tarifas', ['Accept' => 'application/json'])->assertUnauthorized();
});
