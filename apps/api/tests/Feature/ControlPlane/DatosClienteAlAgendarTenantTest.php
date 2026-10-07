<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Más datos del cliente al agendar (ADR 0067): apellidos, lada del celular y cómo
| conoció al negocio quedan en la ficha de un cliente nuevo; la nota para el negocio
| llega al detalle de la cita. Un correo que ya es de alguien no cambia su ficha.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}
 */
function barberiaParaDatos(): array
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

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => now('America/Mexico_City')->addDays(3)->format('Y-m-d')];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}  $ctx
 * @param  array<string, mixed>  $datos
 */
function agendarConDatos(array $ctx, string $hora, array $datos): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx',
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} {$hora}:00", 'duracion_minutos' => 30,
        ...$datos,
    ]);
}

/**
 * La cita de esa hora como la ve el negocio en su agenda.
 *
 * @param  array{e: array{slug: string, bearer: string}, dia: string}  $ctx
 * @return array<string, mixed>
 */
function citaEnAgenda(array $ctx, string $sesion): array
{
    return collect(test()->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $sesion)['cita'];
}

it('un cliente nuevo deja apellidos, lada, cómo nos conoció y una nota para el negocio', function (): void {
    $ctx = barberiaParaDatos();

    $cita = agendarConDatos($ctx, '10:00', [
        'apellidos' => 'López García', 'lada' => '+1', 'celular' => '555 123 4567',
        'como_nos_conocio' => 'instagram', 'nota' => 'Es mi primera vez; soy alérgico al tinte.',
    ])->assertCreated()->json('data');

    $ficha = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/miembros", conBearer($ctx['e']['bearer']))->json('data'))
        ->firstWhere('email', 'beto@correo.mx');
    expect($ficha)->toMatchArray([
        'primer_apellido' => 'López',
        'segundo_apellido' => 'García',
        'celular' => '+1 555 123 4567',
        'como_nos_conocio' => 'instagram',
    ]);

    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->json('data'))->first(fn (array $s): bool => ($s['cita']['reserva_id'] ?? null) === $cita['reserva']);
    expect($sesion['cita']['nota'])->toBe('Es mi primera vez; soy alérgico al tinte.');
});

it('un correo que ya es de alguien no cambia su ficha, pero su nota sí llega a la cita', function (): void {
    $ctx = barberiaParaDatos();
    agendarConDatos($ctx, '10:00', [])->assertCreated();

    $segunda = agendarConDatos($ctx, '11:00', [
        'nombre' => 'Otro nombre', 'apellidos' => 'Pérez', 'como_nos_conocio' => 'google', 'nota' => 'Corte más corto.',
    ])->assertCreated()->json('data');

    $ficha = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/miembros", conBearer($ctx['e']['bearer']))->json('data'))
        ->firstWhere('email', 'beto@correo.mx');
    expect($ficha['nombre'])->toBe('Beto')
        ->and($ficha['primer_apellido'])->toBeNull()
        ->and($ficha['como_nos_conocio'])->toBeNull();

    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->json('data'))->first(fn (array $s): bool => ($s['cita']['reserva_id'] ?? null) === $segunda['reserva']);
    expect($sesion['cita']['nota'])->toBe('Corte más corto.');
});

it('desde su cuenta el cliente también deja una nota', function (): void {
    $ctx = barberiaParaDatos();
    $cliente = alumnoConSesion($ctx['e'], 'Vale', 'vale@correo.mx');

    $cita = $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} 12:00:00", 'duracion_minutos' => 30, 'nota' => 'Llego 5 minutos tarde.',
    ], conBearer($cliente['bearer']))->assertCreated()->json('data');

    expect(citaEnAgenda($ctx, (string) $cita['sesion_id'])['nota'])->toBe('Llego 5 minutos tarde.');
});

it('una cita para otra persona es de quien agenda y dice quién asiste', function (): void {
    $ctx = barberiaParaDatos();

    // En línea: la agenda la mamá para su hijo; los avisos le llegan a ella.
    $cita = agendarConDatos($ctx, '10:00', ['nombre' => 'Laura', 'email' => 'laura@correo.mx', 'asiste' => 'Juanito'])
        ->assertCreated()->json('data');
    $sesion = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->json('data'))->first(fn (array $s): bool => ($s['cita']['reserva_id'] ?? null) === $cita['reserva']);
    expect($sesion['cita']['cliente'])->toBe('Laura')
        ->and($sesion['cita']['asiste'])->toBe('Juanito');

    // Desde su cuenta, y la ve en sus reservas.
    $cliente = alumnoConSesion($ctx['e'], 'Vale', 'vale@correo.mx');
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/mi/citas", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} 12:00:00", 'duracion_minutos' => 30, 'asiste' => 'Mi papá',
    ], conBearer($cliente['bearer']))->assertCreated()->assertJsonPath('data.asiste', 'Mi papá');

    agendarConDatos($ctx, '11:00', ['asiste' => str_repeat('a', 121)])->assertUnprocessable()
        ->assertJsonValidationErrors(['asiste'], 'meta.errors');
});

it('valida la lada, cómo nos conoció y el largo de la nota', function (): void {
    $ctx = barberiaParaDatos();

    agendarConDatos($ctx, '10:00', ['lada' => '52', 'celular' => '5512345678'])->assertUnprocessable()
        ->assertJsonValidationErrors(['lada'], 'meta.errors');
    agendarConDatos($ctx, '10:00', ['como_nos_conocio' => 'television'])->assertUnprocessable()
        ->assertJsonValidationErrors(['como_nos_conocio'], 'meta.errors');
    agendarConDatos($ctx, '10:00', ['nota' => str_repeat('a', 501)])->assertUnprocessable()
        ->assertJsonValidationErrors(['nota'], 'meta.errors');
});
