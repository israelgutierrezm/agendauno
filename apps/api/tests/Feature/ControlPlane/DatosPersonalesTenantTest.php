<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ExportarDatosPersonaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Fecha de nacimiento y género de una persona: opcionales, de una lista breve e
| incluyente; los anota el negocio (alta y edición) o la persona en «Mi perfil», y
| salen en su ficha y en la exportación de sus datos (ARCO).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el negocio los anota al dar de alta y los cambia o borra después', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'tipo' => 'miembro', 'fecha_nacimiento' => '1994-03-14', 'genero' => 'mujer',
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.fecha_nacimiento', '1994-03-14')
        ->assertJsonPath('data.genero', 'mujer')
        ->json('data.id');

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$id}", ['genero' => 'no_binario'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.genero', 'no_binario')
        ->assertJsonPath('data.fecha_nacimiento', '1994-03-14');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$id}/ficha", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.persona.nombre', 'Ana')
        ->assertJsonPath('data.persona.fecha_nacimiento', '1994-03-14')
        ->assertJsonPath('data.persona.genero', 'no_binario');

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$id}", ['fecha_nacimiento' => null, 'genero' => null], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.fecha_nacimiento', null)
        ->assertJsonPath('data.genero', null);
});

it('rechaza un género fuera de la lista y una fecha futura', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'genero' => 'x'], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.genero.0', 'Elige un género de la lista.');
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'fecha_nacimiento' => '2030-01-01'], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.fecha_nacimiento.0', 'La fecha de nacimiento no puede ser futura.');
});

it('la persona los anota en Mi perfil y salen en la exportación de sus datos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = alumnoConSesion($e);

    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", [
        'nombre' => 'Vale', 'fecha_nacimiento' => '2001-07-30', 'genero' => 'prefiero_no_decir',
    ], conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.fecha_nacimiento', '2001-07-30')
        ->assertJsonPath('data.usuario.genero', 'prefiero_no_decir');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.genero', 'prefiero_no_decir');

    $datos = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->sole(),
        fn (): array => app(ExportarDatosPersonaTenant::class)->para(PersonaTenant::query()->where('email', 'vale@correo.mx')->sole()),
    );
    expect($datos['persona'])->toMatchArray(['fecha_nacimiento' => '2001-07-30', 'genero' => 'prefiero_no_decir']);
});
