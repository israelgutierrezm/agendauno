<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Wellhub y TotalPass con límites claros (plan de mejoras M10): una visita es aprobada,
| rechazada o «sin verificar» cuando la plataforma no responde (sin conexión, error de
| su lado o credenciales que no acepta). Sin verificar nunca cuenta como aprobada: se
| reintenta con el mismo código o alguien con permiso la registra como excepción, con
| su motivo y lo que comprobó. Del token de TotalPass solo queda su huella y los
| cambios de credenciales van a la bitácora sin sus valores.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioBienestar(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, string>  $credenciales
 */
function conectarBienestar(array $e, string $proveedor, array $credenciales): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/{$proveedor}", [
        'activa' => true, 'credenciales' => $credenciales,
    ], conBearer($e['bearer']))->assertOk();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function visitaBienestar(array $e, string $sesion, string $proveedor, string $codigo, ?string $bearer = null): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/checkins", [
        'proveedor' => $proveedor, 'sesion_id' => $sesion, 'codigo' => $codigo,
    ], conBearer($bearer ?? $e['bearer']));
}

it('si Wellhub no responde la visita queda sin verificar (no aprobada) y al reintentar con el mismo código se valida', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarBienestar($e, 'wellhub', ['api_key' => 'wh_secreto_estudio', 'gym_id' => '445566']);
    $sesion = crearSesionTenant($e, agendaSemilla($e));

    Http::fake(['api.partners.gympass.com/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['metadata' => ['total' => 1, 'errors' => 0]], 200)]);

    $r = visitaBienestar($e, $sesion, 'wellhub', '1000000000001')
        ->assertStatus(409)
        ->assertJsonPath('code', 'CHECKIN_UNVERIFIABLE')
        ->assertJsonPath('message', 'No se pudo contactar a Wellhub: no respondió a tiempo o no hubo conexión.');
    $pendiente = (string) $r->json('meta.checkin');

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pendiente)
        ->assertJsonPath('data.0.estado', 'no_verificable')
        ->assertJsonPath('data.0.usuario', '1000000000001');
    // El soporte de la plataforma se entera, sin llaves en el mensaje.
    $alerta = AlertaPlataforma::query()->where('tipo', 'bienestar_no_verificable')->firstOrFail();
    expect($alerta->clave)->toBe('estudio-a:wellhub')
        ->and($alerta->mensaje)->not->toContain('wh_secreto_estudio');

    // Reintento con el mismo código (escrito con espacios): la misma visita, ya validada.
    visitaBienestar($e, $sesion, 'wellhub', '1000 0000 00001')
        ->assertCreated()
        ->assertJsonPath('data.id', $pendiente)
        ->assertJsonPath('data.estado', 'validado')
        ->assertJsonPath('data.motivo', null);
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))
        ->assertJsonCount(1, 'data');
});

it('un error de su lado o credenciales que no acepta no se toman como rechazo de la persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarBienestar($e, 'wellhub', ['api_key' => 'wh_x', 'gym_id' => '445566']);
    conectarBienestar($e, 'totalpass', ['api_key' => 'tp_llave', 'codigo_gimnasio' => 'GYM-9']);
    $sesion = crearSesionTenant($e, agendaSemilla($e));

    Http::fake([
        'api.partners.gympass.com/*' => Http::sequence()
            ->push(['errors' => [['key' => 'unauthorized']]], 401)
            ->push(['errors' => [['key' => 'checkin.validation.expired']]], 400),
        'api.totalpass.com/*' => Http::response('Service Unavailable', 503),
    ]);

    visitaBienestar($e, $sesion, 'wellhub', '1000000000002')
        ->assertStatus(409)->assertJsonPath('code', 'CHECKIN_UNVERIFIABLE')
        ->assertJsonPath('message', 'Wellhub no aceptó el token o el Gym ID de la integración: revisa las credenciales.');
    visitaBienestar($e, $sesion, 'totalpass', 'TOK-503')
        ->assertStatus(409)->assertJsonPath('code', 'CHECKIN_UNVERIFIABLE')
        ->assertJsonPath('message', 'TotalPass no está respondiendo (error de su lado). Intenta de nuevo en unos minutos.');

    expect(enNegocioBienestar(fn () => CheckinTenant::query()->where('estado', 'validado')->count()))->toBe(0);
    // Un rechazo real del proveedor no deja nada registrado.
    visitaBienestar($e, $sesion, 'wellhub', '1000000000003')->assertStatus(422)->assertJsonPath('code', 'CHECKIN_INVALID');
    expect(enNegocioBienestar(fn () => CheckinTenant::query()->count()))->toBe(2);
});

it('la excepción la autoriza quien tiene permiso, con motivo y evidencia, y queda en la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarBienestar($e, 'wellhub', ['api_key' => 'wh_secreto_estudio', 'gym_id' => '445566']);
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $admin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');

    Http::fake(['api.partners.gympass.com/*' => Http::sequence()
        ->pushFailedConnection('Connection timed out')
        ->push(['metadata' => ['total' => 1]], 200)]);
    $pendiente = (string) visitaBienestar($e, $sesion, 'wellhub', '1000000000004', $recepcion)
        ->assertStatus(409)->json('meta.checkin');
    $url = "/api/v1/app/{$e['slug']}/checkins/{$pendiente}/excepcion";
    $datos = ['motivo' => 'Wellhub no respondía', 'evidencia' => 'Vi su check-in activo en la app de la persona a las 7:58'];

    // Recepción registra entradas pero no autoriza excepciones; sin evidencia, tampoco.
    $this->postJson($url, $datos, conBearer($recepcion))->assertForbidden();
    $this->postJson($url, ['motivo' => 'Sin red'], conBearer($admin))
        ->assertUnprocessable()->assertJsonValidationErrors(['evidencia'], 'meta.errors');

    $this->postJson($url, $datos, conBearer($admin))
        ->assertOk()
        ->assertJsonPath('data.estado', 'excepcion')
        ->assertJsonPath('data.registrado_por', 'Personal')
        ->assertJsonPath('data.autorizado_por', 'Personal')
        ->assertJsonPath('data.motivo_excepcion', 'Wellhub no respondía')
        ->assertJsonPath('data.evidencia', $datos['evidencia']);

    $auditoria = enNegocioBienestar(fn () => AuditoriaTenant::query()->where('accion', 'checkin.excepcion')->firstOrFail());
    expect($auditoria->motivo)->toBe('Wellhub no respondía')
        ->and($auditoria->despues['evidencia'] ?? null)->toBe($datos['evidencia']);

    // Ya es excepción: no se autoriza dos veces; una validada tampoco admite excepción.
    $this->postJson($url, $datos, conBearer($admin))->assertUnprocessable()->assertJsonPath('code', 'CHECKIN_INVALID');
    $validada = (string) visitaBienestar($e, $sesion, 'wellhub', '1000000000005')->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/checkins/{$validada}/excepcion", $datos, conBearer($admin))
        ->assertUnprocessable()->assertJsonPath('code', 'CHECKIN_INVALID');
});

it('la visita del día cuenta una vez: en otra clase se avisa en cuál quedó', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarBienestar($e, 'wellhub', ['api_key' => 'wh_secreto_estudio', 'gym_id' => '445566']);
    $oferta = agendaSemilla($e);
    $primera = crearSesionTenant($e, $oferta);
    $segunda = crearSesionTenant($e, $oferta, null, '2026-10-01 18:00:00');

    Http::fake(['api.partners.gympass.com/*' => Http::sequence()
        ->push(['metadata' => ['total' => 1]], 200)
        ->push(['errors' => [['key' => 'checkin.already.validated']]], 400)]);

    visitaBienestar($e, $primera, 'wellhub', '1000000000006')->assertCreated();
    visitaBienestar($e, $segunda, 'wellhub', '1000000000006')
        ->assertStatus(422)
        ->assertJsonPath('code', 'CHECKIN_INVALID')
        ->assertJsonPath('message', 'Wellhub cuenta una visita por día y esta ya se registró hoy en la clase de las 08:00.');
});

it('del token de TotalPass solo queda su huella y las credenciales se auditan sin sus valores', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarBienestar($e, 'totalpass', ['api_key' => 'tp_llave_secreta', 'codigo_gimnasio' => 'GYM-9']);
    $sesion = crearSesionTenant($e, agendaSemilla($e));

    Http::fake(['api.totalpass.com/*' => Http::response(null, 204)]);
    visitaBienestar($e, $sesion, 'totalpass', 'TOKSECRETO42')->assertCreated();

    $checkin = enNegocioBienestar(fn () => CheckinTenant::query()->firstOrFail());
    expect((string) $checkin->referencia_externa)->not->toContain('TOKSECRETO42')
        ->and((string) $checkin->codigo_huella)->not->toContain('TOKSECRETO42');

    $auditoria = enNegocioBienestar(fn () => AuditoriaTenant::query()->where('accion', 'integracion.actualizada')->firstOrFail());
    expect(json_encode([$auditoria->antes, $auditoria->despues]))->not->toContain('tp_llave_secreta')
        ->and($auditoria->despues['llaves_cambiadas'] ?? null)->toBe(['api_key', 'codigo_gimnasio'])
        ->and($auditoria->despues['activa'] ?? null)->toBeTrue();

    // Guardar sin cambios no ensucia la bitácora.
    conectarBienestar($e, 'totalpass', ['api_key' => 'tp_llave_secreta']);
    expect(enNegocioBienestar(fn () => AuditoriaTenant::query()->where('accion', 'integracion.actualizada')->count()))->toBe(1);
});
