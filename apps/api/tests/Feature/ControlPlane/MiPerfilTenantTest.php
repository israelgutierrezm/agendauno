<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| "Mi perfil": cada usuario ajusta su nombre (con apellidos por separado), su foto y
| su contraseña. Cambiar la contraseña cierra las demás sesiones, no la actual.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('actualiza nombre y apellidos; el nombre corto es primer nombre + apellido paterno', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", [
        'nombre' => 'María José', 'primer_apellido' => 'López', 'segundo_apellido' => 'Pérez',
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.nombre', 'María José López Pérez')
        ->assertJsonPath('data.usuario.nombre_corto', 'María López');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.primer_apellido', 'López');

    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", ['nombre' => ''], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('sin apellidos capturados, el nombre corto se deduce del nombre completo', function (): void {
    expect((new Usuario(['name' => 'María José López Pérez']))->nombreCorto())->toBe('María López')
        ->and((new Usuario(['name' => 'Beto Ramírez Soto']))->nombreCorto())->toBe('Beto Ramírez')
        ->and((new Usuario(['name' => 'Beto Instructor']))->nombreCorto())->toBe('Beto Instructor');
});

it('cambia la contraseña: pide la actual y cierra las otras sesiones, no la de este equipo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $otraSesion = (string) $this->postJson("/api/v1/app/{$e['slug']}/login", [
        'email' => 'a@correo.mx', 'password' => 'secreto123',
    ])->assertOk()->json('data.token');

    $this->putJson("/api/v1/app/{$e['slug']}/yo/contrasena", [
        'actual' => 'equivocada', 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1',
    ], conBearer($e['bearer']))->assertStatus(422);

    $this->putJson("/api/v1/app/{$e['slug']}/yo/contrasena", [
        'actual' => 'secreto123', 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1',
    ], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($otraSesion))->assertUnauthorized();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'a@correo.mx', 'password' => 'nueva-clave-1'])->assertOk();
});

it('sube y quita la foto de perfil en una carpeta propia del estudio', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $estudioId = Estudio::query()->where('slug', $e['slug'])->value('id');

    $url = $this->post("/api/v1/app/{$e['slug']}/yo/foto", [
        'foto' => UploadedFile::fake()->image('yo.png', 200, 200),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertOk()->json('data.usuario.foto_url');

    expect($url)->toBeString();
    $archivos = Storage::disk('public')->files("usuarios/{$estudioId}");
    expect($archivos)->toHaveCount(1);

    // Solo imágenes (no PDF).
    $this->post("/api/v1/app/{$e['slug']}/yo/foto", [
        'foto' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertStatus(422);

    $this->deleteJson("/api/v1/app/{$e['slug']}/yo/foto", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.foto_url', null);
    expect(Storage::disk('public')->files("usuarios/{$estudioId}"))->toHaveCount(0);
});
