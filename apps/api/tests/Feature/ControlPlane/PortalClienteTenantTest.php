<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La cuenta del cliente muestra créditos, expediente y pase solo cuando el negocio de
| verdad los usa (ADR 0091): a quien solo se corta el cabello no le sirve «Sin paquete».
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('en una barbería sin bonos, ni créditos ni expediente ni pase', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
    agendaSemilla($e);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->assertOk()
        ->assertJsonPath('data.portal', ['creditos' => false, 'pase' => false, 'expediente' => false])
        ->assertJsonPath('data.asistencias_30_dias', 0);
});

it('en un estudio de clases que vende planes, sus créditos; con consentimientos, su expediente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);
    crearPackTenant($e);
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", [
        'clave' => 'deslinde', 'titulo' => 'Deslinde', 'contenido' => 'Acepto los riesgos.',
    ], conBearer($e['bearer']))->assertCreated();
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->assertOk()
        ->assertJsonPath('data.portal.creditos', true)
        ->assertJsonPath('data.portal.expediente', true)
        ->assertJsonPath('data.portal.pase', false);
});
