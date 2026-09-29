<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Support\EnlaceMapa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Foto y enlace de Google Maps de la sede (ADR 0064): al agendar, el cliente reconoce
| la sede por su foto y ve cómo llegar antes de confirmar. El enlace solo puede ser de
| Google Maps; sin él, se arma con la dirección.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('sube la foto de la sede, la reemplaza y la quita; al agendar se ve', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e)['sucursal'];

    $primera = $this->post("/api/v1/app/{$e['slug']}/sucursales/{$sede}/foto", [
        'foto' => UploadedFile::fake()->image('sede.jpg', 800, 600),
    ], conBearer($e['bearer']))->assertOk()->json('data.foto_url');
    expect($primera)->toBeString();
    $archivos = Storage::disk('public')->allFiles('sucursales');
    expect($archivos)->toHaveCount(1);

    $opciones = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data.sucursales'));
    expect($opciones->firstWhere('id', $sede)['foto_url'])->toBe($primera);

    // Otra foto reemplaza a la anterior (una por sede).
    $this->post("/api/v1/app/{$e['slug']}/sucursales/{$sede}/foto", [
        'foto' => UploadedFile::fake()->image('otra.png', 800, 600),
    ], conBearer($e['bearer']))->assertOk();
    expect(Storage::disk('public')->allFiles('sucursales'))->toHaveCount(1)
        ->and(Storage::disk('public')->exists($archivos[0]))->toBeFalse();

    $this->deleteJson("/api/v1/app/{$e['slug']}/sucursales/{$sede}/foto", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.foto_url', null);
    expect(Storage::disk('public')->allFiles('sucursales'))->toBe([]);
});

it('solo acepta imágenes y solo quien gestiona sucursales sube la foto', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e)['sucursal'];

    $this->post("/api/v1/app/{$e['slug']}/sucursales/{$sede}/foto", [
        'foto' => UploadedFile::fake()->create('sede.svg', 10, 'image/svg+xml'),
    ], conBearer($e['bearer']) + ['Accept' => 'application/json'])->assertUnprocessable();

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->post("/api/v1/app/{$e['slug']}/sucursales/{$sede}/foto", [
        'foto' => UploadedFile::fake()->image('sede.jpg', 800, 600),
    ], conBearer($recepcion) + ['Accept' => 'application/json'])->assertForbidden();
    expect(Storage::disk('public')->allFiles('sucursales'))->toBe([]);
});

it('guarda el enlace de Google Maps de la sede y es el que se ve para llegar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede}", [
        'direccion' => 'Av. Álvaro Obregón 120, Roma Norte',
    ], conBearer($e['bearer']))->assertOk();

    // Sin enlace propio: el armado con la dirección.
    $mapa = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.sucursales'))
        ->firstWhere('id', $sede)['mapa_url'];
    expect($mapa)->toStartWith('https://www.google.com/maps/search/?api=1&query=Av.');

    // Con el de «Compartir» de Google Maps (sin https): se guarda con https.
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede}", [
        'mapa_url' => 'maps.app.goo.gl/AbC123xyz',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.mapa_url', 'https://maps.app.goo.gl/AbC123xyz');

    $opciones = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.sucursales'))->firstWhere('id', $sede);
    expect($opciones['mapa_url'])->toBe('https://maps.app.goo.gl/AbC123xyz')
        ->and($opciones['direccion'])->toBe('Av. Álvaro Obregón 120, Roma Norte');
    $publico = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sucursales'))->firstWhere('id', $sede);
    expect($publico['mapa_url'])->toBe('https://maps.app.goo.gl/AbC123xyz');

    // Otro dominio no se acepta; vacío lo quita.
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede}", [
        'mapa_url' => 'https://maps.ejemplo.com/sede',
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['mapa_url'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede}", ['mapa_url' => ''], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.mapa_url', null);
});

it('reconoce los enlaces de Google Maps y rechaza lo demás', function (?string $enlace, ?string $esperado): void {
    expect(EnlaceMapa::normalizar($enlace))->toBe($esperado);
})->with([
    'compartir' => ['https://maps.app.goo.gl/AbC123xyz', 'https://maps.app.goo.gl/AbC123xyz'],
    'sin esquema' => ['maps.app.goo.gl/AbC123xyz', 'https://maps.app.goo.gl/AbC123xyz'],
    'barra del navegador' => ['https://www.google.com/maps/place/Roma+Norte/@19.41,-99.16,17z', 'https://www.google.com/maps/place/Roma+Norte/@19.41,-99.16,17z'],
    'google de México' => ['http://www.google.com.mx/maps?q=19.41,-99.16', 'https://www.google.com.mx/maps?q=19.41,-99.16'],
    'maps.google.com' => ['https://maps.google.com/?q=Roma+Norte', 'https://maps.google.com/?q=Roma+Norte'],
    'goo.gl/maps' => ['https://goo.gl/maps/AbC123', 'https://goo.gl/maps/AbC123'],
    'búsqueda de google' => ['https://www.google.com/search?q=roma', null],
    'otro dominio' => ['https://maps.ejemplo.com/sede', null],
    'dominio parecido' => ['https://google.com.evil.io/maps/x', null],
    'javascript' => ['javascript:alert(1)//maps.app.goo.gl', null],
    'con usuario' => ['https://usuario@maps.app.goo.gl/x', null],
    'vacío' => ['  ', null],
]);
