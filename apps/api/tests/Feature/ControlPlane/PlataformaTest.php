<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array<string, string>
 */
function conTokenPlataforma(string $token = 'token-plataforma'): array
{
    return ['Accept' => 'application/json', 'Authorization' => "Bearer {$token}"];
}

it('sin token de plataforma configurado el apartado esta deshabilitado (401)', function (): void {
    // No se configura turnouno.plataforma.token.
    test()->getJson('/api/v1/plataforma/estudios', conTokenPlataforma())->assertUnauthorized();
});

it('rechaza un token de plataforma incorrecto (401)', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    test()->getJson('/api/v1/plataforma/estudios', conTokenPlataforma('otro'))->assertUnauthorized();
});

it('con el token correcto lista todos los estudios (control plane)', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');

    test()->getJson('/api/v1/plataforma/estudios', conTokenPlataforma())
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonCount(2, 'data');
});

it('un estudio nuevo entra en cobro por activos; el admin lo cambia a fijo y el cargo lo refleja', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    terminarPrueba($e);

    // Por defecto: cobro por alumnos activos.
    test()->getJson('/api/v1/plataforma/estudios', conTokenPlataforma())
        ->assertOk()->assertJsonPath('data.0.modo_cobro', 'activos');

    // El admin de plataforma lo cambia a cuota fija mensual.
    test()->putJson('/api/v1/plataforma/estudios/estudio-a', [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900,
    ], conTokenPlataforma())->assertOk()->assertJsonPath('data.modo_cobro', 'fijo');

    // El cargo que ve el dueño ahora es la cuota fija (sin importar los alumnos activos).
    test()->getJson("/api/v1/app/{$e['slug']}/facturacion", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.modo_cobro', 'fijo')
        ->assertJsonPath('data.cuota_fija_minor', 149900)
        ->assertJsonPath('data.uso.cargo_estimado_minor', 149900);
});

it('el cambio de facturación de un estudio exige token de plataforma', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    estudioConSesion('estudio-a', 'a@correo.mx');

    test()->putJson('/api/v1/plataforma/estudios/estudio-a', [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 1000,
    ], conTokenPlataforma('otro'))->assertUnauthorized();
});

it('activa y configura una pasarela de la plataforma sin devolver las llaves', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    // Por defecto: apagadas, sin llaves.
    test()->getJson('/api/v1/plataforma/pasarelas', conTokenPlataforma())
        ->assertOk()
        ->assertJsonPath('data.0.proveedor', 'stripe')
        ->assertJsonPath('data.0.activa', false);

    // Activa Stripe con llaves de prueba.
    $r = test()->putJson('/api/v1/plataforma/pasarelas/stripe', [
        'activa' => true, 'modo' => 'test',
        'credenciales' => ['secret_key' => 'sk_test_plataforma', 'webhook_secret' => 'whsec_plat'],
    ], conTokenPlataforma())->assertOk();

    $r->assertJsonPath('data.activa', true)->assertJsonPath('data.modo', 'test');
    expect($r->json('data.llaves_configuradas'))->toContain('secret_key');
    // Nunca expone el secreto.
    expect(json_encode($r->json()))->not->toContain('sk_test_plataforma');

    // Persiste + sigue sin exponer secretos.
    $lista = test()->getJson('/api/v1/plataforma/pasarelas', conTokenPlataforma())->assertOk();
    $stripe = collect($lista->json('data'))->firstWhere('proveedor', 'stripe');
    expect($stripe['activa'])->toBeTrue();
    expect($stripe['llaves_configuradas'])->toContain('webhook_secret');
    expect(json_encode($lista->json()))->not->toContain('sk_test_plataforma');
});

it('rechaza configurar un proveedor desconocido y exige token de plataforma', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    test()->putJson('/api/v1/plataforma/pasarelas/desconocido', [
        'activa' => true, 'modo' => 'test',
    ], conTokenPlataforma())->assertNotFound();

    test()->putJson('/api/v1/plataforma/pasarelas/stripe', [
        'activa' => true, 'modo' => 'test',
    ], conTokenPlataforma('otro'))->assertUnauthorized();
});

it('carga la llave de la cuenta FacturAPI sin devolverla nunca', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    test()->getJson('/api/v1/plataforma/configuracion', conTokenPlataforma())
        ->assertOk()->assertJsonPath('data.facturapi_configurada', false);

    $r = test()->putJson('/api/v1/plataforma/configuracion', [
        'facturapi_llave' => 'sk_test_llave_de_plataforma',
    ], conTokenPlataforma())->assertOk();

    $r->assertJsonPath('data.facturapi_configurada', true);
    // Nunca se expone la llave en claro.
    expect(json_encode($r->json()))->not->toContain('sk_test_llave_de_plataforma');

    test()->getJson('/api/v1/plataforma/configuracion', conTokenPlataforma())
        ->assertOk()->assertJsonPath('data.facturapi_configurada', true);
});

it('el superadmin guarda aviso y términos y se ven en el endpoint público', function (): void {
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    test()->putJson('/api/v1/plataforma/legales', [
        'aviso_privacidad' => 'Nuestro aviso de privacidad.',
        'terminos' => 'Términos y condiciones del servicio.',
    ], conTokenPlataforma())
        ->assertOk()
        ->assertJsonPath('data.aviso_privacidad', 'Nuestro aviso de privacidad.')
        ->assertJsonPath('data.terminos', 'Términos y condiciones del servicio.');

    // El endpoint público (sin token) los entrega para el registro.
    test()->getJson('/api/v1/legales')
        ->assertOk()
        ->assertJsonPath('data.aviso_privacidad', 'Nuestro aviso de privacidad.')
        ->assertJsonPath('data.terminos', 'Términos y condiciones del servicio.');
});

it('el endpoint público de legales responde vacío si no se han configurado', function (): void {
    test()->getJson('/api/v1/legales')
        ->assertOk()
        ->assertJsonPath('data.aviso_privacidad', null)
        ->assertJsonPath('data.terminos', null);
});
