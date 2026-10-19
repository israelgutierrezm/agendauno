<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Perfiles del menú: la sesión dice lo que el negocio tiene para que la web solo ofrezca
| lo que le sirve. El reporte de Wellhub y TotalPass, solo a quien los recibe.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la sesión dice si recibe visitantes de Wellhub o TotalPass (para ofrecer su reporte)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $yo = fn () => $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();

    $yo()->assertJsonPath('data.estudio.plataformas_bienestar', false);

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();
    $yo()->assertJsonPath('data.estudio.plataformas_bienestar', true);

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", ['activa' => false], conBearer($e['bearer']))->assertOk();
    $yo()->assertJsonPath('data.estudio.plataformas_bienestar', false);
});

it('un negocio de citas nunca ofrece el reporte de plataformas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    pasarNegocioACitas($e);

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.plataformas_bienestar', false);
});
