<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\BorradorSitioWebTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PublicacionSitioWebTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Archivos de los negocios en S3 (ADR 0170): las imágenes (logo, fotos, sitio) y los
| documentos pueden guardarse fuera del servidor. `agendauno:mover-archivos` lleva lo
| que ya estaba en el volumen y cambia las URL guardadas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
    Storage::fake('local');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Los dos discos en «S3» (falsos, con la URL pública del bucket de imágenes). */
function archivosEnS3(): void
{
    config(['agendauno.archivos.publicos' => 's3_publicos', 'agendauno.archivos.privados' => 's3_privados']);
    Storage::fake('s3_publicos', ['url' => 'https://archivos.prueba']);
    Storage::fake('s3_privados');
}

/**
 * Sube el logo, una foto para «Nosotros» (guardada y publicada) y un documento.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{foto: string, documento: string}
 */
function subirArchivosDelNegocio(array $e): array
{
    $base = "/api/v1/app/{$e['slug']}";
    $json = [...conBearer($e['bearer']), 'Accept' => 'application/json'];
    test()->post("{$base}/marca/logo", ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)], $json)->assertOk();
    $foto = (string) test()->post("{$base}/sitio/imagenes", ['imagen' => UploadedFile::fake()->image('estudio.jpg', 800, 600)], $json)
        ->assertCreated()->json('data.url');
    $borrador = test()->getJson("{$base}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $secciones = array_map(fn (array $s): array => $s['tipo'] === 'nosotros' ? [...$s, 'visible' => true, 'foto_url' => $foto] : $s, $borrador['secciones']);
    test()->putJson("{$base}/sitio", ['plantilla' => $borrador['plantilla'], 'secciones' => $secciones, 'banners' => []], conBearer($e['bearer']))->assertOk();
    test()->postJson("{$base}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();

    $persona = (string) test()->postJson("{$base}/miembros", ['nombre' => 'Rosa'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $documento = (string) test()->post("{$base}/documentos", [
        'persona_id' => $persona, 'archivo' => UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf'),
    ], $json)->assertCreated()->json('data.id');

    return ['foto' => $foto, 'documento' => $documento];
}

it('en S3, las imágenes y los documentos van a su bucket y nada queda en el servidor', function (): void {
    archivosEnS3();
    $e = estudioConSesion('fluo-s3', 'f@correo.mx');

    $subido = subirArchivosDelNegocio($e);

    $estudio = Estudio::query()->where('slug', 'fluo-s3')->firstOrFail();
    expect($estudio->logo_url)->toStartWith("https://archivos.prueba/estudios/{$estudio->id}/logo_")
        ->and($subido['foto'])->toStartWith("https://archivos.prueba/estudios/{$estudio->id}/sitio/")
        ->and(Storage::disk('s3_publicos')->allFiles())->toHaveCount(2)
        ->and(Storage::disk('s3_privados')->allFiles('documentos'))->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles('documentos'))->toBe([]);

    // El sitio publicado la muestra; el documento se descarga por la API.
    $nosotros = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.secciones'))->firstWhere('tipo', 'nosotros');
    expect($nosotros['foto_url'])->toBe($subido['foto']);
    $this->get("/api/v1/app/{$e['slug']}/documentos/{$subido['documento']}", conBearer($e['bearer']))->assertOk();

    // La app instalable usa el logo desde el bucket.
    $icono = $this->get('http://fluo-s3.agendauno.mx/api/v1/pwa/manifest.webmanifest')->assertOk()->json('icons.0');
    expect($icono)->toMatchArray(['src' => $estudio->logo_url, 'sizes' => '300x300', 'type' => 'image/png']);
});

it('mover-archivos lleva lo del servidor a S3 y cambia las URL; repetirlo no copia de nuevo', function (): void {
    $e = estudioConSesion('fluo-mudanza', 'f@correo.mx');
    $subido = subirArchivosDelNegocio($e);
    $estudio = Estudio::query()->where('slug', 'fluo-mudanza')->firstOrFail();
    expect($estudio->logo_url)->toStartWith('/storage/');

    archivosEnS3();
    // Mientras se mueven, el sitio sigue aceptando sus fotos de antes.
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", ['plantilla' => $borrador['plantilla'], 'secciones' => $borrador['secciones'], 'banners' => []], conBearer($e['bearer']))
        ->assertOk();

    $this->artisan('agendauno:mover-archivos', ['--dry-run' => true])->assertSuccessful();
    expect(Storage::disk('s3_publicos')->allFiles())->toBe([]);

    $this->artisan('agendauno:mover-archivos')
        ->expectsOutputToContain('Imágenes de «public» a «s3_publicos»: 2 copiados, 0 ya estaban.')
        ->expectsOutputToContain('Documentos de «local» a «s3_privados»: 1 copiados, 0 ya estaban.')
        ->assertSuccessful();

    $nueva = 'https://archivos.prueba/'.substr($subido['foto'], strlen('/storage/'));
    $estudio->refresh();
    expect($estudio->logo_url)->toStartWith('https://archivos.prueba/estudios/')
        ->and(Storage::disk('s3_publicos')->allFiles())->toHaveCount(2)
        // Los originales se quedan para volver atrás.
        ->and(Storage::disk('public')->allFiles())->toHaveCount(2);
    $contenidos = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): string => json_encode([
        BorradorSitioWebTenant::query()->first()?->borrador,
        PublicacionSitioWebTenant::query()->get()->pluck('contenido')->all(),
    ], JSON_UNESCAPED_SLASHES));
    expect($contenidos)->toContain($nueva)->not->toContain('"/storage/');
    $nosotros = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.secciones'))->firstWhere('tipo', 'nosotros');
    expect($nosotros['foto_url'])->toBe($nueva);
    $this->get("/api/v1/app/{$e['slug']}/documentos/{$subido['documento']}", conBearer($e['bearer']))->assertOk();

    $this->artisan('agendauno:mover-archivos')
        ->expectsOutputToContain('Imágenes de «public» a «s3_publicos»: 0 copiados, 2 ya estaban.')
        ->expectsOutputToContain('Se cambiaron en 0 negocio(s).')
        ->assertSuccessful();
});

it('la verificación de producción pide la URL de las imágenes y un bucket propio para los documentos', function (): void {
    config([
        'agendauno.archivos.publicos' => 's3_publicos',
        'agendauno.archivos.privados' => 's3_privados',
        'filesystems.disks.s3_publicos.url' => null,
        'filesystems.disks.s3_publicos.bucket' => 'archivos',
        'filesystems.disks.s3_privados.bucket' => 'archivos',
    ]);

    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('FALTA URL pública de las imágenes')
        ->expectsOutputToContain('FALTA Documentos en un bucket privado propio')
        ->assertFailed();

    config(['filesystems.disks.s3_publicos.url' => 'https://archivos.prueba', 'filesystems.disks.s3_privados.bucket' => 'documentos']);
    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('OK    URL pública de las imágenes')
        ->expectsOutputToContain('OK    Documentos en un bucket privado propio')
        ->assertFailed();
});
