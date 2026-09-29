<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Cómo se cobra la cita que agenda el cliente (ADR 0065). El negocio decide si pide
| el pago en línea para confirmarla: entonces se aparta hasta pagarla y le llega un
| correo con la hora límite. Si no lo pide, o no puede cobrar en línea, la cita queda
| confirmada al agendar y se paga en línea o en la sucursal. En la página pública el
| correo es obligatorio, y el calendario solo ofrece días en que alguien atiende.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con un servicio de pago ($250, 30 min) y un barbero que atiende a diario.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}
 */
function barberiaParaCobrar(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}  $ctx
 * @param  array<string, mixed>  $extra
 */
function agendarEnLinea(array $ctx, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx',
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => now('America/Mexico_City')->addDays(3)->format('Y-m-d').' 10:00:00', 'duracion_minutos' => 30,
        ...$extra,
    ]);
}

/**
 * Publica los eventos y devuelve los correos generados cuyo asunto empieza así.
 *
 * @param  array{slug: string}  $e
 * @return list<array{destinatario: string|null, asunto: string, cuerpo: string}>
 */
function correosDeCita(array $e, string $prefijo): array
{
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();

    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): array => MensajeTenant::query()->where('canal', 'email')->where('asunto', 'like', $prefijo.'%')->orderBy('id')->get()
            ->map(fn (MensajeTenant $m): array => ['destinatario' => $m->destinatario, 'asunto' => (string) $m->asunto, 'cuerpo' => (string) $m->cuerpo])
            ->all(),
    );
}

it('con cobro en línea y el pago para confirmar, la cita se aparta y le llega el aviso con la hora límite', function (): void {
    $ctx = barberiaParaCobrar();
    activarCobroEnLinea($ctx['e']);

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.cobro', ['pago_obligatorio' => true, 'pago_en_linea' => true]);
    $orden = agendarEnLinea($ctx)->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago')->json('data.orden_id');

    $apartada = correosDeCita($ctx['e'], 'Apartamos tu lugar');
    expect($apartada)->toHaveCount(1)
        ->and($apartada[0]['destinatario'])->toBe('beto@correo.mx')
        ->and($apartada[0]['cuerpo'])->toContain('a las 10:00 en Roma Norte')
        ->toContain('completa el pago de $250.00 MXN antes de las')
        // Con el enlace para pagarlo después.
        ->toContain("/agendar/{$ctx['e']['slug']}?pagar={$orden}")
        ->and(correosDeCita($ctx['e'], 'Reserva confirmada'))->toBe([]);
});

it('el enlace del correo muestra la cita por pagar y hasta cuándo, y ya no si venció', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'America/Mexico_City'));
    $ctx = barberiaParaCobrar();
    activarCobroEnLinea($ctx['e']);
    $orden = agendarEnLinea($ctx)->assertCreated()->json('data.orden_id');

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/orden/{$orden}")->assertOk()
        ->assertJsonPath('data.estado_orden', 'pendiente')
        ->assertJsonPath('data.estado_reserva', 'pendiente_pago')
        ->assertJsonPath('data.servicio', 'Nivel 1')
        ->assertJsonPath('data.sucursal.nombre', 'Roma Norte')
        ->assertJsonPath('data.total_minor', 25000)
        ->assertJsonPath('data.pago_en_linea', true)
        // 30 minutos para pagar (parámetro de reservas).
        ->assertJsonPath('data.vence_en', '2026-10-01T15:30:00+00:00')
        ->assertJsonMissingPath('data.persona');

    // Pasado el plazo se libera: la página ya no ofrece pagarla.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:45:00', 'America/Mexico_City'));
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/orden/{$orden}")->assertOk()
        ->assertJsonPath('data.estado_reserva', 'cancelada')
        ->assertJsonPath('data.vence_en', null);

    // Una orden que no existe (o que no es de una cita) no se encuentra.
    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/orden/01J0000000000000000000000Z")->assertNotFound();
});

it('si el negocio no pide pagar en línea, la cita queda confirmada con su orden por cobrar y llega la confirmación', function (): void {
    $ctx = barberiaParaCobrar();
    activarCobroEnLinea($ctx['e']);
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/parametros", ['valores' => ['citas.pago_en_linea_obligatorio' => 0]], conBearer($ctx['e']['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.cobro', ['pago_obligatorio' => false, 'pago_en_linea' => true]);
    $cita = agendarEnLinea($ctx)->assertCreated()->assertJsonPath('data.estado', 'confirmada')->json('data');
    // La orden queda por cobrar: se puede pagar en línea después o en la sucursal.
    expect($cita['orden_id'])->not->toBeNull()
        ->and($cita['total_minor'])->toBe(25000);

    expect(correosDeCita($ctx['e'], 'Reserva confirmada'))->toHaveCount(1)
        ->and(correosDeCita($ctx['e'], 'Apartamos tu lugar'))->toBe([]);
});

it('sin cobro en línea activo nunca se aparta: se paga en la sucursal', function (): void {
    $ctx = barberiaParaCobrar();

    $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.cobro', ['pago_obligatorio' => false, 'pago_en_linea' => false]);
    agendarEnLinea($ctx)->assertCreated()->assertJsonPath('data.estado', 'confirmada');
    // Tampoco desde la cuenta del cliente.
    $cliente = alumnoConSesion($ctx['e']);
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => now('America/Mexico_City')->addDays(3)->format('Y-m-d').' 11:00:00', 'duracion_minutos' => 30,
    ], conBearer($cliente['bearer']))->assertCreated()->assertJsonPath('data.estado', 'confirmada');
});

it('un cliente no aparta más citas por pagar de las que permite el negocio', function (): void {
    $ctx = barberiaParaCobrar();
    $slug = $ctx['e']['slug'];
    $this->putJson("/api/v1/app/{$slug}/parametros", ['valores' => ['citas.maximo_por_pagar' => 2]], conBearer($ctx['e']['bearer']))
        ->assertOk();
    $cliente = alumnoConSesion($ctx['e'], 'Beto', 'beto@correo.mx');
    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    $a = fn (string $hora, array $extra = []) => $this->postJson("/api/v1/app/{$slug}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$dia} {$hora}:00", 'duracion_minutos' => 30, ...$extra,
    ], conBearer($cliente['bearer']));

    $primera = $a('10:00')->assertCreated()->json('data.id');
    $a('11:00')->assertCreated();
    $a('12:00')->assertUnprocessable()
        ->assertJsonPath('message', 'Ya tienes 2 citas por pagar. Paga o cancela alguna para agendar otra.');
    // Tampoco con «cualquier profesional» (no se reintenta con otro).
    $a('12:00', ['instructor_id' => null])->assertUnprocessable();
    // Ni en la página pública con su correo.
    agendarEnLinea($ctx, ['inicia_en_local' => "{$dia} 12:00:00"])->assertUnprocessable();

    // El negocio sí le agenda aunque esté en el límite (y esa también está por pagar).
    $persona = collect($this->getJson("/api/v1/app/{$slug}/miembros", conBearer($ctx['e']['bearer']))->json('data'))
        ->firstWhere('email', 'beto@correo.mx')['id'];
    $delNegocio = $this->postJson("/api/v1/app/{$slug}/agenda/citas", [
        'persona_id' => $persona, 'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'instructor_id' => $ctx['pro'], 'inicia_en_local' => "{$dia} 13:00:00",
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data.cita.reserva_id');

    // Al cancelar, se libera lugar para agendar otra.
    $this->postJson("/api/v1/app/{$slug}/mi/reservas/{$primera}/cancelar", [], conBearer($cliente['bearer']))->assertOk();
    $a('14:00')->assertUnprocessable();
    $this->postJson("/api/v1/app/{$slug}/reservas/{$delNegocio}/cancelar", [], conBearer($ctx['e']['bearer']))->assertOk();
    $a('14:00')->assertCreated();

    // 0 = sin límite.
    $this->putJson("/api/v1/app/{$slug}/parametros", ['valores' => ['citas.maximo_por_pagar' => 0]], conBearer($ctx['e']['bearer']))
        ->assertOk();
    $a('15:00')->assertCreated();
    $a('16:00')->assertCreated();
});

it('en la página pública el correo es obligatorio', function (): void {
    $ctx = barberiaParaCobrar();

    agendarEnLinea($ctx, ['email' => null])->assertUnprocessable()->assertJsonValidationErrors(['email'], 'meta.errors');
    agendarEnLinea($ctx, ['email' => 'no-es-correo'])->assertUnprocessable()->assertJsonValidationErrors(['email'], 'meta.errors');
});

it('el calendario ofrece desde hoy los días en que alguien atiende, sin los cerrados ni hoy si ya terminó la atención', function (): void {
    // Lunes 7 de enero de 2030, 14:00 en la Ciudad de México.
    $this->travelTo(CarbonImmutable::parse('2030-01-07 14:00:00', 'America/Mexico_City'));
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    // Atiende de lunes a viernes, de 9 a 18; el miércoles 9 el negocio cierra.
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pro, 'sucursal_id' => $sede['sucursal'],
        'horarios' => array_map(static fn (int $dia): array => ['dia_semana' => $dia, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'], range(1, 5)),
    ], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => '2030-01-09', 'motivo' => 'Cerrado'], conBearer($e['bearer']))
        ->assertSuccessful();

    $dias = fn (array $extra = []): array => collect($this->getJson('/api/v1/app/'.$e['slug'].'/citas/dias?'.http_build_query([
        'sucursal_id' => $sede['sucursal'], 'desde' => '2030-01-06', 'dias' => 7, ...$extra,
    ]))->assertOk()->json('data'))->pluck('abierto', 'fecha')->all();

    expect($dias())->toBe([
        '2030-01-06' => false, // ya pasó
        '2030-01-07' => true,  // hoy, todavía atiende
        '2030-01-08' => true,
        '2030-01-09' => false, // cerrado
        '2030-01-10' => true,
        '2030-01-11' => true,
        '2030-01-12' => false, // sábado: nadie atiende
    ]);

    // A las 19:00 ya terminó la atención de hoy.
    $this->travelTo(CarbonImmutable::parse('2030-01-07 19:00:00', 'America/Mexico_City'));
    expect($dias()['2030-01-07'])->toBeFalse();

    // Con alguien que no atiende en esa sede, ningún día.
    personalConSesion($e['slug'], $e['bearer'], 'otro@barberia.mx', 'instructor');
    $otro = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data'))
        ->firstWhere('id', '!=', $pro)['id'];
    expect(array_filter($dias(['instructor_id' => $otro])))->toBe([]);

    // Un rango razonable.
    $this->getJson('/api/v1/app/'.$e['slug'].'/citas/dias?'.http_build_query(['sucursal_id' => $sede['sucursal'], 'desde' => '2030-01-06', 'dias' => 100]))
        ->assertUnprocessable();
});
