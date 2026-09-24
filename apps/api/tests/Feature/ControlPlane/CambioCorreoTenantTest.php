<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoConfirmarCorreo;
use App\Modules\Tenancy\Mail\CorreoCorreoCambiado;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Cambio del correo de acceso: se pide con la contraseña, se aplica al abrir el enlace
| que llega al correo nuevo (una vez, 24 h) y se avisa al anterior.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Token del enlace que llegó al correo nuevo.
 */
function tokenDeConfirmacion(string $para): string
{
    $token = '';
    Mail::assertQueued(CorreoConfirmarCorreo::class, function (CorreoConfirmarCorreo $mail) use ($para, &$token): bool {
        $token = $mail->token;

        return $mail->hasTo($para);
    });

    return $token;
}

it('cambia el correo al confirmar el enlace y avisa al anterior', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", [
        'email' => 'nuevo@correo.mx', 'password' => 'secreto123',
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.email', 'a@correo.mx')
        ->assertJsonPath('data.usuario.email_pendiente', 'nuevo@correo.mx');

    // Hasta confirmar, se sigue entrando con el anterior.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])->assertOk();

    $token = tokenDeConfirmacion('nuevo@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/confirmar-correo", ['token' => $token])
        ->assertOk()->assertJsonPath('data.email', 'nuevo@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'nuevo@correo.mx', 'password' => 'secreto123'])->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])->assertStatus(422);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.email_pendiente', null);
    Mail::assertQueued(CorreoCorreoCambiado::class, fn (CorreoCorreoCambiado $mail): bool => $mail->hasTo('a@correo.mx'));

    // El enlace sirve una sola vez.
    $this->postJson("/api/v1/app/{$e['slug']}/confirmar-correo", ['token' => $token])
        ->assertStatus(422)->assertJsonPath('code', 'EMAIL_CHANGE_INVALID');
});

it('pide la contraseña y un correo libre y distinto', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'nuevo@correo.mx', 'password' => 'mala-clave'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors('password', 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'vale@correo.mx', 'password' => 'secreto123'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors('email', 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'a@correo.mx', 'password' => 'secreto123'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors('email', 'meta.errors');

    Mail::assertNotQueued(CorreoConfirmarCorreo::class);
});

it('el enlace vence en 24 h y se puede cancelar el cambio', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'nuevo@correo.mx', 'password' => 'secreto123'], conBearer($e['bearer']))->assertOk();
    $token = tokenDeConfirmacion('nuevo@correo.mx');

    $this->travel(25)->hours();
    $this->postJson("/api/v1/app/{$e['slug']}/confirmar-correo", ['token' => $token])
        ->assertStatus(422)->assertJsonPath('code', 'EMAIL_CHANGE_INVALID');

    // Nuevo intento y se cancela: el enlace deja de servir.
    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'otro@correo.mx', 'password' => 'secreto123'], conBearer($e['bearer']))->assertOk();
    $token = tokenDeConfirmacion('otro@correo.mx');
    $this->deleteJson("/api/v1/app/{$e['slug']}/yo/correo", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.email_pendiente', null);
    $this->postJson("/api/v1/app/{$e['slug']}/confirmar-correo", ['token' => $token])->assertStatus(422);
});

it('al alumno también le cambia el correo de su ficha (recibos y recordatorios)', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumno = alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/yo/correo", ['email' => 'vale.nueva@correo.mx', 'password' => 'secreto123'], conBearer($alumno['bearer']))
        ->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/confirmar-correo", ['token' => tokenDeConfirmacion('vale.nueva@correo.mx')])->assertOk();

    $miembros = $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($miembros)->pluck('email')->all())->toBe(['vale.nueva@correo.mx']);
});
