<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Invitar a un cliente a su cuenta (Miembros): crea su cuenta sin activar y le manda
| el correo. Mientras no la active, la invitación queda pendiente y se REENVÍA (no se
| vuelve a invitar); al activarla, ya tiene acceso.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function miembroEnListado(array $e, string $persona): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $persona);
}

it('una invitación sin activar queda pendiente y se reenvía; activada, da acceso', function (): void {
    $e = estudioConSesion('barberia-invita', 'dueno@barberia-invita.mx');
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Diego', 'email' => 'Diego@Correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    expect(miembroEnListado($e, $persona))->toMatchArray(['acceso_app' => false, 'invitacion_pendiente' => null]);

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Diego', 'email' => 'diego@correo.mx', 'rol' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated();

    // Pendiente (aunque el correo difiera en mayúsculas): se reenvía a esa cuenta.
    $pendiente = miembroEnListado($e, $persona);
    expect($pendiente['acceso_app'])->toBeFalse();
    expect($pendiente['invitacion_pendiente'])->toBeString();
    $reenvio = $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$pendiente['invitacion_pendiente']}/reenviar", [], conBearer($e['bearer']))
        ->assertOk()->json('data.activacion');

    $this->postJson("/api/v1/app/{$e['slug']}/activar", [
        'email' => $reenvio['email'], 'token' => $reenvio['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();

    expect(miembroEnListado($e, $persona))->toMatchArray(['acceso_app' => true, 'invitacion_pendiente' => null]);
});
