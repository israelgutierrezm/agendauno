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
        ->and(collect($manifiesto['icons'])->map(fn (array $i): string => $i['sizes'].' '.$i['purpose'])->all())
        ->toBe(['192x192 any', '512x512 any', '512x512 maskable'])
        ->and($manifiesto['icons'][0]['src'])->toStartWith('/api/v1/pwa/icono-192.png?v=');

    // En el dominio del otro producto no existe.
    $this->get('http://barberia-norte.agendauno.mx/api/v1/pwa/manifest.webmanifest')->assertNotFound();
    // Mandar el perfil sin el color no lo borra.
    $this->putJson("http://localhost/api/v1/app/{$e['slug']}/perfil-publico", ['descripcion' => 'Cortes clásicos'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.color_marca', '#b03a2e');
});

it('los íconos cuadrados se generan del logo, sin deformarlo, y cambian con él', function (): void {
    $e = estudioConSesion('fluo-iconos', 'f@correo.mx');
    // Un logo ancho (300×100) no se corta: queda centrado dentro del cuadrado.
    $this->postJson("/api/v1/app/{$e['slug']}/marca/logo", [
        'logo' => UploadedFile::fake()->image('logo.png', 300, 100),
    ], conBearer($e['bearer']))->assertOk();

    $iconos = $this->get('http://fluo-iconos.agendauno.mx/api/v1/pwa/manifest.webmanifest')->assertOk()->json('icons');
    expect($iconos)->toHaveCount(3);

    foreach (['192' => 192, '512' => 512, '512-maskable' => 512] as $tam => $lado) {
        $src = collect($iconos)->first(fn (array $i): bool => str_starts_with($i['src'], "/api/v1/pwa/icono-{$tam}.png"))['src'];
        $png = $this->get("http://fluo-iconos.agendauno.mx{$src}")->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $medidas = getimagesizefromstring((string) $png);
        expect([$medidas[0], $medidas[1]])->toBe([$lado, $lado]);
    }
    // El «maskable» va sobre fondo (el teléfono lo recorta); el logo, dentro de su zona segura.
    $maskable = imagecreatefromstring((string) $this->get('http://fluo-iconos.agendauno.mx/api/v1/pwa/icono-512-maskable.png')->getContent());
    expect(imagecolorsforindex($maskable, imagecolorat($maskable, 5, 5)))->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0]);

    // Otro logo, otra versión en la dirección.
    $this->postJson("/api/v1/app/{$e['slug']}/marca/logo", [
        'logo' => UploadedFile::fake()->image('otro.png', 200, 200),
    ], conBearer($e['bearer']))->assertOk();
    $nuevos = $this->get('http://fluo-iconos.agendauno.mx/api/v1/pwa/manifest.webmanifest')->json('icons');
    expect($nuevos[0]['src'])->not->toBe($iconos[0]['src']);

    // Sin logo no hay íconos propios; un tamaño que no existe, tampoco.
    $sinLogo = estudioConSesion('fluo-sin-logo', 's@correo.mx');
    $this->get("http://{$sinLogo['slug']}.agendauno.mx/api/v1/pwa/icono-192.png")->assertNotFound();
    $this->get('http://fluo-iconos.agendauno.mx/api/v1/pwa/icono-64.png')->assertNotFound();
});
