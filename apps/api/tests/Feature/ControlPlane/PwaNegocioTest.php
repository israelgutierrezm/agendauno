<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| La app instalable (PWA) de cada negocio (ADR 0110): su manifiesto en su subdominio,
| con su nombre, su logo, su color (o los del producto) y sin pedir elegir el negocio.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.dominio_base', 'agendauno.mx');
    Config::set('agendauno.productos.turnouno.dominio', 'turnouno.mx');
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el manifiesto de un estudio de clases: su nombre, su origen y los íconos de AgendaUno', function (): void {
    estudioConSesion('fluo-pilates', 'f@correo.mx');

    $respuesta = $this->get('http://fluo-pilates.agendauno.mx/api/v1/pwa/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');
    $manifiesto = $respuesta->json();

    expect($manifiesto)->toMatchArray([
        'id' => '/',
        'name' => 'Estudio fluo-pilates',
        'short_name' => 'Estudio',
        'start_url' => '/?origen=app',
        'scope' => '/',
        'display' => 'standalone',
        'theme_color' => '#031b4e',
    ])
        ->and($manifiesto['description'])->toContain('clases')
        ->and(collect($manifiesto['icons'])->pluck('src')->all())->toBe([
            '/assets/pwa/agendauno-192.png',
            '/assets/pwa/agendauno-512.png',
            '/assets/pwa/agendauno-512-maskable.png',
        ]);
});

it('una barbería usa su logo, su color y los íconos de TurnoUno, en su dominio', function (): void {
    $e = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');

    $this->postJson("/api/v1/app/{$e['slug']}/marca/logo", [
        'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/perfil-publico", [
        'color_marca' => '#B03A2E',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.color_marca', '#b03a2e');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil-publico", [
        'color_marca' => 'rojo',
    ], conBearer($e['bearer']))->assertUnprocessable();

    $manifiesto = $this->get('http://barberia-norte.turnouno.mx/api/v1/pwa/manifest.webmanifest')
        ->assertOk()->json();

    expect($manifiesto['theme_color'])->toBe('#b03a2e')
        ->and($manifiesto['description'])->toContain('citas')
        ->and($manifiesto['icons'][0])->toMatchArray(['sizes' => '300x300', 'type' => 'image/png', 'purpose' => 'any'])
        ->and($manifiesto['icons'][0]['src'])->toStartWith('/storage/')
        ->and($manifiesto['icons'][1]['src'])->toBe('/assets/pwa/turnouno-192.png');

    // En el dominio del otro producto no existe.
    $this->get('http://barberia-norte.agendauno.mx/api/v1/pwa/manifest.webmanifest')->assertNotFound();
    // Mandar el perfil sin el color no lo borra.
    $this->putJson("http://localhost/api/v1/app/{$e['slug']}/perfil-publico", ['descripcion' => 'Cortes clásicos'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.color_marca', '#b03a2e');
});
