<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Antes de reservar y de cancelar, lo que va a pasar (plan de mejoras M05): con qué plan,
| cuánto consume, hasta cuándo cancela sin costo (la política que se congela en la
| reserva), o por qué no puede y qué hacer; y una cancelación no promete que un crédito
| de un plan vencido se pueda volver a usar.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México); la clase es el 5 a las 08:00.
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
 * @return array{bearer: string, derecho: string}
 */
function alumnaConPackAntesDeReservar(array $e, string $email = 'luz@correo.mx'): array
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Luz', 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    return ['bearer' => personalConSesion($e['slug'], $e['bearer'], $email, 'miembro'), 'derecho' => $derecho];
}

it('antes de reservar dice el plan, lo que consume y hasta cuándo cancela; la reserva dice lo mismo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $luz = alumnaConPackAntesDeReservar($e);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-05 08:00:00');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/".collect($this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))->json('data'))->first()['id'], [
        'lugares' => 0, 'que_llevar' => 'Short y top',
    ], conBearer($e['bearer']))->assertOk();

    $antes = $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($luz['bearer']))
        ->assertOk()->json('data');
    expect($antes)->toMatchArray([
        'resultado' => 'reserva',
        'unidades' => 1000,
        'que_llevar' => 'Short y top',
        'de_pago' => null,
    ])
        ->and($antes['plan'])->toMatchArray(['nombre' => 'Pack 8 clases', 'ilimitado' => false, 'disponible_despues' => 7000])
        ->and($antes['sucursal']['nombre'])->toBe('Roma Norte')
        // 6 h antes de las 08:00 de la Ciudad de México (UTC−6).
        ->and($antes['cancelacion'])->toMatchArray(['limite' => '2026-10-05T08:00:00+00:00', 'horas_limite' => 6, 'penaliza_tarde' => true]);

    $reserva = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz['bearer']))
        ->assertCreated()->json('data');
    expect($reserva)->toMatchArray(['estado' => 'confirmada', 'plan' => 'Pack 8 clases', 'unidades' => 1000, 'que_llevar' => 'Short y top'])
        ->and($reserva['cancelacion'])->toMatchArray(['limite' => $antes['cancelacion']['limite'], 'penaliza_tarde' => true]);

    // Ya la tiene: no se reserva dos veces y se le lleva a su reserva.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($luz['bearer']))
        ->assertOk()->assertJsonPath('data.resultado', 'no_disponible')
        ->assertJsonPath('data.motivo', 'ya_reservada')->assertJsonPath('data.accion', 'ver_reserva');
});

it('sin plan dice el motivo y lleva a ver planes; con la clase llena ofrece la lista de espera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sinPlan = alumnoConSesion($e, 'Mar', 'mar@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 1, '2026-10-05 08:00:00');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($sinPlan['bearer']))
        ->assertOk()->assertJsonPath('data.resultado', 'no_disponible')
        ->assertJsonPath('data.codigo', 'ENTITLEMENT_REQUIRED')
        ->assertJsonPath('data.motivo', 'sin_plan')->assertJsonPath('data.accion', 'ver_planes');

    // Una alumna ocupa el único lugar: la siguiente ve la lista de espera, sin consumo.
    $luz = alumnaConPackAntesDeReservar($e);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz['bearer']))->assertCreated();
    $sol = alumnaConPackAntesDeReservar($e, 'sol@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/antes-de-reservar", conBearer($sol['bearer']))
        ->assertOk()->assertJsonPath('data.resultado', 'lista_espera')
        ->assertJsonPath('data.unidades', 0)->assertJsonPath('data.plan.nombre', 'Pack 8 clases');
    $espera = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion, 'esperar' => true], conBearer($sol['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera')->json('data');
    // En espera no consume: no tiene límite de cancelación que cuidar.
    expect($espera['cancelacion'])->toBeNull();
});

it('cancelar no promete que el crédito de un plan vencido se pueda volver a usar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $luz = alumnaConPackAntesDeReservar($e);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-05 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz['bearer']))
        ->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($luz['bearer']))
        ->assertOk()->assertJsonPath('data.mensaje', 'Se devolverá 1 crédito.')->assertJsonPath('data.plan_vencio', null);

    // El negocio acortó la vigencia: ya venció ayer.
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => DerechoTenant::query()->where('ulid', $luz['derecho'])->update(['valido_hasta' => '2026-09-30']),
    );
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($luz['bearer']))
        ->assertOk()->assertJsonPath('data.credito', 'devuelve')->assertJsonPath('data.plan_vencio', '2026-09-30')
        ->assertJsonPath('data.mensaje', 'Se devolverá 1 crédito. Tu plan venció el 30/09/2026: el crédito vuelve a él, pero ya no podrás usarlo.');
});

it('sin política propia del negocio, la cuenta muestra la de la plataforma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $luz = alumnaConPackAntesDeReservar($e);

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($luz['bearer']))
        ->assertOk()->assertJsonPath('data.politica_cancelacion.horas_limite', 6)
        ->assertJsonPath('data.politica_cancelacion.penaliza_tarde', true);
});
