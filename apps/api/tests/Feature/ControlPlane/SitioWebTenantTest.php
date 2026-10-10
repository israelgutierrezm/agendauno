<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| El sitio del negocio (ADR 0114): quien configura el negocio elige plantilla, ordena,
| muestra u oculta secciones, edita sus textos y banners, ve la vista previa y publica.
| El público ve solo lo publicado (sin nada publicado, la página de siempre), solo las
| secciones visibles y los banners vigentes; lo operativo sigue saliendo del sistema.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El borrador con cambios: otro orden, «Nosotros» con texto y foto, reseñas ocultas y
 * un banner.
 *
 * @param  array<string, mixed>  $borrador
 * @return array<string, mixed>
 */
function sitioWebEditado(array $borrador, ?string $foto = null): array
{
    $secciones = collect($borrador['secciones'])->keyBy('tipo');
    $nosotros = [...$secciones['nosotros'], 'titulo' => 'Quiénes somos', 'texto' => "Estudio de barrio.\nDesde 2019.", 'foto_url' => $foto];
    $orden = ['inicio', 'servicios', 'nosotros', 'agenda', 'horario', 'precios', 'equipo', 'promociones', 'sucursales', 'resenas', 'contacto'];

    return [
        'plantilla' => 'portada',
        'secciones' => collect($orden)->map(fn (string $tipo): array => match ($tipo) {
            'nosotros' => $nosotros,
            'resenas' => [...$secciones['resenas'], 'visible' => false],
            'servicios' => [...$secciones['servicios'], 'titulo' => 'Nuestras clases'],
            default => $secciones[$tipo],
        })->all(),
        'banners' => [[
            'titulo' => 'Inscripciones abiertas', 'texto' => 'Primera clase gratis',
            'enlace_texto' => 'Ver precios', 'enlace_url' => '#precios', 'desde' => null, 'hasta' => null,
        ]],
    ];
}

it('sin nada publicado, el público ve la página de siempre y el negocio parte de ella', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');

    $publico = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio');
    expect($publico['plantilla'])->toBe('esencial')
        ->and(collect($publico['secciones'])->pluck('tipo')->all())->toBe([
            'inicio', 'promociones', 'nosotros', 'agenda', 'servicios', 'horario', 'precios', 'equipo', 'resenas', 'sucursales', 'contacto',
        ])
        ->and($publico['banners'])->toBe([]);

    $sitio = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data');
    expect($sitio['cambios_sin_publicar'])->toBeFalse()
        ->and($sitio['publicado_en'])->toBeNull()
        ->and($sitio['pagina_publica'])->toBeTrue()
        ->and(collect($sitio['catalogo']['plantillas'])->pluck('clave')->all())->toBe(['esencial', 'portada', 'compacta']);
});

it('un negocio de citas no tiene horario de clases en su sitio', function (): void {
    $e = estudioConSesion('barberia-sitio', 'b@correo.mx', 'barberia');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data.borrador');
    expect(collect($borrador['secciones'])->pluck('tipo'))->not->toContain('horario');

    $borrador['secciones'][] = ['tipo' => 'horario', 'visible' => true];
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $borrador, conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['secciones.10.tipo'], 'meta.errors');
});

it('el borrador no se publica hasta que el negocio lo publica', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');

    $foto = $this->post("/api/v1/app/{$e['slug']}/sitio/imagenes", [
        'imagen' => UploadedFile::fake()->image('estudio.jpg', 800, 600),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()->json('data.url');

    $guardado = $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioWebEditado($borrador, $foto), conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($guardado['cambios_sin_publicar'])->toBeTrue()
        ->and($guardado['borrador']['plantilla'])->toBe('portada')
        ->and($guardado['borrador']['banners'][0]['id'])->toBeString();

    // El público sigue viendo lo de antes…
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.plantilla'))->toBe('esencial');

    // …y la vista previa, el borrador (solo para quien configura el negocio).
    $previa = $this->getJson("/api/v1/app/{$e['slug']}/sitio/vista-previa", conBearer($e['bearer']))->assertOk()->json('data');
    expect($previa['estudio']['slug'])->toBe('fluo-sitio')
        ->and($previa['sitio']['plantilla'])->toBe('portada');

    $publicado = $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk()->json('data');
    expect($publicado['cambios_sin_publicar'])->toBeFalse()->and($publicado['publicado_en'])->not->toBeNull();

    $sitio = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio');
    $tipos = collect($sitio['secciones'])->pluck('tipo')->all();
    // Su orden, sin las ocultas; portada y contacto siempre.
    expect($tipos)->toBe(['inicio', 'servicios', 'nosotros', 'agenda', 'horario', 'precios', 'equipo', 'promociones', 'sucursales', 'contacto'])
        ->and(collect($sitio['secciones'])->firstWhere('tipo', 'nosotros'))->toMatchArray([
            'titulo' => 'Quiénes somos', 'texto' => "Estudio de barrio.\nDesde 2019.", 'foto_url' => $foto,
        ])
        ->and(collect($sitio['secciones'])->firstWhere('tipo', 'servicios')['titulo'])->toBe('Nuestras clases')
        ->and($sitio['banners'][0])->toMatchArray(['titulo' => 'Inscripciones abiertas', 'enlace_url' => '#precios'])
        ->and($sitio['banners'][0])->not->toHaveKey('desde');
    Storage::disk('public')->assertExists(substr((string) parse_url($foto, PHP_URL_PATH), strlen('/storage/')));
});

it('descartar vuelve a lo publicado y borra las fotos que ya nadie usa', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $foto = $this->post("/api/v1/app/{$e['slug']}/sitio/imagenes", [
        'imagen' => UploadedFile::fake()->image('estudio.png', 400, 400),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()->json('data.url');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioWebEditado($borrador, $foto), conBearer($e['bearer']))->assertOk();

    $sitio = $this->postJson("/api/v1/app/{$e['slug']}/sitio/descartar", [], conBearer($e['bearer']))->assertOk()->json('data');
    expect($sitio['cambios_sin_publicar'])->toBeFalse()->and($sitio['borrador']['plantilla'])->toBe('esencial');
    Storage::disk('public')->assertMissing(substr((string) parse_url($foto, PHP_URL_PATH), strlen('/storage/')));
});

it('los banners se ven solo en sus fechas, en el día del negocio', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $borrador['banners'] = [
        ['titulo' => 'Vigente', 'desde' => '2026-09-25', 'hasta' => '2026-10-01'],
        ['titulo' => 'Ya pasó', 'desde' => '2026-09-01', 'hasta' => '2026-09-30'],
        ['titulo' => 'Todavía no', 'desde' => '2026-10-02', 'hasta' => null],
        ['titulo' => 'Siempre'],
    ];
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $borrador, conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();

    // Hoy es 2026-10-01 en el negocio (reloj fijo de las pruebas).
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.banners'))->pluck('titulo')->all())
        ->toBe(['Vigente', 'Siempre']);
});

it('valida lo que se guarda: fechas, enlaces, fotos ajenas y cuántos banners', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $con = fn (array $cambios): array => [...$borrador, ...$cambios];

    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $con(['banners' => [['titulo' => 'X', 'desde' => '2026-10-10', 'hasta' => '2026-10-01']]]), conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['banners.0.hasta'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $con(['banners' => [['titulo' => 'X', 'enlace_texto' => 'Ir', 'enlace_url' => 'javascript:alert(1)']]]), conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['banners.0.enlace_url'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $con(['banners' => [['titulo' => 'X', 'enlace_url' => 'https://wa.me/5215512345678']]]), conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['banners.0.enlace_texto'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $con(['banners' => [['titulo' => 'X', 'foto_url' => 'https://otro.sitio/foto.jpg']]]), conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['banners.0.foto_url'], 'meta.errors');

    // El máximo de banners es un parámetro (5 por omisión).
    $muchos = array_fill(0, 6, ['titulo' => 'Promo']);
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $con(['banners' => $muchos]), conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['banners'], 'meta.errors');

    // Una sección repetida no.
    $repetida = $borrador;
    $repetida['secciones'][] = $borrador['secciones'][1];
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", $repetida, conBearer($e['bearer']))->assertUnprocessable();

    // Una sección que falta vuelve al final, visible; portada y contacto en su lugar.
    $sinAlgunas = $borrador;
    $sinAlgunas['secciones'] = [['tipo' => 'contacto', 'visible' => false], ['tipo' => 'precios', 'visible' => true], ['tipo' => 'inicio', 'visible' => false]];
    $guardado = $this->putJson("/api/v1/app/{$e['slug']}/sitio", $sinAlgunas, conBearer($e['bearer']))->assertOk()->json('data.borrador.secciones');
    expect($guardado[0])->toMatchArray(['tipo' => 'inicio', 'visible' => true])
        ->and($guardado[1]['tipo'])->toBe('precios')
        ->and(end($guardado))->toMatchArray(['tipo' => 'contacto', 'visible' => true])
        ->and(count($guardado))->toBe(11);
});

it('una foto del sitio es una imagen y tiene un tope por negocio', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $this->post("/api/v1/app/{$e['slug']}/sitio/imagenes", [
        'imagen' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertUnprocessable();

    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    foreach (range(1, 30) as $i) {
        Storage::disk('public')->put("estudios/{$estudio->getKey()}/sitio/foto{$i}.jpg", 'x');
    }
    $this->post("/api/v1/app/{$e['slug']}/sitio/imagenes", [
        'imagen' => UploadedFile::fake()->image('otra.jpg'),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['imagen'], 'meta.errors');
});

it('solo quien configura el negocio edita su sitio o ve la vista previa', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    $alumna = alumnoConSesion($e);
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');

    foreach ([$alumna['bearer'], $recepcion] as $bearer) {
        $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($bearer))->assertForbidden();
        $this->getJson("/api/v1/app/{$e['slug']}/sitio/vista-previa", conBearer($bearer))->assertForbidden();
        $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($bearer))->assertForbidden();
    }
    $this->getJson("/api/v1/app/{$e['slug']}/sitio/vista-previa")->assertUnauthorized();
});

it('la vista previa funciona aunque la página aún no esté abierta al público', function (): void {
    $e = estudioConSesion('fluo-sitio', 'f@correo.mx');
    Estudio::query()->where('slug', $e['slug'])->update(['publicado' => false]);

    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertNotFound();
    $this->getJson("/api/v1/app/{$e['slug']}/sitio/vista-previa", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.sitio.plantilla', 'esencial');
    $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertJsonPath('data.pagina_publica', false);
});
