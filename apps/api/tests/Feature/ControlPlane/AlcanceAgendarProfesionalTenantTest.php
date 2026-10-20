<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lo que la app del equipo pide al agendar (ADR 0095, R19): quien imparte agenda y pasa
| citas solo a su propia agenda (salvo un rol del equipo en esa sede), y quien está
| acotado a algunas sucursales solo ve esas al elegir dónde.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería de citas con un barbero de sistema y uno con rol propio que puede agendar.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, junior: string, juniorId: string, barberoId: string, cliente: string, dia: string}
 */
function barberiaConJuniorQueAgenda(): array
{
    $e = estudioConSesion('barberia-junior', 'dueno@barberia-junior.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    $rol = (string) test()->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Barbero que agenda', 'faceta' => 'instructor',
        'permisos' => ['agenda.ver', 'reservas.ver', 'reservas.gestionar', 'miembros.ver', 'catalogo.ver', 'sucursales.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $junior = personalConSesion($e['slug'], $e['bearer'], 'junior@barberia-junior.mx', $rol);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-junior.mx', 'instructor');
    pasarNegocioACitas($e);

    return [
        'e' => $e, 'sede' => $sede, 'junior' => $junior,
        'juniorId' => usuarioIdPorEmail($e, 'junior@barberia-junior.mx'),
        'barberoId' => usuarioIdPorEmail($e, 'barbero@barberia-junior.mx'),
        'cliente' => crearMiembroTenant($e, 'Marco'),
        'dia' => now()->addDays(2)->format('Y-m-d'),
    ];
}

/**
 * @param  array{sede: array{oferta: string, sucursal: string}, cliente: string, dia: string}  $ctx
 * @return array<string, string>
 */
function citaConProfesional(array $ctx, string $profesional, string $hora = '11:00'): array
{
    return [
        'persona_id' => $ctx['cliente'], 'oferta_id' => $ctx['sede']['oferta'],
        'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => "{$ctx['dia']} {$hora}:00",
    ];
}

it('quien imparte agenda citas solo consigo', function (): void {
    $ctx = barberiaConJuniorQueAgenda();
    $url = "/api/v1/app/{$ctx['e']['slug']}/agenda/citas";

    $this->postJson($url, citaConProfesional($ctx, $ctx['barberoId']), conBearer($ctx['junior']))
        ->assertForbidden()->assertJsonPath('message', 'Solo puedes agendar citas contigo.');
    $this->postJson($url, citaConProfesional($ctx, $ctx['juniorId']), conBearer($ctx['junior']))
        ->assertCreated()->assertJsonPath('data.instructor_id', $ctx['juniorId']);

    // El dueño agenda con cualquiera.
    $this->postJson($url, citaConProfesional($ctx, $ctx['barberoId'], '12:00'), conBearer($ctx['e']['bearer']))
        ->assertCreated();
});

it('quien imparte no pasa su cita a otro profesional', function (): void {
    $ctx = barberiaConJuniorQueAgenda();
    $reserva = (string) $this->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", citaConProfesional($ctx, $ctx['juniorId']), conBearer($ctx['junior']))
        ->assertCreated()->json('data.cita.reserva_id');
    $url = "/api/v1/app/{$ctx['e']['slug']}/reservas/{$reserva}/reprogramar";

    $this->postJson($url, ['inicia_en_local' => "{$ctx['dia']} 13:00:00", 'instructor_id' => $ctx['barberoId']], conBearer($ctx['junior']))
        ->assertForbidden();
    $this->postJson($url, ['inicia_en_local' => "{$ctx['dia']} 13:00:00", 'instructor_id' => $ctx['juniorId']], conBearer($ctx['junior']))
        ->assertOk();
    $this->postJson($url, ['inicia_en_local' => "{$ctx['dia']} 14:00:00", 'instructor_id' => $ctx['barberoId']], conBearer($ctx['e']['bearer']))
        ->assertOk();
});

it('quien está acotado a una sede solo ve esa sucursal', function (): void {
    $e = estudioConSesion('dos-sedes', 'a@dos-sedes.mx');
    $sedeA = agendaSemilla($e);
    agendaSemilla($e);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@dos-sedes.mx', 'recepcionista');
    $sinSede = personalConSesion($e['slug'], $e['bearer'], 'nueva@dos-sedes.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@dos-sedes.mx'), $sedeA['sucursal']);
    $url = "/api/v1/app/{$e['slug']}/sucursales";

    expect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(2);
    expect(collect($this->getJson($url, conBearer($recep))->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$sedeA['sucursal']]);
    // Sin sede asignada (con varias), ninguna (ADR 0098).
    expect($this->getJson($url, conBearer($sinSede))->assertOk()->json('data'))->toBe([]);
});
