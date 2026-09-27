<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alta de un miembro con login: persona (con correo) + pack vendido + usuario
 * invitado con el mismo correo (rol miembro). Devuelve el bearer del miembro y el
 * ulid de su persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string}
 */
function miembroConAcceso(array $e, string $email = 'ana@correo.mx'): array
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $pack = crearPackTenant($e, 8000);
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $pack,
    ], conBearer($e['bearer']))->assertCreated();

    $bearer = personalConSesion($e['slug'], $e['bearer'], $email, 'miembro');

    return ['bearer' => $bearer, 'persona' => $persona];
}

it('el miembro ve su perfil con sus derechos (enlace por correo)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = miembroConAcceso($e);

    test()->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.persona.email', 'ana@correo.mx')
        ->assertJsonPath('data.derechos.0.saldo', 8000)
        ->assertJsonCount(0, 'data.reservas');
});

it('el miembro reserva una sesion y aparece en su perfil', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = miembroConAcceso($e);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($m['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada');

    test()->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data.reservas')
        // El hold consumio 1 credito del disponible.
        ->assertJsonPath('data.derechos.0.disponible', 7000);
});

it('el miembro cancela su propia reserva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = miembroConAcceso($e);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($m['bearer']))
        ->assertCreated()->json('data.id');

    test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelar", [], conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('el miembro no puede cancelar la reserva de otro (403)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = miembroConAcceso($e, 'ana@correo.mx');
    $beto = miembroConAcceso($e, 'beto@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    // Beto reserva; Ana intenta cancelar la reserva de Beto.
    $reservaBeto = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($beto['bearer']))
        ->assertCreated()->json('data.id');

    test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reservaBeto}/cancelar", [], conBearer($ana['bearer']))
        ->assertStatus(403);
});

it('el miembro ve la agenda de sesiones proximas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = miembroConAcceso($e);
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla);

    test()->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.oferta', 'Nivel 1');
});

it('la agenda y las reservas del miembro traen la hora de fin (para su calendario)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = miembroConAcceso($e);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    $agenda = test()->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.0.instructor', null)->json('data.0');
    // La sesión dura 60 minutos.
    expect(strtotime($agenda['termina_en']) - strtotime($agenda['inicia_en']))->toBe(3600);

    test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($m['bearer']))
        ->assertCreated();
    $reserva = test()->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()->json('data.reservas.0');
    expect($reserva)->toHaveKey('instructor')
        ->and($reserva['termina_en'])->toBe($agenda['termina_en']);
});
