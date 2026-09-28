<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Rol activo: quien tiene varios roles en el negocio entra con uno (el de la última
| vez, o el principal) y la API solo le concede los permisos de ese rol. Cambia con
| PUT /yo/rol-activo, por sesión (cada dispositivo el suyo).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio cuya dueña también es alumna (dos roles).
 *
 * @return array{slug: string, bearer: string, ulid: string}
 */
function duenaQueTambienEsAlumna(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ulid = (string) test()->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->json('data.usuario.ulid');
    test()->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/roles", [
        'roles' => ['propietario', 'miembro'],
    ], conBearer($e['bearer']))->assertOk();

    return [...$e, 'ulid' => $ulid];
}

function entrarComoDuena(string $slug): string
{
    return (string) test()->postJson("/api/v1/app/{$slug}/login", [
        'email' => 'a@correo.mx', 'password' => 'secreto123',
    ])->assertOk()->json('data.token');
}

function cambiarRolActivo(string $slug, string $bearer, string $rol): TestResponse
{
    return test()->putJson("/api/v1/app/{$slug}/yo/rol-activo", ['rol' => $rol], conBearer($bearer));
}

it('al entrar con varios roles recibe el principal, sus permisos y los roles disponibles', function (): void {
    $e = duenaQueTambienEsAlumna();

    $login = test()->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])
        ->assertOk();

    $login->assertJsonPath('data.usuario.rol', 'propietario')
        ->assertJsonPath('data.usuario.permisos', ['*'])
        ->assertJsonPath('data.usuario.roles_disponibles', [
            ['clave' => 'propietario', 'faceta' => 'equipo', 'nombre' => null],
            ['clave' => 'miembro', 'faceta' => 'miembro', 'nombre' => null],
        ]);
});

it('como alumna la API solo concede lo de alumna; al volver a dueña, todo', function (): void {
    $e = duenaQueTambienEsAlumna();
    $bearer = entrarComoDuena($e['slug']);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($bearer))->assertOk();

    cambiarRolActivo($e['slug'], $bearer, 'miembro')->assertOk()
        ->assertJsonPath('data.usuario.rol', 'miembro')
        ->assertJsonPath('data.usuario.permisos', ['formularios.responder']);

    // El servidor lo exige: lo del panel queda fuera, su cuenta sí.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($bearer))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($bearer))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($bearer))->assertOk()->assertJsonPath('data.usuario.rol', 'miembro');

    cambiarRolActivo($e['slug'], $bearer, 'propietario')->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($bearer))->assertOk();
});

it('recuerda el rol de la última vez y cada sesión conserva el suyo', function (): void {
    $e = duenaQueTambienEsAlumna();
    $telefono = entrarComoDuena($e['slug']);
    $computadora = entrarComoDuena($e['slug']);

    cambiarRolActivo($e['slug'], $telefono, 'miembro')->assertOk();

    // La otra sesión sigue como dueña.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($computadora))->assertJsonPath('data.usuario.rol', 'propietario');
    // Una sesión nueva entra con el de la última vez.
    test()->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'secreto123'])
        ->assertJsonPath('data.usuario.rol', 'miembro');
});

it('no se puede elegir un rol que no se tiene', function (): void {
    $e = duenaQueTambienEsAlumna();
    $bearer = entrarComoDuena($e['slug']);

    cambiarRolActivo($e['slug'], $bearer, 'admin')->assertUnprocessable()
        ->assertJsonValidationErrors(['rol'], 'meta.errors');
    cambiarRolActivo($e['slug'], $bearer, 'cualquiera')->assertUnprocessable();
    $this->putJson("/api/v1/app/{$e['slug']}/yo/rol-activo", ['rol' => 'miembro'])->assertUnauthorized();
});

it('si le quitan el rol con el que trabaja, sigue con otro de los suyos', function (): void {
    $e = duenaQueTambienEsAlumna();
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'admin');
    $coachUlid = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($coach))->json('data.usuario.ulid');
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$coachUlid}/roles", ['roles' => ['admin', 'instructor']], conBearer($e['bearer']))->assertOk();
    cambiarRolActivo($e['slug'], $coach, 'admin')->assertOk();

    // La dueña le quita el rol de admin: su sesión sigue, ahora como instructor.
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$coachUlid}/roles", ['roles' => ['instructor']], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($coach))
        ->assertOk()
        ->assertJsonPath('data.usuario.rol', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($coach))->assertForbidden();
});

it('solo quien actúa como dueño concede el rol de dueño', function (): void {
    $e = duenaQueTambienEsAlumna();
    // Dueña y además admin: entra como admin.
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$e['ulid']}/roles", ['roles' => ['propietario', 'admin']], conBearer($e['bearer']))->assertOk();
    $bearer = entrarComoDuena($e['slug']);
    cambiarRolActivo($e['slug'], $bearer, 'admin')->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'socia@correo.mx', 'recepcionista');
    $socia = collect($this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($bearer))->assertOk()->json('data'))
        ->firstWhere('email', 'socia@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$socia['id']}/roles", ['roles' => ['propietario']], conBearer($bearer))
        ->assertUnprocessable();

    cambiarRolActivo($e['slug'], $bearer, 'propietario')->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$socia['id']}/roles", ['roles' => ['propietario']], conBearer($bearer))
        ->assertOk();
});
