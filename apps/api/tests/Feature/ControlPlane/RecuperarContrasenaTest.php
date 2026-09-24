<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Mail\CorreoRestablecimiento;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Recuperación de contraseña tenant-local: el enlace llega por correo (hash guardado,
| vence en una hora, un solo uso), no revela si la cuenta existe y al usarse cierra
| las demás sesiones de la cuenta.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Pide el enlace y devuelve el token que viajó en el correo.
 */
function tokenDeRestablecimiento(string $slug, string $email): string
{
    test()->postJson("/api/v1/app/{$slug}/recuperar-contrasena", ['email' => $email])
        ->assertOk()->assertJsonPath('data.ok', true);

    $token = '';
    Mail::assertQueued(CorreoRestablecimiento::class, function (CorreoRestablecimiento $correo) use ($email, &$token): bool {
        $token = $correo->token;

        return $correo->email === $email;
    });

    return $token;
}

it('el enlace del correo fija la contraseña nueva, deja la sesión iniciada y cierra las demás', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $token = tokenDeRestablecimiento($e['slug'], 'a@correo.mx');

    $nuevo = $this->postJson("/api/v1/app/{$e['slug']}/restablecer-contrasena", [
        'email' => 'a@correo.mx', 'token' => $token,
        'password' => 'nueva-clave-9', 'password_confirmation' => 'nueva-clave-9',
    ])->assertOk()->json('data.token');

    // La sesión anterior se cerró; la que devolvió el restablecimiento sirve.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertUnauthorized();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer((string) $nuevo))->assertOk();

    // Entra con la nueva, ya no con la anterior.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'nueva-clave-9'])->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])->assertStatus(422);

    // El enlace sirve una sola vez.
    $this->postJson("/api/v1/app/{$e['slug']}/restablecer-contrasena", [
        'email' => 'a@correo.mx', 'token' => $token,
        'password' => 'otra-clave-99', 'password_confirmation' => 'otra-clave-99',
    ])->assertStatus(422)->assertJsonPath('code', 'PASSWORD_RESET_INVALID');
});

it('no revela si el correo está registrado', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/recuperar-contrasena", ['email' => 'nadie@correo.mx'])
        ->assertOk()->assertJsonPath('data.ok', true);

    Mail::assertNotQueued(CorreoRestablecimiento::class);
});

it('el enlace vence en una hora', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $token = tokenDeRestablecimiento($e['slug'], 'a@correo.mx');

    $this->travel(61)->minutes();

    $this->postJson("/api/v1/app/{$e['slug']}/restablecer-contrasena", [
        'email' => 'a@correo.mx', 'token' => $token,
        'password' => 'nueva-clave-9', 'password_confirmation' => 'nueva-clave-9',
    ])->assertStatus(422)->assertJsonPath('code', 'PASSWORD_RESET_INVALID');
});

it('a una cuenta que aún no se activa le reenvía su activación', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx', 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/recuperar-contrasena", ['email' => 'beto@correo.mx'])->assertOk();

    Mail::assertNotQueued(CorreoRestablecimiento::class);
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $c): bool => $c->email === 'beto@correo.mx');
});

it('pedir el enlace tiene su propio límite y no gasta los intentos de inicio de sesión', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    foreach (range(1, 3) as $_) {
        $this->postJson("/api/v1/app/{$e['slug']}/recuperar-contrasena", ['email' => 'a@correo.mx'])->assertOk();
    }
    $this->postJson("/api/v1/app/{$e['slug']}/recuperar-contrasena", ['email' => 'a@correo.mx'])->assertStatus(429);

    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])->assertOk();
});
