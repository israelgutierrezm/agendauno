<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Al agendar una cita el servidor vuelve a validar lo que la pantalla ofreció: servicio
| agendable, profesional que atiende, duración del servicio, horario de atención, día
| abierto y que no se encime con otra cita (cualquier solapamiento).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con un servicio de 30 min y un barbero que atiende de lunes a domingo de
 * 09:00 a 12:00; el cliente agenda sin cuenta desde la página pública.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}
 */
function barberiaQueValida(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    test()->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pro, 'sucursal_id' => $sede['sucursal'],
        'horarios' => array_map(static fn (int $d): array => ['dia_semana' => $d, 'hora_inicio' => '09:00', 'hora_fin' => '12:00'], range(1, 7)),
    ], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => now()->addDays(3)->format('Y-m-d')];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, dia: string}  $ctx
 * @param  array<string, mixed>  $cambios
 */
function citaPublica(array $ctx, string $hora, array $cambios = []): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Cliente', 'email' => 'cliente'.uniqid().'@correo.mx',
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => "{$ctx['dia']} {$hora}:00", 'duracion_minutos' => 30,
        ...$cambios,
    ]);
}

it('no se agenda fuera del horario de atención ni en un día cerrado', function (): void {
    $ctx = barberiaQueValida();

    citaPublica($ctx, '08:30')->assertStatus(422);                 // antes de abrir
    citaPublica($ctx, '11:45')->assertStatus(422);                 // termina después de cerrar
    citaPublica($ctx, '11:30')->assertCreated();                   // cabe justo

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/excepciones-horario", ['fecha' => $ctx['dia'], 'motivo' => 'Feriado'], conBearer($ctx['e']['bearer']))
        ->assertCreated();
    citaPublica($ctx, '10:00')->assertStatus(422);                 // día cerrado
});

it('la duración la fija el servicio, no la pantalla', function (): void {
    $ctx = barberiaQueValida();

    // Pide 3 horas: el servicio dura 30 min, así que la cita queda de 30 y cabe.
    citaPublica($ctx, '10:00', ['duracion_minutos' => 180])->assertCreated();

    $sesiones = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/sesiones?desde={$ctx['dia']}&hasta={$ctx['dia']}", conBearer($ctx['e']['bearer']))
        ->assertOk()->json('data');
    $cita = collect($sesiones)->firstWhere('tipo', 'cita');
    expect(CarbonImmutable::parse($cita['inicia_en'])->diffInMinutes(CarbonImmutable::parse($cita['termina_en'])))->toEqual(30);
});

it('no acepta a quien no atiende citas ni un servicio que no se agenda', function (): void {
    $ctx = barberiaQueValida();

    // El dueño no es profesional.
    $dueno = (string) $this->getJson("/api/v1/app/{$ctx['e']['slug']}/yo", conBearer($ctx['e']['bearer']))->json('data.usuario.ulid');
    citaPublica($ctx, '10:00', ['instructor_id' => $dueno])->assertStatus(422);

    // Una clase grupal sin cobro por cita no se agenda como cita.
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/ofertas/{$ctx['sede']['oferta']}", ['lugares' => 0, 'politica_reserva' => 'entitlement'], conBearer($ctx['e']['bearer']))
        ->assertOk();
    citaPublica($ctx, '10:00')->assertStatus(422);
});

it('una cita que se encima (aunque empiece a otra hora) se rechaza', function (): void {
    $ctx = barberiaQueValida();
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/ofertas/{$ctx['sede']['oferta']}", ['lugares' => 0, 'duracion_minutos' => 60], conBearer($ctx['e']['bearer']))
        ->assertOk();

    citaPublica($ctx, '10:00')->assertCreated();                   // 10:00–11:00
    citaPublica($ctx, '10:30')->assertStatus(422);                 // se encima a la mitad
    citaPublica($ctx, '09:30')->assertStatus(422);                 // termina dentro
    citaPublica($ctx, '11:00')->assertCreated();                   // justo después
});

it('recepción puede agendar fuera de horario, pero no encimado', function (): void {
    $ctx = barberiaQueValida();
    $cliente = crearMiembroTenant($ctx['e'], 'Marco');
    $carga = fn (string $hora): array => [
        'persona_id' => $cliente, 'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'instructor_id' => $ctx['pro'], 'inicia_en_local' => "{$ctx['dia']} {$hora}:00",
    ];

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", $carga('18:00'), conBearer($ctx['e']['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", $carga('18:15'), conBearer($ctx['e']['bearer']))->assertStatus(422);
});
