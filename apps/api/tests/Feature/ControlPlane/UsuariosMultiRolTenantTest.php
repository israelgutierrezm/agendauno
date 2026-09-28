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

/**
 * ULID de un usuario tenant-local por su email (via el apartado Usuarios).
 */
function ulidUsuarioPorEmail(string $slug, string $bearer, string $email): string
{
    $usuarios = test()->getJson("/api/v1/app/{$slug}/usuarios", conBearer($bearer))->assertOk()->json('data');
    foreach ($usuarios as $usuario) {
        if ($usuario['email'] === $email) {
            return (string) $usuario['id'];
        }
    }

    throw new RuntimeException("Usuario no encontrado: {$email}");
}

it('el apartado Usuarios lista todas las cuentas con sus roles', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    $resp = test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->assertOk();

    $resp->assertJsonCount(2, 'data');
    expect($resp->json('roles'))->toContain('propietario');

    $duena = collect($resp->json('data'))->firstWhere('email', 'duena@correo.mx');
    expect($duena['roles'])->toEqual(['propietario']);
    expect($duena['rol'])->toEqual('propietario');
});

it('asignar varios roles a una cuenta le deja entrar con cualquiera de ellos', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    $bearerProfe = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'miembro');
    $ulid = ulidUsuarioPorEmail($e['slug'], $e['bearer'], 'profe@correo.mx');

    $r = test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/roles", [
        'roles' => ['miembro', 'instructor'],
    ], conBearer($e['bearer']))->assertOk();

    expect($r->json('data.roles'))->toEqualCanonicalizing(['miembro', 'instructor']);
    expect($r->json('data.rol'))->toEqual('instructor'); // rol principal (mas privilegiado)

    // Su sesión sigue con el rol con el que entró (ADR 0055): los permisos no se
    // suman; ahora puede elegir también el nuevo.
    $yo = test()->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($bearerProfe))->assertOk();
    expect($yo->json('data.usuario.rol'))->toBe('miembro')
        ->and($yo->json('data.usuario.permisos'))->toBe(['formularios.responder'])
        ->and(collect($yo->json('data.usuario.roles_disponibles'))->pluck('clave')->all())->toBe(['instructor', 'miembro']);

    $permisos = test()->putJson("/api/v1/app/{$e['slug']}/yo/rol-activo", ['rol' => 'instructor'], conBearer($bearerProfe))
        ->assertOk()->json('data.usuario.permisos');
    expect($permisos)->toContain('reservas.ver')->toContain('asistencia.marcar');
});

it('la duena no puede quitarse a si misma el rol de dueno', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    $ulid = ulidUsuarioPorEmail($e['slug'], $e['bearer'], 'duena@correo.mx');

    test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/roles", [
        'roles' => ['admin'],
    ], conBearer($e['bearer']))->assertStatus(422);

    // Sigue siendo dueña (puede seguir gestionando usuarios).
    $duena = collect(
        test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->json('data')
    )->firstWhere('email', 'duena@correo.mx');
    expect($duena['roles'])->toEqual(['propietario']);
});

it('un admin no puede conceder el rol de dueno pero si otros roles', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    $bearerAdmin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');
    personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'miembro');
    $ulidProfe = ulidUsuarioPorEmail($e['slug'], $e['bearer'], 'profe@correo.mx');

    // El admin NO puede conceder propietario.
    test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulidProfe}/roles", [
        'roles' => ['propietario', 'instructor'],
    ], conBearer($bearerAdmin))->assertStatus(422);

    // Pero SI puede combinar roles no-dueño.
    test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulidProfe}/roles", [
        'roles' => ['miembro', 'instructor'],
    ], conBearer($bearerAdmin))->assertOk();
});

it('el selector de instructores incluye a quien tambien es instructor aunque su rol principal sea admin', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    personalConSesion($e['slug'], $e['bearer'], 'coach.admin@correo.mx', 'admin');
    $ulid = ulidUsuarioPorEmail($e['slug'], $e['bearer'], 'coach.admin@correo.mx');

    test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/roles", [
        'roles' => ['admin', 'instructor'],
    ], conBearer($e['bearer']))->assertOk();

    // La dueña es solo propietario; el coach admin+instructor debe aparecer.
    test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('un instructor no puede entrar al apartado Usuarios', function (): void {
    $e = estudioConSesion('estudio-x', 'duena@correo.mx');
    $bearerProfe = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($bearerProfe))->assertForbidden();
});
