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

it('crea una llave, la usa para leer y respeta el alcance (scope)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');

    $creada = test()->postJson("/api/v1/app/{$e['slug']}/llaves-api", [
        'nombre' => 'Integracion X', 'scopes' => ['miembros.ver'],
    ], conBearer($e['bearer']))->assertCreated();

    $secreto = (string) $creada->json('data.secreto');
    expect($secreto)->toStartWith('tu_');
    $creada->assertJsonPath('data.scopes', ['miembros.ver'])->assertJsonPath('data.activa', true);

    // Con el scope miembros.ver puede leer /integracion/miembros.
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => $secreto])
        ->assertOk()->assertJsonCount(1, 'data');

    // Sin scope agenda.ver: 403 en /integracion/sesiones.
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/sesiones", ['X-API-Key' => $secreto])
        ->assertForbidden();
});

it('no expone el secreto al listar y una llave revocada deja de autenticar (401)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $creada = test()->postJson("/api/v1/app/{$e['slug']}/llaves-api", [
        'nombre' => 'K', 'scopes' => ['miembros.ver'],
    ], conBearer($e['bearer']))->assertCreated();
    $secreto = (string) $creada->json('data.secreto');
    $id = (string) $creada->json('data.id');

    $lista = test()->getJson("/api/v1/app/{$e['slug']}/llaves-api", conBearer($e['bearer']))->assertOk();
    expect($lista->json('data.0'))->not->toHaveKey('secreto');
    expect($lista->json('data.0'))->not->toHaveKey('hash');

    // Válida: autentica.
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => $secreto])->assertOk();

    // Revocar -> 401.
    test()->deleteJson("/api/v1/app/{$e['slug']}/llaves-api/{$id}", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activa', false);
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => $secreto])->assertUnauthorized();

    // Llave inexistente -> 401.
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", ['X-API-Key' => 'tu_noexiste'])->assertUnauthorized();
});

it('valida los scopes contra el catalogo y exige nombre + al menos un scope', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    test()->postJson("/api/v1/app/{$e['slug']}/llaves-api", ['nombre' => 'K', 'scopes' => ['borrar.todo']], conBearer($e['bearer']))
        ->assertStatus(422);
    test()->postJson("/api/v1/app/{$e['slug']}/llaves-api", ['nombre' => 'K', 'scopes' => []], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('una llave de un estudio no autentica en otro (aislada por BD)', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $secreto = (string) test()->postJson("/api/v1/app/{$a['slug']}/llaves-api", [
        'nombre' => 'K', 'scopes' => ['miembros.ver'],
    ], conBearer($a['bearer']))->assertCreated()->json('data.secreto');

    // La llave de A no existe en la BD de B -> 401.
    test()->getJson("/api/v1/app/{$b['slug']}/integracion/miembros", ['X-API-Key' => $secreto])
        ->assertUnauthorized();
});

it('gestionar llaves exige permiso de integraciones', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->getJson("/api/v1/app/{$e['slug']}/llaves-api", conBearer($coach))->assertForbidden();
});

it('cada alcance que se ofrece lee algo: reservas, planes y órdenes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $clase = crearSesionTenant($e, $semilla, 5);
    $vp = venderPackAMiembroTenant($e, 3000);
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $secreto = (string) test()->postJson("/api/v1/app/{$e['slug']}/llaves-api", [
        'nombre' => 'Integracion Y', 'scopes' => ['reservas.ver', 'derechos.ver', 'ordenes.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.secreto');
    $llave = ['X-API-Key' => $secreto];

    test()->getJson("/api/v1/app/{$e['slug']}/integracion/reservas", $llave)->assertOk()
        ->assertJsonPath('data.0.sesion_id', $clase)->assertJsonPath('data.0.persona_id', $vp['persona']);
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/derechos", $llave)->assertOk()
        ->assertJsonPath('data.0.persona_id', $vp['persona'])->assertJsonPath('data.0.disponible', 2000);
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/ordenes", $llave)->assertOk();
    // Sin miembros.ver no lee personas.
    test()->getJson("/api/v1/app/{$e['slug']}/integracion/miembros", $llave)->assertForbidden();
});
