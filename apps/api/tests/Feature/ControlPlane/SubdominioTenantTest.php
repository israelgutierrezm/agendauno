<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config()->set('turnouno.dominio_base', 'agendauno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('resuelve el estudio por subdominio y autentica con el bearer tenant-local', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("http://{$e['slug']}.agendauno.mx/api/v1/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.email', 'a@correo.mx')
        ->assertJsonPath('data.estudio.slug', 'estudio-a');
});

it('permite iniciar sesion por subdominio', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson('http://estudio-a.agendauno.mx/api/v1/login', [
        'email' => 'a@correo.mx', 'password' => 'secreto123',
    ])->assertOk()->assertJsonPath('data.estudio.slug', 'estudio-a');
});

it('la operacion por subdominio es tenant-local: alta e aislamiento', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    // Alta de alumno via subdominio de A.
    $this->postJson('http://estudio-a.agendauno.mx/api/v1/miembros', [
        'nombre' => 'Ana', 'tipo' => 'miembro',
    ], conBearer($a['bearer']))->assertCreated();

    // Cada estudio ve solo lo suyo (via subdominio).
    $this->getJson('http://estudio-a.agendauno.mx/api/v1/miembros', conBearer($a['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('http://estudio-b.agendauno.mx/api/v1/miembros', conBearer($b['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('un bearer no autentica en el subdominio de otro estudio', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');

    // El token de A vive en la BD de A: en el subdominio de B no resuelve.
    $this->getJson('http://estudio-b.agendauno.mx/api/v1/yo', conBearer($a['bearer']))
        ->assertStatus(401);
});

it('un subdominio inexistente responde 404 sin filtrar otros estudios', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson('http://noexiste.agendauno.mx/api/v1/yo', conBearer('1|x'))
        ->assertStatus(404);
});
