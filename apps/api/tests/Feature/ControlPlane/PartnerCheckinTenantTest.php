<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Wellhub y TotalPass (solo negocios de clases en México): el negocio guarda sus
| credenciales (cifradas) y recepción valida la visita con la API de cada proveedor,
| como la documentan: Wellhub con su Access Control API (token, Gym ID y el Wellhub ID
| de 13 dígitos) y TotalPass con el uso del token del día. No consume créditos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Activa Wellhub con su token y su Gym ID en el estudio.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function activarWellhub(array $e): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true,
        'credenciales' => ['api_key' => 'wh_secreto_estudio', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function sesionDemo(array $e): string
{
    return crearSesionTenant($e, agendaSemilla($e));
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function registrarVisita(array $e, string $sesion, string $proveedor, string $codigo): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/checkins", [
        'proveedor' => $proveedor, 'sesion_id' => $sesion, 'codigo' => $codigo,
    ], conBearer($e['bearer']));
}

it('el propietario configura Wellhub con llaves cifradas que nunca se devuelven', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    test()->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.proveedor', 'wellhub')
        ->assertJsonPath('data.0.activa', false)
        ->assertJsonPath('data.0.llaves', ['api_key', 'gym_id'])
        ->assertJsonPath('data.1.llaves', ['api_key', 'codigo_gimnasio', 'codigo_plan']);

    $r = test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_secreto_estudio', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.activa', true)
        ->assertJsonPath('data.llaves_configuradas', ['api_key', 'gym_id']);

    expect($r->json())->not->toContain('wh_secreto_estudio');

    // Cifrada en la BD del tenant.
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $crudo = app(GestorDeConexionTenant::class)->ejecutarEn(
        $estudio,
        fn (): string => (string) DB::connection('tenant')->table('integraciones')->where('proveedor', 'wellhub')->value('credenciales'),
    );
    expect($crudo)->not->toContain('wh_secreto_estudio');
});

it('solo las credenciales de cada proveedor, solo en clases y solo en México', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Una dirección propia (u otra llave) no se acepta: las direcciones son las del proveedor.
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_x', 'base_url' => 'https://127.0.0.1/api'],
    ], conBearer($e['bearer']))->assertUnprocessable();

    // En un negocio de citas no hay plataformas de bienestar (ADR 0104).
    $b = estudioConSesion('barberia-b', 'b@correo.mx', 'barberia');
    $this->getJson("/api/v1/app/{$b['slug']}/integraciones", conBearer($b['bearer']))
        ->assertJsonPath('code', 'MODALITY_NOT_AVAILABLE');

    // Fuera de México no operan: ni se configuran ni validan visitas.
    activarWellhub($e);
    Estudio::query()->where('slug', $e['slug'])->update(['pais' => 'CO']);
    $this->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'INTEGRATION_ONLY_MEXICO');
    registrarVisita($e, sesionDemo($e), 'wellhub', '1000000000001')
        ->assertStatus(409)->assertJsonPath('code', 'INTEGRATION_UNAVAILABLE');
});

it('un no-propietario no puede configurar integraciones (RBAC)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $admin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');

    test()->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($admin))->assertStatus(403);
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", ['activa' => true], conBearer($admin))->assertStatus(403);
});

it('valida la visita de Wellhub con su Access Control API (sin consumir créditos)', function (): void {
    Http::fake([
        'api.partners.gympass.com/access/v1/validate' => Http::response([
            'metadata' => ['total' => 1, 'errors' => 0],
            'results' => ['user' => ['gympass_id' => '1000000000001'], 'validated_at' => '2026-10-01T12:00:00Z'],
        ], 200),
    ]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarWellhub($e);
    $sesion = sesionDemo($e);

    registrarVisita($e, $sesion, 'wellhub', '1000000000001')
        ->assertCreated()
        ->assertJsonPath('data.proveedor', 'wellhub')
        ->assertJsonPath('data.usuario', '1000000000001')
        ->assertJsonPath('data.estado', 'validado');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer wh_secreto_estudio')
        && $r->hasHeader('X-Gym-Id', '445566')
        && $r['gympass_id'] === '1000000000001');

    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('dice por qué Wellhub no validó la visita', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarWellhub($e);
    $sesion = sesionDemo($e);

    // El Wellhub ID tiene 13 dígitos: ni se pregunta.
    registrarVisita($e, $sesion, 'wellhub', 'MALO')
        ->assertStatus(422)->assertJsonPath('code', 'CHECKIN_INVALID');

    Http::fake(['api.partners.gympass.com/*' => Http::response([
        'metadata' => ['total' => 0, 'errors' => 1],
        'errors' => [['message' => 'Expired', 'key' => 'checkin.validation.expired']],
    ], 400)]);
    registrarVisita($e, $sesion, 'wellhub', '1000000000002')
        ->assertStatus(422)->assertJsonPath('code', 'CHECKIN_INVALID')
        ->assertJsonPath('message', 'El check-in venció: en Wellhub dura 20 minutos. Pide que lo haga de nuevo.');
});

it('la visita de Wellhub es una por día: ya validada, es la misma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarWellhub($e);
    $sesion = sesionDemo($e);

    Http::fake(['api.partners.gympass.com/*' => Http::sequence()
        ->push(['metadata' => ['total' => 1, 'errors' => 0]], 200)
        ->push(['errors' => [['message' => 'Already validated', 'key' => 'checkin.already.validated']]], 400)]);

    $uno = (string) registrarVisita($e, $sesion, 'wellhub', '1000000000003')->assertCreated()->json('data.id');
    $dos = (string) registrarVisita($e, $sesion, 'wellhub', '1000000000003')->assertCreated()->json('data.id');

    expect($dos)->toBe($uno);
    test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('valida el token de TotalPass con su API de uso', function (): void {
    Http::fake([
        'api.totalpass.com/service/v1/track_usages' => Http::sequence()
            ->push(null, 204)
            ->push(['errors' => [['label' => 'already_used_other_gym']]], 422),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true, 'credenciales' => ['api_key' => 'tp_llave', 'codigo_gimnasio' => 'GYM-9'],
    ], conBearer($e['bearer']))->assertOk();
    $sesion = sesionDemo($e);

    registrarVisita($e, $sesion, 'totalpass', 'TOK123')->assertCreated()->assertJsonPath('data.proveedor', 'totalpass');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('x-api-key', 'tp_llave')
        && $r['data']['attributes'] === ['type' => 'token', 'identifier' => 'TOK123', 'service_provider_code' => 'GYM-9']);

    registrarVisita($e, $sesion, 'totalpass', 'TOK999')
        ->assertStatus(422)->assertJsonPath('message', 'Ese token ya se usó en otro gimnasio.');
});

it('rechaza check-in si la integracion no esta activa (INTEGRATION_UNAVAILABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sesion = sesionDemo($e);

    registrarVisita($e, $sesion, 'totalpass', 'X')
        ->assertStatus(409)->assertJsonPath('code', 'INTEGRATION_UNAVAILABLE');
});
