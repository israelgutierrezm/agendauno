<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Los horarios para agendar o cambiar una cita traen, además de la hora en UTC, la hora
| en la sede (`inicia_local`) y su zona: la app la muestra y la manda tal cual como
| `inicia_en_local`, aunque el teléfono esté en otra zona. La sede está en Cancún
| (UTC-5) y el negocio en la Ciudad de México (UTC-6).
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
 * Negocio de citas de 60 min con su sede en Cancún, una profesional de 08:00 a 20:00
 * (hora de la sede) y una clienta con cuenta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}
 */
function negocioDeCitasEnCancun(): array
{
    $e = estudioConSesion('estudio-cancun', 'dueno@cancun.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Cancun'], conBearer($e['bearer']))
        ->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'pro@cancun.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'ana' => alumnoConSesion($e, 'Ana', 'ana@cancun.mx')];
}

it('ofrece los horarios con la hora de la sede y agenda esa misma hora', function (): void {
    $n = negocioDeCitasEnCancun();
    $slug = $n['e']['slug'];
    $consulta = "sucursal_id={$n['sede']['sucursal']}&fecha=2026-10-05&oferta_id={$n['sede']['oferta']}";

    $datos = $this->getJson("/api/v1/app/{$slug}/mi/citas/disponibilidad?instructor_id={$n['pro']}&{$consulta}", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.zona_horaria', 'America/Cancun')->json('data');
    // 08:00 en Cancún son las 13:00 UTC (en CDMX serían las 14:00).
    expect($datos['slots'][0])->toMatchArray(['inicia' => '2026-10-05T13:00:00+00:00', 'inicia_local' => '2026-10-05T08:00']);
    $diez = collect($datos['slots'])->firstWhere('inicia_local', '2026-10-05T10:00');
    expect($diez['inicia'])->toBe('2026-10-05T15:00:00+00:00');

    // Con «cualquier profesional» también viene la hora de la sede.
    $this->getJson("/api/v1/app/{$slug}/mi/citas/disponibilidad?{$consulta}", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.zona_horaria', 'America/Cancun')
        ->assertJsonPath('data.slots.0.inicia_local', '2026-10-05T08:00');

    // Se manda la hora tal cual llegó: queda a las 10:00 de Cancún.
    $this->postJson("/api/v1/app/{$slug}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => $diez['inicia_local'], 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.inicia_en', '2026-10-05T15:00:00+00:00')
        ->assertJsonPath('data.zona_horaria', 'America/Cancun');
});

it('ofrece los horarios para cambiar la cita con la hora de la sede y la mueve a esa hora', function (): void {
    $n = negocioDeCitasEnCancun();
    $slug = $n['e']['slug'];
    $cita = $this->postJson("/api/v1/app/{$slug}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$slug}/ordenes/{$cita['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($n['e']['bearer']))->assertOk();

    $opciones = $this->getJson("/api/v1/app/{$slug}/mi/reservas/{$cita['id']}/reprogramar?fecha=2026-10-06", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.puede', true)->assertJsonPath('data.zona_horaria', 'America/Cancun')
        ->json('data.slots');
    $mediodia = collect($opciones)->firstWhere('inicia_local', '2026-10-06T12:00');
    expect($mediodia['inicia'])->toBe('2026-10-06T17:00:00+00:00');

    $this->postJson("/api/v1/app/{$slug}/mi/reservas/{$cita['id']}/reprogramar", ['inicia_en_local' => $mediodia['inicia_local']], conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.ahora', '2026-10-06T17:00:00+00:00');
});

it('da las otras fechas de la clase con la hora de la sede', function (): void {
    $e = estudioConSesion('estudio-cancun', 'dueno@cancun.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Cancun'], conBearer($e['bearer']))
        ->assertOk();
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ana@cancun.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'ana@cancun.mx', 'miembro');
    $lunes = crearSesionTenant($e, $sede, 5, '2026-10-05 08:00:00');
    $miercoles = crearSesionTenant($e, $sede, 5, '2026-10-07 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $lunes], conBearer($bearer))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", conBearer($bearer))
        ->assertOk()
        ->assertJsonPath('data.sesiones.0.id', $miercoles)
        ->assertJsonPath('data.sesiones.0.inicia_en', '2026-10-07T13:00:00+00:00')
        ->assertJsonPath('data.sesiones.0.inicia_local', '2026-10-07T08:00')
        ->assertJsonPath('data.sesiones.0.zona_horaria', 'America/Cancun');
});
