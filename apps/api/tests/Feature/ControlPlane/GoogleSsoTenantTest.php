<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config()->set('services.google.client_id', 'client-123.apps.googleusercontent.com');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Simula la respuesta del endpoint `tokeninfo` de Google para un correo dado.
 */
function googleTokeninfo(
    string $email,
    string $aud = 'client-123.apps.googleusercontent.com',
    bool $verificado = true,
    int $estado = 200,
): void {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'iss' => 'https://accounts.google.com',
            'aud' => $aud,
            'sub' => 'google-sub-'.md5($email),
            'email' => $email,
            'email_verified' => $verificado ? 'true' : 'false',
            'name' => 'Usuario Google',
        ], $estado),
    ]);
}

/**
 * Conecta Google (con ese correo) a la cuenta del bearer, desde su perfil.
 */
function conectarGoogle(string $slug, string $bearer, string $correoGoogle): void
{
    googleTokeninfo($correoGoogle);
    test()->putJson("/api/v1/app/{$slug}/yo/google", ['credential' => 't'], conBearer($bearer))
        ->assertOk()->assertJsonPath('data.usuario.google_conectado', true);
}

it('Google solo entra a una cuenta que lo conectó desde su perfil (ADR 0093)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    googleTokeninfo('a@correo.mx');

    // Sin conectarlo, aunque el correo coincida, no entra: se le pide conectarlo.
    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 'id-token-falso'])
        ->assertStatus(422)->assertJsonPath('code', 'GOOGLE_AUTH_FAILED')
        ->assertJsonPath('message', 'Primero entra con tu contraseña y conecta Google desde tu perfil.');

    conectarGoogle($e['slug'], $e['bearer'], 'a@correo.mx');
    $r = $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 'id-token-falso'])
        ->assertOk()
        ->assertJsonPath('data.usuario.email', 'a@correo.mx')
        ->assertJsonPath('data.usuario.rol', 'propietario');

    // El bearer emitido autentica en el estudio.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer((string) $r->json('data.token')))
        ->assertOk()->assertJsonPath('data.usuario.email', 'a@correo.mx');
});

it('se puede conectar un Gmail distinto al correo de la cuenta y quitarlo después', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarGoogle($e['slug'], $e['bearer'], 'personal@gmail.com');

    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])
        ->assertOk()->assertJsonPath('data.usuario.email', 'a@correo.mx');

    $this->deleteJson("/api/v1/app/{$e['slug']}/yo/google", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.google_conectado', false);
    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])->assertStatus(422);
});

it('una cuenta de Google conecta a un solo usuario del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    conectarGoogle($e['slug'], $e['bearer'], 'compartido@gmail.com');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    googleTokeninfo('compartido@gmail.com');
    $this->putJson("/api/v1/app/{$e['slug']}/yo/google", ['credential' => 't'], conBearer($coach))
        ->assertStatus(422)->assertJsonValidationErrors(['credential'], 'meta.errors');
});

it('Google no activa invitaciones ni crea cuentas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Invitado sin activar: entra con el enlace de su invitación, no con Google.
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Coach', 'email' => 'coach@correo.mx', 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();
    googleTokeninfo('coach@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])
        ->assertStatus(422)->assertJsonPath('code', 'GOOGLE_AUTH_FAILED');

    googleTokeninfo('desconocido@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])
        ->assertStatus(422)->assertJsonPath('code', 'GOOGLE_AUTH_FAILED');
});

it('rechaza un ID token cuyo aud no es esta app', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    googleTokeninfo('a@correo.mx', aud: 'otra-app.apps.googleusercontent.com');

    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])
        ->assertStatus(422)->assertJsonPath('code', 'GOOGLE_AUTH_FAILED');
});

it('acepta los ID tokens de la app móvil (su cliente de Android o iOS)', function (): void {
    config()->set('services.google.client_ids_app', 'android-1.apps.googleusercontent.com, ios-1.apps.googleusercontent.com');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    googleTokeninfo('a@correo.mx', aud: 'ios-1.apps.googleusercontent.com');

    $this->putJson("/api/v1/app/{$e['slug']}/yo/google", ['credential' => 't'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.google_conectado', true);
    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])->assertOk();
});

it('rechaza Google si el correo no esta verificado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    googleTokeninfo('a@correo.mx', verificado: false);

    $this->postJson("/api/v1/app/{$e['slug']}/auth/google", ['credential' => 't'])
        ->assertStatus(422)->assertJsonPath('code', 'GOOGLE_AUTH_FAILED');
});

it('las cuentas de Google son por estudio: conectada en uno no entra en otro', function (): void {
    $a = estudioConSesion('estudio-a', 'comun@correo.mx');
    $b = estudioConSesion('estudio-b', 'otro@correo.mx');
    conectarGoogle($a['slug'], $a['bearer'], 'comun@correo.mx');

    // En A la conectó el dueño: entra.
    $this->postJson("/api/v1/app/{$a['slug']}/auth/google", ['credential' => 't'])->assertOk();
    // En B nadie la conectó: rechazado.
    $this->postJson("/api/v1/app/{$b['slug']}/auth/google", ['credential' => 't'])->assertStatus(422);
});
