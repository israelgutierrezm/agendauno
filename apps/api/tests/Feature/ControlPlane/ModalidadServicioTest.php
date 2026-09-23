<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Modalidad de servicio del tenant (clases con cupo vs citas 1 a 1), derivada del
| perfil de negocio y expuesta en `perfil_config.modalidad` para que agenda,
| terminología, menú y cobro se adapten sin forks por industria.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un estudio de clases expone la modalidad clases en su sesion', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'pilates'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil_config.modalidad', 'clases');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.modalidad', 'clases');
});

it('un negocio de citas expone la modalidad citas y vuelve a clases al cambiar de perfil', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil_config.modalidad', 'citas');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.modalidad', 'citas');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'yoga'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil_config.modalidad', 'clases');
});

it('los perfiles de salud y belleza operan con citas', function (string $perfil): void {
    $e = estudioConSesion("negocio-{$perfil}", "dueno@{$perfil}.mx");

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => $perfil], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil_config.modalidad', 'citas');
})->with(['estetica', 'salon', 'spa', 'salud']);
