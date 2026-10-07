<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Foto de un servicio (ADR 0066): el negocio la sube en Catálogo y la ve quien elige
| al agendar y en la página del negocio. Una por servicio; solo imágenes.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('sube la foto del servicio, la reemplaza y la quita; se ve al agendar y en la página', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $oferta = agendaSemilla($e)['oferta'];
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$oferta}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    pasarNegocioACitas($e);

    $url = $this->post("/api/v1/app/{$e['slug']}/ofertas/{$oferta}/foto", [
        'foto' => UploadedFile::fake()->image('corte.jpg', 800, 600),
    ], conBearer($e['bearer']))->assertOk()->json('data.foto_url');
    expect($url)->toBeString();
    $archivos = Storage::disk('public')->allFiles('servicios');
    expect($archivos)->toHaveCount(1);

    $opciones = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.servicios'));
    expect($opciones->firstWhere('id', $oferta)['foto_url'])->toBe($url);
    $publico = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.servicios'));
    expect($publico->firstWhere('id', $oferta)['foto_url'])->toBe($url);
    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))->json('data'));
    expect($catalogo->firstWhere('id', $oferta)['foto_url'])->toBe($url);

    // Otra la reemplaza; quitarla la borra.
    $this->post("/api/v1/app/{$e['slug']}/ofertas/{$oferta}/foto", [
        'foto' => UploadedFile::fake()->image('otra.png', 800, 600),
    ], conBearer($e['bearer']))->assertOk();
    expect(Storage::disk('public')->allFiles('servicios'))->toHaveCount(1)
        ->and(Storage::disk('public')->exists($archivos[0]))->toBeFalse();
    $this->deleteJson("/api/v1/app/{$e['slug']}/ofertas/{$oferta}/foto", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.foto_url', null);
    expect(Storage::disk('public')->allFiles('servicios'))->toBe([]);
});

it('solo acepta imágenes y solo quien gestiona el catálogo sube la foto', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $oferta = agendaSemilla($e)['oferta'];

    $this->post("/api/v1/app/{$e['slug']}/ofertas/{$oferta}/foto", [
        'foto' => UploadedFile::fake()->create('corte.svg', 10, 'image/svg+xml'),
    ], conBearer($e['bearer']) + ['Accept' => 'application/json'])->assertUnprocessable();

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->post("/api/v1/app/{$e['slug']}/ofertas/{$oferta}/foto", [
        'foto' => UploadedFile::fake()->image('corte.jpg', 800, 600),
    ], conBearer($recepcion) + ['Accept' => 'application/json'])->assertForbidden();
    expect(Storage::disk('public')->allFiles('servicios'))->toBe([]);
});
