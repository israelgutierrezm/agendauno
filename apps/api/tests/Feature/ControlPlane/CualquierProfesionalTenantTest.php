<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| «Cualquier profesional disponible»: el cliente agenda sin elegir profesional. Ve los
| huecos de todo el equipo que atiende en la sede ese día y el negocio asigna, a esa
| hora, al que tiene menos trabajo; si alguien le ganó el hueco a ese profesional, se
| intenta con el siguiente. La respuesta dice quién lo atenderá.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con un servicio de 30 min y dos barberos: Beto atiende de 9 a 11 y Carla
 * de 10 a 12, el mismo día (dentro de 3 días).
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, beto: string, carla: string, fecha: string, cliente: string}
 */
function barberiaConDosBarberos(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    foreach (['Beto' => 'beto@barberia.mx', 'Carla' => 'carla@barberia.mx'] as $nombre => $email) {
        test()->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
            'nombre' => $nombre, 'email' => $email, 'rol' => 'instructor',
        ], conBearer($e['bearer']))->assertCreated();
    }
    $ids = collect(test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('id', 'nombre');

    pasarNegocioACitas($e);
    $fecha = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    $dia = (int) now('America/Mexico_City')->addDays(3)->isoWeekday();
    foreach ([['Beto', '09:00', '11:00'], ['Carla', '10:00', '12:00']] as [$nombre, $abre, $cierra]) {
        test()->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
            'instructor_id' => $ids[$nombre], 'sucursal_id' => $sede['sucursal'],
            'horarios' => [['dia_semana' => $dia, 'hora_inicio' => $abre, 'hora_fin' => $cierra]],
        ], conBearer($e['bearer']))->assertCreated();
    }

    return [
        'e' => $e, 'sede' => $sede, 'beto' => (string) $ids['Beto'], 'carla' => (string) $ids['Carla'],
        'fecha' => $fecha, 'cliente' => crearMiembroTenant($e, 'Cliente'),
    ];
}

/**
 * El negocio agenda una cita con un profesional (para preparar el caso sin gastar el
 * límite de solicitudes de la página pública).
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, beto: string, carla: string, fecha: string, cliente: string}  $ctx
 */
function citaDelNegocio(array $ctx, string $hora, string $profesional): void
{
    test()->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", [
        'persona_id' => $ctx['cliente'], 'oferta_id' => $ctx['sede']['oferta'],
        'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => "{$ctx['fecha']} {$hora}:00",
    ], conBearer($ctx['e']['bearer']))->assertCreated();
}

/**
 * Agenda en la página pública con «cualquier profesional».
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, beto: string, carla: string, fecha: string, cliente: string}  $ctx
 */
function agendarPublico(array $ctx, string $hora): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Cliente', 'email' => 'cliente@correo.mx', 'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'inicia_en_local' => "{$ctx['fecha']} {$hora}:00", 'duracion_minutos' => 30,
    ]);
}

it('sin elegir profesional ofrece los huecos de todo el equipo y quién puede atender en cada uno', function (): void {
    $ctx = barberiaConDosBarberos();

    $slots = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/disponibilidad?sucursal_id={$ctx['sede']['sucursal']}&fecha={$ctx['fecha']}&oferta_id={$ctx['sede']['oferta']}")
        ->assertOk()->json('data.slots');

    // Beto 9:00–10:30 y Carla 10:00–11:30, cada media hora: 6 huecos distintos.
    expect($slots)->toHaveCount(6);
    expect($slots[0]['profesionales'])->toBe([$ctx['beto']]);
    // A las 10:00 y 10:30 están libres los dos.
    expect($slots[2]['profesionales'])->toEqualCanonicalizing([$ctx['beto'], $ctx['carla']]);
    expect($slots[5]['profesionales'])->toBe([$ctx['carla']]);
    // En orden cronológico.
    expect(collect($slots)->pluck('inicia')->all())->toBe(collect($slots)->pluck('inicia')->sort()->values()->all());
});

it('asigna la cita a quien tiene menos trabajo ese día y dice quién atenderá', function (): void {
    $ctx = barberiaConDosBarberos();
    citaDelNegocio($ctx, '09:00', $ctx['beto']);

    // A las 10:00 los dos están libres; Beto ya tiene una cita ese día.
    agendarPublico($ctx, '10:00')->assertCreated()
        ->assertJsonPath('data.profesional.id', $ctx['carla'])
        ->assertJsonPath('data.profesional.nombre', 'Carla');
});

it('si a quien tocaba ya lo ocuparon a esa hora, agenda con el siguiente', function (): void {
    $ctx = barberiaConDosBarberos();
    // Carla tiene más trabajo ese día (2 citas), pero Beto está ocupado a las 10:00.
    citaDelNegocio($ctx, '11:00', $ctx['carla']);
    citaDelNegocio($ctx, '11:30', $ctx['carla']);
    citaDelNegocio($ctx, '10:00', $ctx['beto']);

    agendarPublico($ctx, '10:00')->assertCreated()->assertJsonPath('data.profesional.nombre', 'Carla');
    // Ya no queda nadie a esa hora.
    agendarPublico($ctx, '10:00')->assertStatus(422);
});

it('sin nadie que atienda a esa hora rechaza la cita', function (): void {
    $ctx = barberiaConDosBarberos();

    // Nadie atiende a las 13:00; a las 9:00 solo Beto, y ya está ocupado.
    agendarPublico($ctx, '13:00')->assertStatus(422)->assertJsonPath('message', 'No hay profesionales disponibles a esa hora.');
    citaDelNegocio($ctx, '09:00', $ctx['beto']);
    agendarPublico($ctx, '09:00')->assertStatus(422);
});

it('desde su cuenta el cliente también agenda con cualquier profesional', function (): void {
    $ctx = barberiaConDosBarberos();
    $cliente = alumnoConSesion($ctx['e']);

    $slots = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas/disponibilidad?sucursal_id={$ctx['sede']['sucursal']}&fecha={$ctx['fecha']}&oferta_id={$ctx['sede']['oferta']}", conBearer($cliente['bearer']))
        ->assertOk()->json('data.slots');
    expect($slots)->toHaveCount(6);

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'inicia_en_local' => "{$ctx['fecha']} 11:30:00", 'duracion_minutos' => 30,
    ], conBearer($cliente['bearer']))->assertCreated()
        // Sin cobro en línea, queda confirmada y se paga en la sucursal (ADR 0065).
        ->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonPath('data.instructor', 'Carla')
        ->assertJsonPath('data.profesional.id', $ctx['carla']);
});
