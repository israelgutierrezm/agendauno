<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Avisos al profesional de sus citas: cuando le agendan o le cancelan una cita le
| llega una notificación en la app (o un correo, si el negocio lo configura). En una
| clase grupal no se avisa al instructor por cada reserva.
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
 * Barbería con un servicio de pago, un barbero con la app y un cliente (Marco); la
 * cita es el jueves 1 de octubre a las 10:00 de CDMX.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, barbero: string, cliente: string}
 */
function barberoConApp(): array
{
    test()->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    $barbero = personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    test()->postJson("/api/v1/app/{$e['slug']}/mi/dispositivos", ['token' => 'token-barbero', 'plataforma' => 'android'], conBearer($barbero))
        ->assertCreated();
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'barbero' => $barbero, 'cliente' => crearMiembroTenant($e, 'Marco')];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cliente: string}  $ctx
 * @return array<string, mixed>
 */
function agendarCitaDeMarco(array $ctx): array
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", [
        'persona_id' => $ctx['cliente'], 'oferta_id' => $ctx['sede']['oferta'],
        'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => '2026-10-01 10:00:00',
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data');
}

/**
 * @param  array{slug: string}  $e
 */
function publicarYEnviar(array $e): void
{
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->artisan('agendauno:enviar-mensajes')->assertSuccessful();
}

it('al barbero le llega la cita nueva y la cancelada', function (): void {
    $ctx = barberoConApp();
    $cita = agendarCitaDeMarco($ctx);
    publicarYEnviar($ctx['e']);

    expect($this->fcm->envios)->toHaveCount(1)
        ->and($this->fcm->envios[0]['token'])->toBe('token-barbero')
        ->and($this->fcm->envios[0]['notification'])->toBe([
            'title' => 'Nueva cita: Marco',
            'body' => 'Nivel 1 · jueves 1 de octubre a las 10:00 en Roma Norte',
        ]);

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/reservas/{$cita['cita']['reserva_id']}/cancelar", [], conBearer($ctx['e']['bearer']))->assertOk();
    publicarYEnviar($ctx['e']);

    expect($this->fcm->envios)->toHaveCount(2)
        ->and($this->fcm->envios[1]['notification']['title'])->toBe('Cita cancelada: Marco');

    // En la bandeja de salida se ve a quién se envió: al barbero.
    $salida = $this->getJson("/api/v1/app/{$ctx['e']['slug']}/mensajes", conBearer($ctx['e']['bearer']))->assertOk()->json('data');
    expect(collect($salida)->where('canal', 'push')->pluck('persona')->unique()->values()->all())->toBe(['Personal']);
});

it('en una clase grupal no se avisa al instructor por cada reserva', function (): void {
    $ctx = barberoConApp();
    $clase = (string) $this->postJson("/api/v1/app/{$ctx['e']['slug']}/sesiones", [
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => '2026-10-01 18:00:00', 'duracion_minutos' => 60, 'capacidad' => 10,
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/ofertas/{$ctx['sede']['oferta']}", ['lugares' => 12, 'politica_reserva' => 'entitlement'], conBearer($ctx['e']['bearer']))
        ->assertOk();
    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/acuerdos", ['persona_id' => $ctx['cliente'], 'producto_id' => crearPackTenant($ctx['e'])], conBearer($ctx['e']['bearer']))
        ->assertCreated();

    $this->postJson("/api/v1/app/{$ctx['e']['slug']}/sesiones/{$clase}/reservas", ['persona_id' => $ctx['cliente']], conBearer($ctx['e']['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada');
    publicarYEnviar($ctx['e']);

    expect($this->fcm->envios)->toHaveCount(0);
});

it('el negocio puede avisarle también por correo, pero no a una bandeja del equipo', function (): void {
    $ctx = barberoConApp();
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.confirmada', 'canal' => 'email', 'destinatario' => 'profesional',
        'asunto' => 'Te agendaron a {{persona_nombre}}', 'cuerpo' => '{{fecha}} a las {{hora}}.',
    ], conBearer($ctx['e']['bearer']))->assertCreated()->assertJsonPath('data.destinatario', 'profesional');
    $this->putJson("/api/v1/app/{$ctx['e']['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.confirmada', 'canal' => 'interno', 'destinatario' => 'profesional',
        'asunto' => 'X', 'cuerpo' => 'Y',
    ], conBearer($ctx['e']['bearer']))->assertUnprocessable();

    agendarCitaDeMarco($ctx);
    publicarYEnviar($ctx['e']);

    $correos = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'barberia-a')->firstOrFail(),
        fn () => MensajeTenant::query()->where('canal', 'email')->where('asunto', 'like', 'Te agendaron%')->get(['destinatario', 'asunto'])->toArray(),
    );
    expect($correos)->toBe([['destinatario' => 'barbero@barberia.mx', 'asunto' => 'Te agendaron a Marco']]);
});
