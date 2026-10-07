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
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
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

it('el pase de entrada solo en negocios de acceso libre, aunque un estudio tenga entradas registradas', function (): void {
    // Un estudio (pole): entrar es tomar la clase. Aunque alguien registre una entrada,
    // su alumna no ve pase (ADR 0105).
    $estudio = estudioConSesion('estudio-pase', 'dueno@estudio-pase.mx', 'pole');
    agendaSemilla($estudio);
    $ana = alumnoConSesion($estudio, 'Ana', 'ana@correo.mx');
    $otra = (string) $this->postJson("/api/v1/app/{$estudio['slug']}/miembros", [
        'nombre' => 'Lu', 'email' => 'lu@correo.mx', 'tipo' => 'miembro',
    ], conBearer($estudio['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$estudio['slug']}/accesos", [
        'persona_id' => $otra, 'metodo' => 'manual',
    ], conBearer($estudio['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$estudio['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.portal.pase', false);

    // Un gimnasio (acceso libre): su cliente sí tiene pase.
    $gimnasio = estudioConSesion('gimnasio-pase', 'dueno@gimnasio-pase.mx', 'gimnasio');
    agendaSemilla($gimnasio);
    $beto = alumnoConSesion($gimnasio, 'Beto', 'beto@correo.mx');
    $this->getJson("/api/v1/app/{$gimnasio['slug']}/mi/perfil", conBearer($beto['bearer']))
        ->assertOk()->assertJsonPath('data.portal.pase', true);
});
