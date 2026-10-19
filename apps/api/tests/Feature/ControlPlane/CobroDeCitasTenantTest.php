<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoTicketVenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Carbon\CarbonImmutable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
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
    pasarNegocioACitas($e);
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
    pasarNegocioACitas($e);
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

    // Desde la cuenta del cliente, lo mismo (también fuera del directorio).
    $cliente = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => false, 'privado' => true], conBearer($e['bearer']))->assertOk();
    $mios = collect($this->getJson('/api/v1/app/'.$e['slug'].'/mi/citas/dias?'.http_build_query([
        'sucursal_id' => $sede['sucursal'], 'desde' => '2030-01-06', 'dias' => 7,
    ]), conBearer($cliente['bearer']))->assertOk()->json('data'))->pluck('abierto', 'fecha')->all();
    expect($mios['2030-01-08'])->toBeTrue()
        ->and($mios['2030-01-09'])->toBeFalse()
        ->and($mios['2030-01-07'])->toBeFalse();
    // El equipo (sin perfil de cliente) no usa esta ruta.
    $this->getJson('/api/v1/app/'.$e['slug'].'/mi/citas/dias?'.http_build_query(['sucursal_id' => $sede['sucursal'], 'desde' => '2030-01-06']), conBearer($e['bearer']))
        ->assertForbidden();
});

/**
 * Un producto a la venta con existencias en la sede de la cita.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}  $ctx
 */
function productoParaCobrarCita(array $ctx, int $precio, int $stock): string
{
    $e = $ctx['e'];
    $articulo = (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", ['nombre' => 'Cera', 'precio_minor' => $precio, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", ['sucursal_id' => $ctx['sede']['sucursal'], 'tipo' => 'entrada', 'cantidad' => $stock], conBearer($e['bearer']))
        ->assertCreated();

    return $articulo;
}

/** @param  array{slug: string, bearer: string}  $e */
function existenciasDe(array $e, string $articulo): int
{
    return (int) collect(test()->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->json('data'))
        ->firstWhere('id', $articulo)['stock_total'];
}

it('al terminar la cita cobra servicio, productos y propina en un solo cobro, con su ticket y el cambio', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $cera = productoParaCobrarCita($ctx, 5000, 5);
    $cobro = [
        'metodo' => 'efectivo', 'items' => [['articulo_id' => $cera, 'cantidad' => 2]],
        'propina_minor' => 3000, 'recibido_minor' => 50000, 'clave_intento' => 'cita-1',
    ];

    $datos = $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", $cobro, conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($datos)->toMatchArray([
        'servicio_minor' => 25000, 'productos_minor' => 10000, 'propina_minor' => 3000,
        'total_minor' => 38000, 'recibido_minor' => 50000, 'cambio_minor' => 12000, 'metodo' => 'efectivo',
    ])
        ->and($datos['ticket']['servicio']['monto_minor'])->toBe(25000)
        ->and($datos['ticket']['cobrado_minor'])->toBe(38000)
        ->and($datos['ticket']['cambio_minor'])->toBe(12000);
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['orden_id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pagada');
    expect(existenciasDe($e, $cera))->toBe(3);

    // Un reintento con la misma clave devuelve el mismo cobro: no cobra ni descuenta otra vez.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", $cobro, conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 38000);
    expect(existenciasDe($e, $cera))->toBe(3);
});

it('si lo recibido no alcanza o falta stock no se cobra nada: ni el servicio ni los productos', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $cera = productoParaCobrarCita($ctx, 5000, 1);
    $url = "/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro";

    $this->postJson($url, ['metodo' => 'efectivo', 'items' => [['articulo_id' => $cera, 'cantidad' => 1]], 'recibido_minor' => 26000], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['recibido_minor'], 'meta.errors');
    $this->postJson($url, ['metodo' => 'tarjeta', 'items' => [['articulo_id' => $cera, 'cantidad' => 2]]], conBearer($e['bearer']))
        ->assertStatus(422);

    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['orden_id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pendiente');
    expect(existenciasDe($e, $cera))->toBe(1);
});

it('una cita ya cobrada solo admite agregar algo más (productos o propina)', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $url = "/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro";
    $this->postJson($url, ['metodo' => 'tarjeta'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 25000)
        // Todo cobro deja su comprobante, aun solo el servicio (R06).
        ->assertJsonPath('data.ticket.servicio.monto_minor', 25000);

    $this->postJson($url, ['metodo' => 'tarjeta'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['reserva'], 'meta.errors');
    $this->postJson($url, ['metodo' => 'tarjeta', 'propina_minor' => 4000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 0)->assertJsonPath('data.propina_minor', 4000)->assertJsonPath('data.total_minor', 4000);
});

it('vender productos al cobrar la cita pide el permiso del mostrador', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $cera = productoParaCobrarCita($ctx, 5000, 3);
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'otro@barberia.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'tarjeta', 'items' => [['articulo_id' => $cera, 'cantidad' => 1]]], conBearer($instructor))
        ->assertForbidden();
});

it('reintentar el cobro de solo el servicio con la misma clave recupera el mismo cobro, sin cobrar dos veces (R04)', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $url = "/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro";
    $cobro = ['metodo' => 'efectivo', 'recibido_minor' => 30000, 'clave_intento' => 'solo-servicio-1'];

    $primero = $this->postJson($url, $cobro, conBearer($e['bearer']))->assertCreated()->json('data');
    expect($primero)->toMatchArray(['servicio_minor' => 25000, 'total_minor' => 25000, 'cambio_minor' => 5000])
        ->and($primero['venta_id'])->not->toBeNull()
        ->and($primero['ticket']['servicio']['monto_minor'])->toBe(25000);

    // Se perdió la respuesta: el mismo intento devuelve el mismo cobro.
    $otraVez = $this->postJson($url, $cobro, conBearer($e['bearer']))->assertCreated()->json('data');
    expect($otraVez['venta_id'])->toBe($primero['venta_id'])->and($otraVez['total_minor'])->toBe(25000);
    $pagos = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($pagos)->toHaveCount(1);
});

it('la clave de un cobro no sirve para otra cita, otros importes ni el mostrador: 409 sin cobrar nada (R05)', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $a = agendarEnLinea($ctx)->assertCreated()->json('data');
    $b = agendarEnLinea($ctx, ['nombre' => 'Ana', 'email' => 'ana@correo.mx', 'inicia_en_local' => now('America/Mexico_City')->addDays(3)->format('Y-m-d').' 12:00:00'])
        ->assertCreated()->json('data');
    $cobroA = ['metodo' => 'tarjeta', 'propina_minor' => 2000, 'clave_intento' => 'clave-a'];
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$a['reserva']}/cobro", $cobroA, conBearer($e['bearer']))->assertCreated();

    // Otra cita con la misma clave: conflicto, y B sigue por cobrar.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$b['reserva']}/cobro", $cobroA, conBearer($e['bearer']))->assertStatus(409);
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$b['orden_id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pendiente');
    // La misma cita con otros importes: conflicto.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$a['reserva']}/cobro", [...$cobroA, 'propina_minor' => 5000], conBearer($e['bearer']))->assertStatus(409);
    // Ni en el mostrador.
    $cera = productoParaCobrarCita($ctx, 5000, 5);
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $ctx['sede']['sucursal'], 'items' => [['articulo_id' => $cera, 'cantidad' => 1]], 'metodo_pago' => 'efectivo', 'clave_intento' => 'clave-a',
    ], conBearer($e['bearer']))->assertStatus(409);
    expect(existenciasDe($e, $cera))->toBe(5);
});

it('el ticket se manda también al cobrar solo el servicio; si el correo falla el cobro queda y se reenvía (R06)', function (): void {
    $ctx = barberiaParaCobrar();
    $e = $ctx['e'];
    $cita = agendarEnLinea($ctx)->assertCreated()->json('data');
    $url = "/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro";

    // El correo falla: el cobro sí queda, y el envío dice que falló.
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Sin servidor de correo'));
    $datos = $this->postJson($url, ['metodo' => 'efectivo', 'enviar_ticket' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.ticket_envio', 'fallido')->json('data');
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['orden_id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pagada');

    // Se reenvía aparte: solo el correo, sin cobrar otra vez.
    Mail::swap(new MailManager(app()));
    Mail::fake();
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas/{$datos['venta_id']}/ticket", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.ticket_envio', 'enviado');
    Mail::assertQueued(CorreoTicketVenta::class, 1);
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data')))->toHaveCount(1);
});
