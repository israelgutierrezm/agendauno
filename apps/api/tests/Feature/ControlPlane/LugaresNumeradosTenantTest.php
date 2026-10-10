<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lugares numerados de principio a fin (plan de mejoras B06): el alumno ve qué lugares
| están libres (sin saber quién tiene los otros), elige el suyo al reservar o después, y
| dos personas no se quedan con el mismo. Un lugar fuera del catálogo o ya tomado se
| rechaza, nunca se cambia a otro en silencio. Las clases sin lugares siguen igual.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alumna con un pack de 8 clases y su cuenta.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function alumnaConPackLugares(array $e, string $nombre, string $email): string
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => $nombre, 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated();

    return personalConSesion($e['slug'], $e['bearer'], $email, 'miembro');
}

/**
 * Una clase con `n` lugares numerados (0 = sin numerar); devuelve el ulid de la sesión.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function claseConLugaresNumerados(array $e, int $n): string
{
    $semilla = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}", ['lugares' => $n], conBearer($e['bearer']))->assertOk();

    return crearSesionTenant($e, $semilla, 8, '2026-10-05 08:00:00');
}

it('el alumno elige su lugar al reservar y nadie más puede tomarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = claseConLugaresNumerados($e, 5);
    $luz = alumnaConPackLugares($e, 'Luz', 'luz@correo.mx');
    $sol = alumnaConPackLugares($e, 'Sol', 'sol@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($luz))
        ->assertOk()->assertJsonPath('data.lugares', ['total' => 5, 'ocupados' => []]);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'lugar' => 3], conBearer($luz))
        ->assertCreated()->assertJsonPath('data.lugar', 3)->assertJsonPath('data.lugares', 5);

    // Sol ve el 3 ocupado, sin saber por quién.
    $lugares = $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/lugares", conBearer($sol))
        ->assertOk()->json('data');
    expect($lugares)->toBe(['total' => 5, 'ocupados' => [3], 'mio' => null]);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'lugar' => 3], conBearer($sol))
        ->assertUnprocessable()->assertJsonPath('code', 'SPOT_UNAVAILABLE');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'lugar' => 6], conBearer($sol))
        ->assertUnprocessable()->assertJsonPath('code', 'SPOT_UNAVAILABLE');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'lugar' => 4], conBearer($sol))
        ->assertCreated()->assertJsonPath('data.lugar', 4);
});

it('cambia su lugar a uno libre; uno tomado o de otra persona se rechaza', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = claseConLugaresNumerados($e, 5);
    $luz = alumnaConPackLugares($e, 'Luz', 'luz@correo.mx');
    $sol = alumnaConPackLugares($e, 'Sol', 'sol@correo.mx');
    // Luz reserva sin elegir lugar; Sol toma el 2.
    $deLuz = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz))
        ->assertCreated()->assertJsonPath('data.lugar', null)->json('data.id');
    $deSol = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'lugar' => 2], conBearer($sol))
        ->assertCreated()->json('data.id');

    $this->putJson("/api/v1/app/{$e['slug']}/mi/reservas/{$deLuz}/lugar", ['lugar' => 2], conBearer($luz))
        ->assertUnprocessable()->assertJsonPath('code', 'SPOT_UNAVAILABLE');
    $this->putJson("/api/v1/app/{$e['slug']}/mi/reservas/{$deLuz}/lugar", ['lugar' => 5], conBearer($luz))
        ->assertOk()->assertJsonPath('data.lugar', 5);
    // Cambiar de nuevo libera el anterior.
    $this->putJson("/api/v1/app/{$e['slug']}/mi/reservas/{$deLuz}/lugar", ['lugar' => 1], conBearer($luz))
        ->assertOk()->assertJsonPath('data.lugar', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/lugares", conBearer($luz))
        ->assertOk()->assertJsonPath('data.ocupados', [1, 2])->assertJsonPath('data.mio', 1);

    $this->putJson("/api/v1/app/{$e['slug']}/mi/reservas/{$deSol}/lugar", ['lugar' => 3], conBearer($luz))->assertForbidden();
});

it('una clase sin lugares numerados sigue igual', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = claseConLugaresNumerados($e, 0);
    $luz = alumnaConPackLugares($e, 'Luz', 'luz@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($luz))
        ->assertOk()->assertJsonPath('data.lugares', null);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz))
        ->assertCreated()->assertJsonPath('data.lugares', 0)->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/lugar", ['lugar' => 1], conBearer($luz))
        ->assertUnprocessable()->assertJsonPath('code', 'SPOT_UNAVAILABLE');
});
