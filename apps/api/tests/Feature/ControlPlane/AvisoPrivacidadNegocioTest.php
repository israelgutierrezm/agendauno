<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Cada negocio publica su aviso de privacidad para sus clientes (el documento con la
| clave reservada `aviso-privacidad`): se consulta sin sesión antes de dar los datos
| (su página y agendar sin cuenta) y se firma en el portal como cualquier otro.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el aviso del negocio se consulta sin sesión y se avisa al agendar sin cuenta', function (): void {
    $e = estudioConSesion('barberia-aviso', 'dueno@barberia-aviso.mx', 'barberia');
    agendaSemilla($e);

    // Sin publicar: no hay aviso y el formulario para agendar no lo enlaza.
    $this->getJson("/api/v1/app/{$e['slug']}/aviso-privacidad")->assertNotFound();
    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.estudio.aviso_privacidad', false);

    $this->postJson("/api/v1/app/{$e['slug']}/waivers", [
        'clave' => 'aviso-privacidad', 'titulo' => 'Aviso de privacidad',
        'contenido' => 'Barbería Aviso trata tus datos para agendar tus citas.',
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/aviso-privacidad")->assertOk()
        ->assertJsonPath('data.titulo', 'Aviso de privacidad')
        ->assertJsonPath('data.contenido', 'Barbería Aviso trata tus datos para agendar tus citas.')
        ->assertJsonPath('data.version', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()
        ->assertJsonPath('data.estudio.aviso_privacidad', true);

    // Retirado, deja de mostrarse.
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers/{$id}/retirar", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson("/api/v1/app/{$e['slug']}/aviso-privacidad")->assertNotFound();
});
