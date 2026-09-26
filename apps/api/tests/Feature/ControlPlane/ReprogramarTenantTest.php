<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/*
| 2.1 de la fase 2: reprogramar sin cancelar y volver a capturar. Una cita pagada se
| mueve sin volver a cobrar y, si el nuevo horario ya no está libre, queda intacta; un
| alumno se mueve a otra fecha de su clase con su crédito; una clase completa cambia
| de horario con todas sus reservas. Y (2.7) no llega el recordatorio del horario
| anterior: llega el aviso del cambio.
| Hoy es 1 de enero de 2030, 06:00 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio de citas con una profesional (08:00–20:00 todos los días) y una clienta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}
 */
function negocioParaReprogramar(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'pro@correo.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'ana' => alumnoConSesion($e, 'Ana', 'ana@correo.mx')];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}  $n
 * @return array<string, mixed>
 */
function citaPagada(array $n, string $cuando): array
{
    $cita = test()->postJson("/api/v1/app/{$n['e']['slug']}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))->assertCreated()->json('data');
    test()->postJson("/api/v1/app/{$n['e']['slug']}/ordenes/{$cita['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($n['e']['bearer']))->assertOk();

    return $cita;
}

/**
 * Publica lo pendiente y devuelve los mensajes (asunto, cuerpo, estado) a ese correo.
 *
 * @param  array{slug: string}  $e
 * @return list<array{asunto: string, cuerpo: string, estado: string}>
 */
function mensajesAlCliente(array $e, string $email): array
{
    test()->artisan('turnouno:despachar-outbox')->assertSuccessful();

    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), fn (): array => MensajeTenant::query()
        ->where('destinatario', $email)->orderBy('id')->get()
        ->map(fn (MensajeTenant $m): array => ['asunto' => (string) $m->asunto, 'cuerpo' => (string) $m->cuerpo, 'estado' => $m->estado->value])
        ->all());
}

it('recepción mueve una cita pagada sin volver a cobrar y avisa del cambio', function (): void {
    $n = negocioParaReprogramar();
    $e = $n['e'];
    $cita = citaPagada($n, '2030-01-07 10:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['id']}/reprogramar", ['inicia_en_local' => '2030-01-08 12:00:00'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.reserva', $cita['id'])->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonPath('data.antes', '2030-01-07T16:00:00+00:00')->assertJsonPath('data.ahora', '2030-01-08T18:00:00+00:00');

    // La misma reserva, ya pagada: nada nuevo por cobrar.
    $mias = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($n['ana']['bearer']))->json('data'));
    expect($mias)->toHaveCount(1)->and($mias[0]['estado'])->toBe('pagada');

    $aviso = collect(mensajesAlCliente($e, 'ana@correo.mx'))->firstWhere('asunto', 'Cambio de horario: Nivel 1');
    expect($aviso['cuerpo'])->toContain('Antes: lunes 7 de enero a las 10:00')
        ->toContain('Ahora: martes 8 de enero a las 12:00');
});

it('si el nuevo horario ya no está libre, la cita original queda intacta', function (): void {
    $n = negocioParaReprogramar();
    $e = $n['e'];
    $cita = citaPagada($n, '2030-01-07 10:00:00');
    citaPagada($n, '2030-01-07 15:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['id']}/reprogramar", ['inicia_en_local' => '2030-01-07 15:30:00'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.instructor_id.0', 'Esa persona ya atiende Nivel 1 a las 15:00.');

    $mia = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($n['ana']['bearer']))->json('data.reservas'))->firstWhere('id', $cita['id']);
    expect($mia['inicia_en'])->toBe('2030-01-07T16:00:00+00:00')->and($mia['estado'])->toBe('confirmada');
});

it('no llega el recordatorio del horario anterior, sí el del nuevo', function (): void {
    $n = negocioParaReprogramar();
    $e = $n['e'];
    $cita = citaPagada($n, '2030-01-02 10:00:00');

    // Un día antes sale el recordatorio de las 10:00 del día 2…
    $this->travelTo('2030-01-01 17:00:00');
    $this->artisan('turnouno:enviar-recordatorios')->assertSuccessful();
    // …y antes de enviarse, la cita se mueve al día 4.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['id']}/reprogramar", ['inicia_en_local' => '2030-01-04 11:00:00'], conBearer($e['bearer']))->assertOk();

    $recordatorios = fn (): Collection => collect(mensajesAlCliente($e, 'ana@correo.mx'))
        ->filter(fn (array $m): bool => str_starts_with($m['asunto'], 'Recordatorio:') && $m['estado'] !== 'descartado')
        ->pluck('asunto')->values();
    expect($recordatorios())->toHaveCount(0);

    // El del nuevo horario llega a su hora.
    $this->travelTo('2030-01-03 17:30:00');
    $this->artisan('turnouno:enviar-recordatorios')->assertSuccessful();
    expect($recordatorios()->all())->toBe(['Recordatorio: Nivel 1 el viernes 4 de enero a las 11:00']);
});

it('un alumno se mueve a otra fecha de su clase con su crédito, sin encimarse ni pasarse del cupo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $lunes = crearSesionTenant($e, $sede, 1, '2030-01-07 08:00:00');
    $miercoles = crearSesionTenant($e, $sede, 1, '2030-01-09 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$lunes}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/reprogramar", ['sesion_id' => $miercoles], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sesion_id', $miercoles);
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$miercoles}/reservas", conBearer($e['bearer']))->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$lunes}/reservas", conBearer($e['bearer']))->assertJsonCount(0, 'data');
    // Su crédito sigue apartado (uno), no se cobró ni se devolvió.
    $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertJsonPath('saldo', 8000)->assertJsonPath('disponible', 7000);

    // El miércoles ya está lleno para otra persona; y no se mueve a otra clase distinta.
    $otra = venderPackAMiembroTenant($e, 8000, 'Beto');
    $deBeto = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$lunes}/reservas", ['persona_id' => $otra['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$deBeto}/reprogramar", ['sesion_id' => $miercoles], conBearer($e['bearer']))
        ->assertStatus(409);
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Yoga'], conBearer($e['bearer']))->json('data.id');
    $act = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$actividad}/actividades", ['nombre' => 'Yoga'], conBearer($e['bearer']))->json('data.id');
    $yoga = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$act}/ofertas", ['nombre' => 'Yoga', 'modalidad' => 'grupal', 'capacidad' => 5], conBearer($e['bearer']))->json('data.id');
    $sesionYoga = crearSesionTenant($e, ['oferta' => $yoga, 'sucursal' => $sede['sucursal']], 5, '2030-01-10 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$deBeto}/reprogramar", ['sesion_id' => $sesionYoga], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
});

it('una clase completa cambia de horario y cada alumno recibe el aviso; lo ocurrido no se mueve', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00');
    foreach (['Bea' => 'bea@correo.mx', 'Caro' => 'caro@correo.mx'] as $nombre => $email) {
        $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => $nombre, 'email' => $email, 'tipo' => 'miembro'], conBearer($e['bearer']))->json('data.id');
        $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reprogramar", ['inicia_en_local' => '2030-01-07 19:00:00'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.ahora', '2030-01-08T01:00:00+00:00');

    foreach (['bea@correo.mx', 'caro@correo.mx'] as $email) {
        $aviso = collect(mensajesAlCliente($e, $email))->firstWhere('asunto', 'Cambio de horario: Nivel 1');
        expect($aviso['cuerpo'])->toContain('Antes: lunes 7 de enero a las 08:00')->toContain('Ahora: lunes 7 de enero a las 19:00');
    }

    // Con asistencia registrada, ya ocurrió: no se mueve.
    $reserva = (string) $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reprogramar", ['inicia_en_local' => '2030-01-07 20:00:00'], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RESERVATION_ATTENDED');
});
