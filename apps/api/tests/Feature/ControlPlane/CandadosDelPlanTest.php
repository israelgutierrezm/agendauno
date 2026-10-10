<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CupoProfesionales;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\PuntosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Lo que el plan de un negocio de citas no incluye (ADR 0107) tampoco se cuela por otro
| lado: la API de integración con una llave creada en la prueba, los procesos en
| segundo plano (webhooks, automatizaciones, puntos), el equipo que no atiende al dar
| roles o reactivar, ni el país que define cómo se cobra la renta.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Termina la prueba y deja al negocio en ese nivel.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function dejarEnNivel(array $e, string $nivel, int $profesionales): void
{
    terminarPrueba($e);
    Estudio::query()->where('slug', $e['slug'])->update(['plan_nivel' => $nivel, 'plan_profesionales' => $profesionales]);
}

/**
 * @param  array{slug: string}  $e
 */
function enNegocioCandados(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), $fn);
}

/**
 * Una reserva y una asistencia de la persona (eventos del outbox) y corre el relay.
 *
 * @param  array{slug: string}  $e
 */
function eventosDeLaClienta(array $e, string $persona, string $reserva): void
{
    enNegocioCandados($e, function () use ($persona, $reserva): void {
        $eventos = app(RegistrarEventoTenant::class);
        $eventos->registrar('reserva.creada', 'reserva', $reserva, ['persona_id' => $persona, 'reserva_id' => $reserva]);
        $eventos->registrar('asistencia.marcada', 'reserva', $reserva, ['persona_id' => $persona, 'reserva_id' => $reserva, 'estado' => 'presente']);
    });
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->artisan('agendauno:reintentar-webhooks')->assertSuccessful();
}

it('una llave de API creada en la prueba deja de servir en Premium', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $secreto = (string) $this->postJson("/api/v1/app/{$e['slug']}/llaves-api", ['nombre' => 'Agenda externa', 'scopes' => ['miembros.ver']], conBearer($e['bearer']))
        ->assertCreated()->json('data.secreto');
    $this->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => $secreto])->assertOk();

    dejarEnNivel($e, 'premium', 2);

    $this->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => $secreto])
        ->assertStatus(403)
        ->assertJsonPath('code', 'PLAN_FEATURE_NOT_INCLUDED')
        ->assertJsonPath('meta.funcion', 'integraciones');
});

it('en segundo plano, sin las funciones en su plan no salen webhooks, no corren automatizaciones ni se dan puntos', function (): void {
    dnsFalso(['ejemplo.test' => ['93.184.216.34']]);
    Http::fake(['*' => Http::response('ok', 200)]);
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    // En la prueba (Pro) configuró todo.
    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", ['url' => 'https://ejemplo.test/hook', 'eventos' => ['reserva.creada']], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/automatizaciones", ['nombre' => 'Seguimiento', 'evento' => 'reserva.creada', 'titulo_plantilla' => 'Llamar a {{persona_nombre}}'], conBearer($e['bearer']))
        ->assertCreated();
    $this->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", ['activa' => true, 'puntos_por_asistencia' => 10, 'puntos_por_moneda' => 1], conBearer($e['bearer']))
        ->assertOk();
    $persona = crearMiembroTenant($e, 'Ana');
    $personaId = (int) enNegocioCandados($e, fn () => PersonaTenant::query()->where('ulid', $persona)->value('id'));

    dejarEnNivel($e, 'premium', 2);
    eventosDeLaClienta($e, $persona, 'reserva-1');

    Http::assertNothingSent();
    expect(enNegocioCandados($e, fn (): array => [TareaTenant::query()->count(), app(PuntosTenant::class)->saldo($personaId)]))->toBe([0, 0]);

    // Con Pro, todo vuelve a correr.
    dejarEnNivel($e, 'pro', 2);
    eventosDeLaClienta($e, $persona, 'reserva-2');

    Http::assertSentCount(1);
    expect(enNegocioCandados($e, fn (): array => [TareaTenant::query()->count(), app(PuntosTenant::class)->saldo($personaId)]))->toBe([1, 10]);
});

it('Individual invita clientes, pero no da roles del equipo ni reactiva a quien no atiende', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    personalConSesion($e['slug'], $e['bearer'], 'recepcion@barberia.mx', 'recepcionista');
    $recepcion = usuarioIdPorEmail($e, 'recepcion@barberia.mx');
    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$recepcion}", [], conBearer($e['bearer']))->assertOk();
    dejarEnNivel($e, 'individual', 1);

    // Un cliente no es equipo.
    alumnoConSesion($e);
    $clienta = usuarioIdPorEmail($e, 'vale@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$clienta}/roles", ['roles' => ['miembro', 'recepcionista']], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'equipo');
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$recepcion}/reactivar", [], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'equipo');
    // Quitar roles o darle el de profesional (cabe en su plan) sí.
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$clienta}/roles", ['roles' => ['miembro', 'instructor']], conBearer($e['bearer']))
        ->assertOk();
});

it('los profesionales se suman con la fila del negocio bloqueada y sin pasar de los contratados', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    dejarEnNivel($e, 'premium', 2);
    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();

    // Cuenta y da de alta dentro de una transacción del control plane (con el bloqueo).
    $afuera = DB::transactionLevel();
    $adentro = enNegocioCandados($e, fn (): int => app(CupoProfesionales::class)->sumar($estudio, 1, fn (): int => DB::transactionLevel()));
    expect($adentro)->toBe($afuera + 1);

    foreach (['beto', 'carlos'] as $nombre) {
        $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => $nombre, 'email' => "{$nombre}@barberia.mx", 'rol' => 'instructor'], conBearer($e['bearer']))
            ->assertCreated();
    }
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Dani', 'email' => 'dani@barberia.mx', 'rol' => 'instructor'], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'PROFESSIONAL_SEATS_EXCEEDED');
});

it('las funciones que faltan se deciden leyendo la tarifa una sola vez', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    dejarEnNivel($e, 'individual', 1);
    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();

    $lecturas = 0;
    DB::listen(function (QueryExecuted $consulta) use (&$lecturas): void {
        if (str_contains($consulta->sql, 'tarifas_saas')) {
            $lecturas++;
        }
    });
    $sin = app(FuncionesPlan::class)->faltantes($estudio);

    expect($sin)->toContain('equipo', 'lealtad', 'integraciones')
        ->and($lecturas)->toBeLessThanOrEqual(2);
});

it('pasada la prueba o con un cargo emitido, el país solo lo cambia soporte', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.puede_cambiar_pais', true)->assertJsonPath('data.motivo_pais', null);

    terminarPrueba($e);
    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.puede_cambiar_pais', false)->assertJsonPath('data.motivo_pais', RegionNegocioTenant::MOTIVO_PAIS);
    $this->putJson("/api/v1/app/{$e['slug']}/negocio/region", ['pais' => 'CO', 'zona_horaria' => 'America/Bogota'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.pais.0', RegionNegocioTenant::MOTIVO_PAIS);
    // Lo demás de la región sí (mandar su mismo país no es un cambio).
    $this->putJson("/api/v1/app/{$e['slug']}/negocio/region", ['pais' => 'mx', 'zona_horaria' => 'America/Bogota'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.pais', 'MX')->assertJsonPath('data.zona_horaria', 'America/Bogota');

    // Con un cargo emitido, aunque se le extienda la prueba.
    cargoRentaPendiente($e);
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 10], conPlataforma())->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.puede_cambiar_pais', false);

    // Soporte sí lo cambia.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/pais", ['pais' => 'ZZ'], conPlataforma())
        ->assertUnprocessable()->assertJsonPath('meta.errors.pais.0', 'Elige un país de la lista.');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/pais", ['pais' => 'co'], conPlataforma())
        ->assertOk()->assertJsonPath('data.pais', 'CO');
    expect(Estudio::query()->where('slug', $e['slug'])->value('pais'))->toBe('CO');
});
