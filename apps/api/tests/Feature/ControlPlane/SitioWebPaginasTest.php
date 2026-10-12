<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Varias páginas en el sitio del negocio (ADR 0146, fase 2): además de Inicio, páginas
| propias con su dirección (/p/…), si van en el menú, su SEO y sus secciones (las del
| sistema, una vez por página, y bloques propios). Más el SEO de Inicio y el pie de
| página. Al publicar, el SEO se copia al negocio para la cabecera y el sitemap que ven
| buscadores y redes.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
    Config::set('agendauno.dominio_base', 'agendauno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El borrador con dos páginas, el SEO de Inicio y el pie.
 *
 * @param  array<string, mixed>  $borrador
 * @return array<string, mixed>
 */
function sitioConPaginas(array $borrador): array
{
    return [
        ...$borrador,
        'seo' => ['titulo' => 'Fluō Pilates en la Roma', 'descripcion' => 'Pilates reformer en grupos pequeños.'],
        'pie' => ['texto' => 'Abrimos de lunes a sábado.'],
        'paginas' => [
            [
                'titulo' => 'Nosotros', 'slug' => 'nosotros', 'en_menu' => true,
                'seo' => ['titulo' => 'Conoce a Fluō', 'descripcion' => 'Quiénes somos.'],
                'secciones' => [
                    ['tipo' => 'nosotros', 'visible' => true, 'texto' => 'Estudio de barrio desde 2019.'],
                    ['tipo' => 'preguntas', 'visible' => true, 'titulo' => 'Dudas', 'datos' => ['items' => [['pregunta' => '¿Hay estacionamiento?', 'respuesta' => 'Sí.']]]],
                ],
            ],
            [
                'titulo' => 'Precios', 'slug' => 'precios', 'en_menu' => false,
                'secciones' => [
                    ['tipo' => 'precios', 'visible' => true],
                    ['tipo' => 'servicios', 'visible' => false],
                ],
            ],
        ],
    ];
}

it('el negocio arma páginas con su dirección, menú, SEO y secciones; el público las ve al publicar', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data');
    expect($borrador['borrador']['paginas'])->toBe([])
        ->and($borrador['catalogo']['paginas_maximas'])->toBe(8);

    $guardado = $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioConPaginas($borrador['borrador']), conBearer($e['bearer']))
        ->assertOk()->json('data.borrador');
    expect($guardado['paginas'])->toHaveCount(2)
        ->and($guardado['paginas'][0])->toMatchArray(['slug' => 'nosotros', 'titulo' => 'Nosotros', 'en_menu' => true])
        ->and($guardado['paginas'][0]['id'])->toBeString()
        ->and(collect($guardado['paginas'][0]['secciones'])->pluck('tipo')->all())->toBe(['nosotros', 'preguntas'])
        ->and($guardado['seo'])->toBe(['titulo' => 'Fluō Pilates en la Roma', 'descripcion' => 'Pilates reformer en grupos pequeños.', 'imagen_url' => null])
        ->and($guardado['pie'])->toBe(['texto' => 'Abrimos de lunes a sábado.']);

    // Hasta publicar, el público no ve las páginas.
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.paginas'))->toBe([]);
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();

    $sitio = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio');
    expect(collect($sitio['paginas'])->pluck('slug')->all())->toBe(['nosotros', 'precios'])
        // Solo lo visible: «servicios» estaba oculta.
        ->and(collect($sitio['paginas'][1]['secciones'])->pluck('tipo')->all())->toBe(['precios'])
        ->and($sitio['paginas'][1]['en_menu'])->toBeFalse()
        ->and($sitio['seo']['titulo'])->toBe('Fluō Pilates en la Roma')
        ->and($sitio['pie']['texto'])->toBe('Abrimos de lunes a sábado.');

    // El SEO publicado queda en el negocio para la cabecera del servidor.
    $seo = Estudio::query()->where('slug', 'fluo')->firstOrFail()->seo_sitio;
    expect($seo['inicio']['titulo'])->toBe('Fluō Pilates en la Roma')
        ->and($seo['paginas'][0])->toMatchArray(['slug' => 'nosotros', 'titulo' => 'Nosotros', 'seo_titulo' => 'Conoce a Fluō']);
});

it('la cabecera para buscadores y redes y el sitemap usan el SEO de cada página publicada', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    Estudio::query()->where('slug', 'fluo')->update(['publicado' => true, 'privado' => false]);
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioConPaginas($borrador), conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();

    $inicio = $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertOk()->getContent();
    expect($inicio)->toContain('<title>Fluō Pilates en la Roma</title>')
        ->and($inicio)->toContain('content="Pilates reformer en grupos pequeños."');

    $nosotros = $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/estudio/fluo/p/nosotros'])->assertOk()->getContent();
    expect($nosotros)->toContain('<title>Conoce a Fluō</title>')
        ->and($nosotros)->toContain('href="https://fluo.agendauno.mx/estudio/fluo/p/nosotros"');
    // Sin SEO propio: su título con el nombre del negocio.
    expect($this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/estudio/fluo/p/precios'])->getContent())
        ->toContain('<title>Precios | Estudio fluo</title>');
    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/estudio/fluo/p/no-existe'])->assertNoContent();

    expect($this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/sitemap.xml')->assertOk()->getContent())
        ->toContain('<loc>https://fluo.agendauno.mx/estudio/fluo/p/nosotros</loc>')
        ->toContain('<loc>https://fluo.agendauno.mx/estudio/fluo/p/precios</loc>');
});

it('valida las páginas: dirección, repetidas, secciones de Inicio, cuántas y fotos ajenas', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $guardar = fn (array $paginas, array $extra = []) => $this->putJson(
        "/api/v1/app/{$e['slug']}/sitio",
        [...$borrador, 'paginas' => $paginas, ...$extra],
        conBearer($e['bearer']),
    );
    $pagina = fn (string $slug, array $secciones = []): array => ['titulo' => 'Una', 'slug' => $slug, 'secciones' => $secciones];

    $guardar([$pagina('Mi Página')])->assertUnprocessable()->assertJsonValidationErrors(['paginas.0.slug'], 'meta.errors');
    $guardar([$pagina('nosotros'), $pagina('nosotros')])->assertUnprocessable()->assertJsonValidationErrors(['paginas.1.slug'], 'meta.errors');
    // La portada y el contacto son de Inicio; las del sistema, una vez por página.
    $guardar([$pagina('una', [['tipo' => 'inicio', 'visible' => true]])])
        ->assertUnprocessable()->assertJsonValidationErrors(['paginas.0.secciones.0.tipo'], 'meta.errors');
    $guardar([$pagina('una', [['tipo' => 'precios', 'visible' => true], ['tipo' => 'precios', 'visible' => true]])])
        ->assertUnprocessable()->assertJsonValidationErrors(['paginas.0.secciones.1.tipo'], 'meta.errors');
    $guardar([['titulo' => 'Una', 'slug' => 'una', 'secciones' => [], 'seo' => ['imagen_url' => 'https://otro.com/a.jpg']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['paginas.0.seo.imagen_url'], 'meta.errors');
    $guardar([], ['seo' => ['descripcion' => str_repeat('a', 161)]])
        ->assertUnprocessable()->assertJsonValidationErrors(['seo.descripcion'], 'meta.errors');
    // Cuántas es un parámetro (8 por omisión).
    $guardar(array_map(fn (int $n): array => $pagina("pagina-{$n}"), range(1, 9)))
        ->assertUnprocessable()->assertJsonValidationErrors(['paginas'], 'meta.errors');
});
