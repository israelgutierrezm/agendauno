<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Avisos al equipo del negocio: le llegan a quien tiene el permiso con el que se
| atiende lo que pasó (no por nombre de rol). Una solicitud de baja de datos y una
| reseña avisan de fábrica; los demás (venta, cobro fallido…) se activan si se quiere.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    cuentaDeServicioFcmDePrueba();
    $this->fcm = (object) ['envios' => []];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3599]);
        }
        if (str_contains($request->url(), 'fcm.googleapis.com')) {
            $this->fcm->envios[] = json_decode($request->body(), true)['message'];

            return Http::response(['name' => 'projects/agendauno-prueba/messages/1']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
    File::deleteDirectory(storage_path('framework/testing/fcm'));
});

/**
 * Negocio con dueña (con la app), recepcionista (sin la app) e instructor (con la
 * app), y una alumna (Vale) con cuenta.
 *
 * @return array{slug: string, bearer: string, vale: string}
 */
function negocioConEquipo(): array
{
    test()->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'duena@estudio.mx');
    test()->postJson("/api/v1/app/{$e['slug']}/mi/dispositivos", ['token' => 'token-duena', 'plataforma' => 'ios'], conBearer($e['bearer']))->assertCreated();
    personalConSesion($e['slug'], $e['bearer'], 'recepcion@estudio.mx', 'recepcionista');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@estudio.mx', 'instructor');
    test()->postJson("/api/v1/app/{$e['slug']}/mi/dispositivos", ['token' => 'token-coach', 'plataforma' => 'android'], conBearer($coach))->assertCreated();
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    return [...$e, 'vale' => $vale['bearer']];
}

/**
 * @param  array{slug: string}  $e
 * @return list<array{destinatario: string|null, canal: string, asunto: string, cuerpo: string}>
 */
function avisosAlEquipo(array $e): array
{
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): array => MensajeTenant::query()->whereNotNull('usuario_id')->orderBy('id')->get()
            ->map(fn (MensajeTenant $m): array => ['destinatario' => $m->destinatario, 'canal' => $m->canal->value, 'asunto' => $m->asunto, 'cuerpo' => $m->cuerpo])
            ->all(),
    );
}

it('una solicitud de baja de datos le llega a quien puede atenderla', function (): void {
    $e = negocioConEquipo();

    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Me mudo', 'password' => 'secreto123'], conBearer($e['vale']))->assertCreated();

    $avisos = avisosAlEquipo($e);
    // Correo a la dueña y a recepción (gestionan alumnos); push solo a la dueña, que
    // tiene la app. El instructor no atiende bajas: no recibe nada.
    expect(collect($avisos)->where('canal', 'email')->pluck('destinatario')->sort()->values()->all())
        ->toBe(['duena@estudio.mx', 'recepcion@estudio.mx'])
        ->and(collect($avisos)->where('canal', 'email')->first()['cuerpo'])->toContain('20 días hábiles')
        ->and(collect($avisos)->where('canal', 'email')->first()['cuerpo'])->toContain('/privacidad')
        ->and(collect($this->fcm->envios)->pluck('token')->all())->toBe(['token-duena'])
        ->and($this->fcm->envios[0]['notification']['title'])->toBe('Solicitud de baja de datos');
});

it('una reseña nueva le llega al equipo con la calificación', function (): void {
    $e = negocioConEquipo();
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-09-29 10:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->travelTo('2026-09-30 12:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    avisosAlEquipo($e); // lo que haya salido antes no cuenta
    $this->fcm->envios = [];

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/resena", ['calificacion' => 2, 'comentario' => 'Empezó tarde.'], conBearer($e['vale']))
        ->assertCreated();
    avisosAlEquipo($e);

    expect($this->fcm->envios)->toHaveCount(1)
        ->and($this->fcm->envios[0]['token'])->toBe('token-duena')
        ->and($this->fcm->envios[0]['notification'])->toBe([
            'title' => 'Nueva reseña: 2 de 5',
            'body' => 'Vale calificó Nivel 1. Empezó tarde.',
        ]);
});

it('los demás avisos al equipo vienen apagados y solo aplican a sus eventos', function (): void {
    $e = negocioConEquipo();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", conBearer($e['bearer']))->assertOk();
    $venta = collect($r->json('data'))->first(fn (array $p): bool => $p['clave'] === 'orden.pagada' && $p['destinatario'] === 'equipo');
    expect($venta['activo'])->toBeFalse()
        ->and($r->json('destinatarios.equipo'))->toContain('privacidad.baja_solicitada', 'orden.pagada')
        ->and($r->json('destinatarios.profesional'))->toContain('reserva.confirmada');

    // Una reserva no se avisa "al equipo" ni una venta "al profesional".
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.confirmada', 'canal' => 'push', 'destinatario' => 'equipo', 'asunto' => 'X', 'cuerpo' => 'Y',
    ], conBearer($e['bearer']))->assertUnprocessable();
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'orden.pagada', 'canal' => 'push', 'destinatario' => 'profesional', 'asunto' => 'X', 'cuerpo' => 'Y',
    ], conBearer($e['bearer']))->assertUnprocessable();

    // Activada, una venta le llega a quien ve la facturación (la dueña), no a recepción.
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'orden.pagada', 'canal' => 'push', 'destinatario' => 'equipo',
        'asunto' => $venta['asunto'], 'cuerpo' => $venta['cuerpo'], 'activo' => true,
    ], conBearer($e['bearer']))->assertCreated();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Bea'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    avisosAlEquipo($e);

    expect(collect($this->fcm->envios)->pluck('notification.title')->all())->toBe(['Venta: $899.00 MXN']);
});
