<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Permanencia y baja de una membresía (plan de mejoras B04, decisiones del dueño del
| 2026-10-10): un plan con permanencia se compra aceptando sus condiciones (quedan con
| la compra y se congelan en el acuerdo); la baja aplica al terminar el periodo pagado,
| con la penalización pactada (sin penalización, monto fijo o las mensualidades que
| falten al precio vigente) solo si no cumplió; la mensualidad vencida no se perdona en
| silencio; y se ve el cálculo antes de confirmar.
|
| Hoy es el 1 de octubre de 2026: el plan se compra hoy y renueva el 1 de cada mes.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una membresía mensual con permanencia; devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $baja
 */
function membresiaConPermanencia(array $e, int $meses = 6, array $baja = ['baja_anticipada' => 'meses_restantes']): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 120000, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'aniversario', 'permanencia_meses' => $meses, ...$baja,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Vende la membresía en caja aceptando sus condiciones; devuelve el ulid del acuerdo.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function contratarConPermanencia(array $e, string $persona, string $plan): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $plan, 'acepta_condiciones' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');
}

it('sin aceptar las condiciones no se compra; aceptadas, quedan con la compra y en el acuerdo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $plan = membresiaConPermanencia($e);
    $ana = crearMiembroTenant($e, 'Ana');

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $plan], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'TERMS_NOT_ACCEPTED');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $ana, 'items' => [['producto_id' => $plan, 'cantidad' => 1]]], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'TERMS_NOT_ACCEPTED');

    // El alumno las ve antes de comprar y las acepta en su cuenta.
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($vale['bearer']))->assertOk()->json('data'))->keyBy('id');
    expect($catalogo[$plan]['condiciones'])->toStartWith('Permanencia mínima de 6 meses desde la fecha de compra.');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $plan, 'cantidad' => 1]], 'acepta_condiciones' => true,
    ], conBearer($vale['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $planes = $this->getJson("/api/v1/app/{$e['slug']}/mi/planes", conBearer($vale['bearer']))->assertOk()->json('data');
    $mensualidad = collect($planes)->firstWhere('producto', 'Mensualidad');
    expect($mensualidad)->toMatchArray(['recurrente' => true, 'permanencia_hasta' => '2027-04-01', 'proximo_cobro' => '2026-11-01'])
        ->and($mensualidad['condiciones'])->toContain('pagas las mensualidades que falten');
});

it('la simulación dice la fecha efectiva y la penalización pactada; la baja hace lo mismo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = contratarConPermanencia($e, $ana, membresiaConPermanencia($e));
    // Una clase después de su último día: se cancela con la baja.
    $semilla = agendaSemilla($e);
    $despues = crearSesionTenant($e, $semilla, 5, '2026-11-03 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$despues}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->travelTo('2026-10-15 12:00:00');
    // Al terminar el periodo pagado (31 de octubre); faltan nov, dic, ene, feb y mar.
    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data', [
            'efectiva_el' => '2026-10-31', 'en_permanencia' => true, 'permanencia_hasta' => '2027-04-01',
            'modo' => 'meses_restantes', 'meses_restantes' => 5, 'precio_base_minor' => 120000, 'porcentaje' => 100,
            'penalizacion_minor' => 600000, 'adeudo_minor' => 0, 'moneda' => 'MXN',
            'condiciones' => 'Permanencia mínima de 6 meses desde la fecha de compra. Si das de baja antes de cumplirla, pagas las mensualidades que falten para cumplirla, al precio vigente del plan. La baja aplica al terminar el periodo pagado.',
        ]);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", ['motivo' => 'Se muda'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.efectiva_el', '2026-10-31')
        ->assertJsonPath('data.penalizacion_minor', 600000)->assertJsonPath('data.estado_acuerdo', 'activo');

    // La penalización queda por cobrar, aparte; ya no se renueva; la clase posterior se canceló.
    $pendientes = collect($this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($pendientes->flatMap(fn (array $o): array => $o['cuotas'])->pluck('nombre', 'concepto')->all())
        ->toBe(['penalizacion_baja' => 'Penalización por baja anticipada']);
    $plan = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/planes", conBearer($e['bearer']))->assertOk()->json('data'))->first();
    expect($plan)->toMatchArray(['baja_efectiva_el' => '2026-10-31', 'proximo_cobro' => null, 'hasta' => '2026-10-31']);
    $ficha = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/ficha", conBearer($e['bearer']))->assertOk()->json('data.reservas'))->keyBy('id');
    expect($ficha[$reserva]['estado'])->toBe('cancelada');

    // Una segunda baja no se puede; al pasar su último día, el acuerdo se cierra.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", [], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'MEMBERSHIP_CANCEL_NOT_ALLOWED');
    $this->travelTo('2026-11-01 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'MEMBERSHIP_CANCEL_NOT_ALLOWED');
});

it('con porcentaje, paga esa parte de las mensualidades que falten, congelada en su membresía', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Fuera de 1–100 no se guarda.
    foreach ([0, 101] as $fuera) {
        $this->postJson("/api/v1/app/{$e['slug']}/productos", [
            'nombre' => 'Mal', 'tipo' => 'membresia', 'precio_minor' => 120000, 'moneda' => 'MXN', 'ilimitado' => true,
            'politica_reset' => 'aniversario', 'permanencia_meses' => 6, 'baja_anticipada' => 'meses_restantes', 'penalizacion_porcentaje' => $fuera,
        ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['penalizacion_porcentaje'], 'meta.errors');
    }
    $plan = membresiaConPermanencia($e, 6, ['baja_anticipada' => 'meses_restantes', 'penalizacion_porcentaje' => 50]);
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = contratarConPermanencia($e, $ana, $plan);

    // Cambiar el plan después no cambia lo que aceptó.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$plan}", [
        'permanencia_meses' => 6, 'baja_anticipada' => 'meses_restantes', 'penalizacion_porcentaje' => 80,
    ], conBearer($e['bearer']))->assertOk();

    $this->travelTo('2026-10-15 12:00:00');
    // Faltan 5 mensualidades de 1,200: el 50 % son 3,000.
    $simulacion = $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", conBearer($e['bearer']))->assertOk()->json('data');
    expect($simulacion)->toMatchArray(['meses_restantes' => 5, 'porcentaje' => 50, 'penalizacion_minor' => 300000])
        ->and($simulacion['condiciones'])->toContain('pagas el 50 % de las mensualidades que falten');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.penalizacion_minor', 300000);
});

it('monto fijo, permanencia cumplida y exentar la penalización con permiso', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $fijo = contratarConPermanencia($e, crearMiembroTenant($e, 'Ana'), membresiaConPermanencia($e, 6, ['baja_anticipada' => 'monto_fijo', 'penalizacion_minor' => 150000]));
    $corta = contratarConPermanencia($e, crearMiembroTenant($e, 'Bea'), membresiaConPermanencia($e, 1));
    $this->travelTo('2026-10-15 12:00:00');

    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$fijo}/baja", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.penalizacion_minor', 150000)->assertJsonPath('data.modo', 'monto_fijo');
    // Un mes de permanencia: al terminar octubre ya la cumplió.
    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$corta}/baja", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.en_permanencia', false)->assertJsonPath('data.penalizacion_minor', 0);

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$fijo}/baja", ['exentar_penalizacion' => true], conBearer($recepcion))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$fijo}/baja", ['exentar_penalizacion' => true, 'motivo' => 'Cambio de ciudad'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.penalizacion_minor', 0)->assertJsonPath('data.penalizacion_exenta', true);
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->json('data'))
        ->flatMap(fn (array $o): array => $o['cuotas']))->toBeEmpty();
});

it('en mora la baja es hoy y la mensualidad vencida se cobra aparte; un paquete no se da de baja', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = contratarConPermanencia($e, $ana, membresiaConPermanencia($e, 1));
    // Se abre la renovación del 1 de noviembre y no la paga.
    $this->travelTo('2026-10-30 12:00:00');
    $this->artisan('agendauno:avisar-renovaciones')->assertSuccessful();
    $this->travelTo('2026-11-05 12:00:00');

    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.efectiva_el', '2026-11-05')->assertJsonPath('data.adeudo_minor', 120000);
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.adeudo_minor', 120000)->assertJsonPath('data.estado_acuerdo', 'cancelado');

    // La renovación se cerró; lo que debe queda como «Mensualidad pendiente».
    $pendientes = collect($this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->json('data'));
    expect($pendientes)->toHaveCount(1)
        ->and($pendientes->first()['cuotas'][0])->toMatchArray(['concepto' => 'adeudo_mensualidad', 'monto_minor' => 120000]);

    $paquete = crearPackTenant($e);
    $delPaquete = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $paquete], conBearer($e['bearer']))
        ->assertCreated()->json('data.acuerdo');
    $this->getJson("/api/v1/app/{$e['slug']}/acuerdos/{$delPaquete}/baja", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'MEMBERSHIP_CANCEL_NOT_ALLOWED');
});

it('perdonar la mensualidad vencida al dar de baja pide permiso para exentar y un motivo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = contratarConPermanencia($e, $ana, membresiaConPermanencia($e, 1));
    $this->travelTo('2026-10-30 12:00:00');
    $this->artisan('agendauno:avisar-renovaciones')->assertSuccessful();
    $this->travelTo('2026-11-05 12:00:00');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $url = "/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/baja";

    $this->postJson($url, ['conservar_adeudo' => false, 'motivo' => 'Se mudó'], conBearer($recepcion))->assertForbidden();
    $this->postJson($url, ['conservar_adeudo' => false], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['motivo'], 'meta.errors');
    $this->postJson($url, ['conservar_adeudo' => false, 'motivo' => 'Se mudó de ciudad'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.adeudo_minor', 0);
});
