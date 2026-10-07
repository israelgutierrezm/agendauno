<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Contrato de la agenda para la web y la app (ADR 0104, H8): cada sesión y cada reserva
| dice si es `clase` o `cita` (la modalidad del negocio, nunca otro valor) y trae solo
| su bloque — `clase` con cupo y lista de espera, o `cita` con la atención y el pago
| calculados por el servidor —, con el otro en null, y la `ocupacion` que se muestra.
| Reprogramar pide lo de su tipo y rechaza lo del otro.
| Hoy es 1 de octubre de 2026, 06:00 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería (negocio de citas) con un servicio de pago de 30 min, un barbero que
 * atiende de 08:00 a 20:00 y un cliente dado de alta. `dia` es pasado mañana.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cliente: string, dia: string}
 */
function contratoAgendaBarberia(): array
{
    $e = estudioConSesion('barberia-h8', 'dueno@barberia.mx', 'barberia');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return [
        'e' => $e, 'sede' => $sede, 'pro' => $pro,
        'cliente' => crearMiembroTenant($e, 'Marco'),
        'dia' => now('America/Mexico_City')->addDays(2)->format('Y-m-d'),
    ];
}

/**
 * Recepción agenda una cita para el cliente a esa hora (por cobrar en caja).
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cliente: string, dia: string}  $c
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function contratoAgendaCita(array $c, string $hora = '11:00', array $extra = []): array
{
    return test()->postJson("/api/v1/app/{$c['e']['slug']}/agenda/citas", [
        'persona_id' => $c['cliente'], 'oferta_id' => $c['sede']['oferta'],
        'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => "{$c['dia']} {$hora}:00",
        ...$extra,
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data');
}

/**
 * La sesión de ese día en la agenda del equipo.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function contratoAgendaSesion(array $e, string $dia, string $id): array
{
    $sesion = collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones?desde={$dia}&hasta={$dia}", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $id);
    expect($sesion)->toBeArray();

    return $sesion;
}

/**
 * Miembro con cuenta y un pack de 8 clases; devuelve su bearer y su persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string}
 */
function contratoAgendaMiembro(array $e, string $email = 'ana@correo.mx'): array
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e),
    ], conBearer($e['bearer']))->assertCreated();

    return ['bearer' => personalConSesion($e['slug'], $e['bearer'], $email, 'miembro'), 'persona' => $persona];
}

/**
 * El tipo es siempre `clase` o `cita` y solo trae su bloque.
 *
 * @param  array<string, mixed>  $item
 */
function contratoAgendaCoherente(array $item): void
{
    expect($item)->toHaveKeys(['tipo', 'clase', 'cita', 'ocupacion'])
        ->and($item['tipo'])->toBeIn(['clase', 'cita']);
    if ($item['tipo'] === 'clase') {
        expect($item['clase'])->toBeArray()->and($item['cita'])->toBeNull();
    } else {
        expect($item['clase'])->toBeNull()->and($item['ocupacion'])->toBeNull();
    }
}

it('una clase de un negocio de clases trae su cupo y la ocupación que se muestra, sin cita', function (): void {
    $e = estudioConSesion('estudio-h8', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $creada = $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60, 'capacidad' => 4,
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($creada['tipo'])->toBe('clase')
        ->and($creada['cita'])->toBeNull()
        ->and($creada['clase'])->toBe([
            'capacidad' => 4, 'ocupados' => 0, 'libres' => 4, 'en_espera' => 0,
            'lugares' => 0, 'de_pago' => false, 'precio_minor' => null,
        ])
        ->and($creada['ocupacion'])->toBe(['ocupados' => 0, 'capacidad' => 4, 'porcentaje' => 0]);

    $vp = venderPackAMiembroTenant($e);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$creada['id']}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    $sesion = contratoAgendaSesion($e, '2026-10-01', $creada['id']);
    // Los campos de siempre siguen; el bloque y la ocupación los da el servidor.
    expect($sesion['ocupados'])->toBe(1)
        ->and($sesion['capacidad'])->toBe(4)
        ->and($sesion['clase'])->toMatchArray(['ocupados' => 1, 'libres' => 3, 'en_espera' => 0])
        ->and($sesion['ocupacion'])->toBe(['ocupados' => 1, 'capacidad' => 4, 'porcentaje' => 25])
        ->and($sesion['cita'])->toBeNull();
});

it('una clase de pago por clase dice su precio en su bloque', function (): void {
    $e = estudioConSesion('estudio-h8', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000,
    ], conBearer($e['bearer']))->assertOk();

    $sesion = contratoAgendaSesion($e, '2026-10-01', crearSesionTenant($e, $semilla));

    expect($sesion['tipo'])->toBe('clase')
        ->and($sesion['clase'])->toMatchArray(['de_pago' => true, 'precio_minor' => 15000]);
});

it('una cita de un negocio de citas trae a quién se atiende y en qué va, calculado por el servidor', function (): void {
    $c = contratoAgendaBarberia();
    $e = $c['e'];

    $cita = contratoAgendaCita($c);
    expect($cita['tipo'])->toBe('cita')
        ->and($cita['clase'])->toBeNull()
        ->and($cita['ocupacion'])->toBeNull()
        ->and($cita['cita'])->toMatchArray([
            'cliente' => 'Marco', 'estado' => 'confirmada', 'por_cobrar' => true,
            'estado_atencion' => 'confirmada', 'estado_pago' => 'por_cobrar',
        ]);

    // Cobrada en caja: pagada.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['cita']['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();
    expect(contratoAgendaSesion($e, $c['dia'], $cita['id'])['cita']['estado_pago'])->toBe('pagada');

    // Llegó antes de la hora; según la hora, en servicio y luego completada.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['cita']['reserva_id']}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();
    expect(contratoAgendaSesion($e, $c['dia'], $cita['id'])['cita']['estado_atencion'])->toBe('llego');

    $this->travelTo(CarbonImmutable::parse("{$c['dia']} 11:10:00", 'America/Mexico_City'));
    expect(contratoAgendaSesion($e, $c['dia'], $cita['id'])['cita']['estado_atencion'])->toBe('en_servicio');

    $this->travelTo(CarbonImmutable::parse("{$c['dia']} 11:31:00", 'America/Mexico_City'));
    expect(contratoAgendaSesion($e, $c['dia'], $cita['id'])['cita']['estado_atencion'])->toBe('completada');
});

it('en un negocio de citas, una sesión nueva es una cita de una persona aunque la pantalla pida cupo', function (): void {
    $c = contratoAgendaBarberia();

    $sesion = $this->postJson("/api/v1/app/{$c['e']['slug']}/sesiones", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => "{$c['dia']} 15:00:00", 'duracion_minutos' => 30, 'capacidad' => 12,
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data');

    // Sin cliente todavía: la cita va en null y se lee por su estado.
    expect($sesion['tipo'])->toBe('cita')
        ->and($sesion['capacidad'])->toBe(1)
        ->and($sesion['clase'])->toBeNull()
        ->and($sesion['ocupacion'])->toBeNull()
        ->and($sesion['cita'])->toBeNull()
        ->and($sesion['estado'])->toBe('programada');
});

it('sin duración del servicio ni de la pantalla, la cita dura lo que fijó el negocio', function (): void {
    $c = contratoAgendaBarberia();
    $e = $c['e'];
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$c['sede']['oferta']}", ['lugares' => 0, 'duracion_minutos' => null], conBearer($e['bearer']))
        ->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['citas.duracion_defecto' => 45]], conBearer($e['bearer']))
        ->assertOk();

    $cita = contratoAgendaCita($c);

    expect(strtotime($cita['termina_en']) - strtotime($cita['inicia_en']))->toBe(45 * 60);
});

it('ninguna sesión ni reserva trae un tipo que no sea clase o cita, ni el bloque del otro', function (): void {
    // Negocio de clases: la agenda del equipo, la del miembro y sus reservas.
    $e = estudioConSesion('estudio-h8', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $m = contratoAgendaMiembro($e);
    $sesion = crearSesionTenant($e, $semilla, 6);
    crearSesionTenant($e, $semilla, null, '2026-10-01 10:00:00');

    $reserva = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($m['bearer']))
        ->assertCreated()->json('data');
    $agenda = $this->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($m['bearer']))->assertOk()->json('data');
    $perfil = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))->assertOk()->json('data.reservas');
    $equipo = $this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))->assertOk()->json('data');

    expect($agenda)->toHaveCount(2)->and($equipo)->toHaveCount(2)->and($perfil)->toHaveCount(1);
    foreach ([$reserva, ...$agenda, ...$perfil, ...$equipo] as $item) {
        contratoAgendaCoherente($item);
        expect($item['tipo'])->toBe('clase');
    }
    // Su reserva dice cómo va su clase.
    expect($reserva['clase'])->toMatchArray(['capacidad' => 6, 'ocupados' => 1, 'libres' => 5])
        ->and($reserva['ocupacion'])->toBe(['ocupados' => 1, 'capacidad' => 6, 'porcentaje' => 17]);

    // Negocio de citas: una con cliente y una sin él.
    $c = contratoAgendaBarberia();
    contratoAgendaCita($c);
    $this->postJson("/api/v1/app/{$c['e']['slug']}/sesiones", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => "{$c['dia']} 15:00:00", 'duracion_minutos' => 30,
    ], conBearer($c['e']['bearer']))->assertCreated();

    $citas = $this->getJson("/api/v1/app/{$c['e']['slug']}/sesiones?desde={$c['dia']}&hasta={$c['dia']}", conBearer($c['e']['bearer']))
        ->assertOk()->json('data');
    expect($citas)->toHaveCount(2);
    foreach ($citas as $item) {
        contratoAgendaCoherente($item);
        expect($item['tipo'])->toBe('cita');
    }
});

it('en la cuenta del cliente de un negocio de citas: su cita dice en qué va y no hay clases que reservar', function (): void {
    $c = contratoAgendaBarberia();
    $e = $c['e'];
    // Se paga en línea para confirmar: la cita queda apartada por pagar.
    activarCobroEnLinea($e);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $beto = alumnoConSesion($e, 'Beto', 'beto@correo.mx');

    $agendada = $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => "{$c['dia']} 10:00:00", 'duracion_minutos' => 30, 'nota' => 'Solo la barba',
    ], conBearer($ana['bearer']))->assertCreated()->json('data');

    $esperado = [
        'reserva_id' => $agendada['id'], 'estado' => 'pendiente_pago', 'asistencia' => null,
        'orden_id' => $agendada['orden_id'], 'nota' => 'Solo la barba', 'asiste' => null,
        'estado_atencion' => 'confirmada', 'estado_pago' => 'por_pagar',
    ];
    expect($agendada['tipo'])->toBe('cita')
        ->and($agendada['clase'])->toBeNull()
        ->and($agendada['ocupacion'])->toBeNull()
        ->and($agendada['cita'])->toBe($esperado);

    $mia = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))->assertOk()->json('data.reservas.0');
    expect($mia['cita'])->toBe($esperado);

    // Nada que listar como clase abierta, y la cita ajena no se reserva ni se espera.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($beto['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $agendada['sesion_id'], 'esperar' => true], conBearer($beto['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');

    // Al cancelarla, la cita dice que está cancelada y que ya no lleva cobro.
    $cancelada = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$agendada['id']}/cancelar", [], conBearer($ana['bearer']))
        ->assertOk()->json('data.cita');
    expect($cancelada)->toMatchArray(['estado' => 'cancelada', 'estado_atencion' => 'cancelada', 'estado_pago' => null]);
});

it('reprogramar una cita pide su nuevo horario y no acepta otra sesión', function (): void {
    $c = contratoAgendaBarberia();
    $e = $c['e'];
    $cita = contratoAgendaCita($c);
    $url = "/api/v1/app/{$e['slug']}/reservas/{$cita['cita']['reserva_id']}/reprogramar";

    $this->postJson($url, ['sesion_id' => $cita['id']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('meta.errors.inicia_en_local.0', 'Elige el nuevo horario.')
        ->assertJsonPath('meta.errors.sesion_id.0', 'Una cita se cambia de horario, no a otra sesión.');

    $this->postJson($url, ['inicia_en_local' => "{$c['dia']} 13:00:00"], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'cita')
        ->assertJsonPath('data.reserva', $cita['cita']['reserva_id'])
        ->assertJsonPath('data.sesion_id', $cita['id'])
        ->assertJsonPath('data.ahora', CarbonImmutable::parse("{$c['dia']} 13:00:00", 'America/Mexico_City')->utc()->toIso8601String());
});

it('reprogramar a un alumno pide otra fecha de su clase y no acepta un horario suelto', function (): void {
    $e = estudioConSesion('estudio-h8', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e);
    $lunes = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    $miercoles = crearSesionTenant($e, $semilla, 5, '2026-10-07 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$lunes}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $url = "/api/v1/app/{$e['slug']}/reservas/{$reserva}/reprogramar";

    $this->postJson($url, ['inicia_en_local' => '2026-10-06 08:00:00'], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.sesion_id.0', 'Elige la nueva fecha.')
        ->assertJsonPath('meta.errors.inicia_en_local.0', 'En una clase se elige otra fecha de la clase.');

    $this->postJson($url, ['sesion_id' => $miercoles], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tipo', 'clase')->assertJsonPath('data.sesion_id', $miercoles);

    // La clase completa también dice su tipo al moverse.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$miercoles}/reprogramar", ['inicia_en_local' => '2026-10-07 09:00:00'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tipo', 'clase')->assertJsonPath('data.sesion_id', $miercoles);
});

it('desde su cuenta, el cliente cambia su cita con su horario y no con otra sesión', function (): void {
    $c = contratoAgendaBarberia();
    $e = $c['e'];
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => "{$c['dia']} 10:00:00", 'duracion_minutos' => 30,
    ], conBearer($ana['bearer']))->assertCreated()->json('data');
    $url = "/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/reprogramar";

    $this->getJson("{$url}?fecha={$c['dia']}", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.tipo', 'cita')->assertJsonPath('data.puede', true);

    $this->postJson($url, ['sesion_id' => $cita['sesion_id']], conBearer($ana['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.sesion_id.0', 'Una cita se cambia de horario, no a otra sesión.');

    $this->postJson($url, ['inicia_en_local' => "{$c['dia']} 12:00:00"], conBearer($ana['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'cita')
        ->assertJsonPath('data.reserva', $cita['id'])
        ->assertJsonPath('data.sesion_id', $cita['sesion_id']);
});
