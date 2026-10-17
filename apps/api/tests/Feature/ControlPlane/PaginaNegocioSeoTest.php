<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| La página pública de un negocio en su subdominio llega con SU cabecera (ADR 0128):
| nginx la pide con SSI para buscadores y vistas previas al compartir. Más su sitemap y
| su robots. Lo que no es página pública responde vacío (nginx deja la genérica).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.dominio_base', 'agendauno.mx');
    Config::set('agendauno.productos.turnouno.dominio', 'turnouno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Publica el negocio con datos para su página. */
function publicarNegocioParaSeo(string $slug, bool $privado = false): Estudio
{
    $estudio = Estudio::query()->where('slug', $slug)->firstOrFail();
    $estudio->update([
        'publicado' => true,
        'privado' => $privado,
        'ciudad' => 'Guadalajara',
        'descripcion' => 'Pilates <reformer> & barra',
        'logo_url' => '/storage/marcas/logo.png',
        'redes' => ['instagram' => 'https://instagram.com/fluo'],
    ]);

    return $estudio;
}

it('la portada de un estudio de clases: su título, su imagen, canonical y datos estructurados', function (): void {
    estudioConSesion('fluo', 'f@correo.mx');
    publicarNegocioParaSeo('fluo');

    $html = $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/?origen=app'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->getContent();

    expect($html)
        ->toContain('<title>Estudio fluo | Horarios y clases en AgendaUno</title>')
        ->toContain('content="Consulta próximas clases, instructores y precios de Estudio fluo en Guadalajara, MX."')
        ->toContain('<meta name="robots" content="index,follow,max-image-preview:large">')
        ->toContain('<meta property="og:site_name" content="AgendaUno">')
        ->toContain('<meta property="og:type" content="profile">')
        // El logo guardado como ruta del sitio se vuelve absoluto.
        ->toContain('<meta property="og:image" content="https://fluo.agendauno.mx/storage/marcas/logo.png">')
        ->toContain('<link rel="canonical" href="https://fluo.agendauno.mx/">')
        ->toContain('<script id="agendauno-route-jsonld" type="application/ld+json">')
        ->toContain('"sameAs":["https://instagram.com/fluo"]')
        // Lo que escribe el negocio no rompe el HTML.
        ->toContain('Pilates \u003Creformer\u003E & barra')
        ->not->toContain('<reformer>');

    // Su enlace corto y /estudio/{slug} son la misma página.
    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/estudio/fluo'])
        ->assertOk()->assertSee('<link rel="canonical" href="https://fluo.agendauno.mx/">', false);
    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/enlaces'])
        ->assertOk()
        ->assertSee('<title>Estudio fluo | Enlaces</title>', false)
        ->assertSee('<link rel="canonical" href="https://fluo.agendauno.mx/enlaces">', false);
});

it('un negocio de citas: su página de agendar, con la marca y el dominio de TurnoUno', function (): void {
    estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    publicarNegocioParaSeo('barberia-norte');

    $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/agendar/barberia-norte?sucursal=x'])
        ->assertOk()
        ->assertSee('<title>Agenda en Estudio barberia-norte | TurnoUno</title>', false)
        ->assertSee('<link rel="canonical" href="https://barberia-norte.turnouno.mx/agendar/barberia-norte">', false);
    $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])
        ->assertOk()->assertSee('Servicios y citas en TurnoUno', false);

    // En el dominio del otro producto no hay página.
    $this->get('http://barberia-norte.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertNoContent();
});

it('lo que no es página pública responde vacío: el panel, otro negocio, uno sin publicar', function (): void {
    estudioConSesion('fluo', 'f@correo.mx');

    // Sin publicar.
    Estudio::query()->where('slug', 'fluo')->update(['publicado' => false]);
    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertNoContent();

    publicarNegocioParaSeo('fluo');
    foreach (['/panel', '/entrar?estudio=fluo', '/estudio/fluo/vista-previa', '/estudio/otro', '/agendar/fluo'] as $pagina) {
        $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => $pagina])->assertNoContent();
    }
    // Un host que no es el subdominio de un negocio.
    $this->get('http://no-existe.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertNoContent();
    $this->get('http://localhost/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertNoContent();
});

it('«solo con enlace» se ve al compartir pero no se indexa ni tiene sitemap', function (): void {
    estudioConSesion('fluo', 'f@correo.mx');
    publicarNegocioParaSeo('fluo', privado: true);

    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Estudio fluo | Horarios y clases en AgendaUno">', false)
        ->assertSee('<meta name="robots" content="noindex,follow">', false)
        ->assertDontSee('rel="canonical"', false);

    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/sitemap.xml')->assertNotFound();
    // La web lo sabe por el escaparate, para no marcarla como indexable al mostrarla.
    $this->getJson('/api/v1/app/fluo/escaparate')->assertOk()->assertJsonPath('data.estudio.en_directorio', false);
    expect($this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/robots.txt')->assertOk()->getContent())
        ->toBe("User-agent: *\nAllow: /\n");
});

it('su sitemap y su robots, en su subdominio', function (): void {
    estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    publicarNegocioParaSeo('barberia-norte');

    $xml = $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();
    expect($xml)
        ->toContain('<loc>https://barberia-norte.turnouno.mx/</loc>')
        ->toContain('<loc>https://barberia-norte.turnouno.mx/enlaces</loc>')
        ->toContain('<loc>https://barberia-norte.turnouno.mx/agendar/barberia-norte</loc>')
        ->toContain('<lastmod>2026-10-01</lastmod>');

    expect($this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/robots.txt')->assertOk()->getContent())
        ->toBe("User-agent: *\nAllow: /\n\nSitemap: https://barberia-norte.turnouno.mx/sitemap.xml\n");
});

/**
 * Una barbería publicada con un servicio, su sede con dirección, teléfono y horario, y
 * un barbero.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}}
 */
function barberiaConDatosPublicos(): array
{
    $e = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'nombre' => 'Corte <clásico>', 'politica_reserva' => 'pago', 'precio_clase_minor' => 35000, 'duracion_minutos' => 45,
        'descripcion' => 'Con navaja y toalla caliente',
    ], conBearer($e['bearer']))->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", [
        'direccion' => 'Av. Juárez 123, Centro', 'region' => 'Jalisco', 'telefono' => '33 1234 5678',
        'horario' => [['dia' => 1, 'abre' => '10:00', 'cierra' => '20:00'], ['dia' => 6, 'abre' => '09:00', 'cierra' => '14:00']],
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'marco@correo.mx', 'instructor');
    publicarNegocioParaSeo('barberia-norte');

    return ['e' => $e, 'sede' => $sede];
}

it('sin JavaScript, su página trae su contenido: servicios con precio, equipo y sucursal con horario', function (): void {
    barberiaConDatosPublicos();

    $html = $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cuerpo', ['X-Pagina' => '/'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->getContent();

    expect($html)
        ->toStartWith('<main class="pagina-publica-texto"><h1>Estudio barberia-norte</h1>')
        ->toContain('<h2>Servicios</h2>')
        // Lo que escribe el negocio, escapado.
        ->toContain('<strong>Corte &lt;clásico&gt;</strong> · Pole Sport · 45 min · $350.00 MXN. Con navaja y toalla caliente')
        ->toContain('<h2>Equipo</h2>')
        ->toContain('<h2>Dónde estamos</h2>')
        ->toContain('Av. Juárez 123, Centro · Jalisco · 33 1234 5678 · Horario: Lun 10:00–20:00, Sáb 09:00–14:00')
        ->not->toContain('<clásico>');

    // Agendar: solo los servicios. Lo que no es página pública, nada.
    expect($this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cuerpo', ['X-Pagina' => '/agendar/barberia-norte'])->assertOk()->getContent())
        ->toContain('<h2>Servicios</h2>')->not->toContain('<h2>Equipo</h2>');
    $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cuerpo', ['X-Pagina' => '/panel'])->assertNoContent();
    $this->get('http://otro.turnouno.mx/api/v1/pagina-negocio/cuerpo', ['X-Pagina' => '/'])->assertNoContent();
});

it('sus datos estructurados dicen su giro, su dirección, su teléfono y su horario; la web recibe los mismos', function (): void {
    barberiaConDatosPublicos();

    $html = $this->get('http://barberia-norte.turnouno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertOk()->getContent();
    preg_match('#<script id="agendauno-route-jsonld" type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $jsonLd = json_decode($m[1] ?? 'null', true);

    expect($jsonLd)->toMatchArray([
        '@type' => 'HairSalon',
        'name' => 'Estudio barberia-norte',
        'telephone' => '33 1234 5678',
    ])
        ->and($jsonLd['address'])->toBe([
            '@type' => 'PostalAddress',
            'streetAddress' => 'Av. Juárez 123, Centro',
            'addressLocality' => 'Guadalajara',
            'addressRegion' => 'Jalisco',
            'addressCountry' => 'MX',
        ])
        ->and($jsonLd['openingHoursSpecification'][0])->toBe([
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => 'https://schema.org/Monday',
            'opens' => '10:00',
            'closes' => '20:00',
        ]);

    // La web los pone tal cual al mostrar la página (no se contradicen).
    $this->getJson('/api/v1/app/barberia-norte/escaparate')->assertOk()->assertJsonPath('data.json_ld', $jsonLd);
});

it('404 de verdad: un subdominio sin negocio, la página de uno sin publicar o una página propia que no existe', function (): void {
    estudioConSesion('fluo', 'f@correo.mx');
    $existe = fn (string $host, string $pagina): int => $this->get("http://{$host}/api/v1/pagina-negocio/existe", ['X-Pagina' => $pagina])->status();

    // Sin publicar: su página no existe, su panel y entrar sí.
    Estudio::query()->where('slug', 'fluo')->update(['publicado' => false]);
    expect($existe('fluo.agendauno.mx', '/'))->toBe(403)
        ->and($existe('fluo.agendauno.mx', '/entrar'))->toBe(204)
        ->and($existe('fluo.agendauno.mx', '/panel'))->toBe(204);

    publicarNegocioParaSeo('fluo');
    expect($existe('fluo.agendauno.mx', '/'))->toBe(204)
        ->and($existe('fluo.agendauno.mx', '/enlaces?utm=ig'))->toBe(204)
        ->and($existe('fluo.agendauno.mx', '/estudio/fluo/p/no-existe'))->toBe(403)
        // Un negocio que no existe, o en el dominio del otro producto.
        ->and($existe('no-existe.agendauno.mx', '/'))->toBe(403)
        ->and($existe('fluo.turnouno.mx', '/'))->toBe(403)
        // Otro host no es asunto de esta página.
        ->and($existe('localhost', '/lo-que-sea'))->toBe(204);
});

it('el dominio de la marca lista los sitemaps de sus negocios del directorio', function (): void {
    estudioConSesion('fluo', 'f@correo.mx');
    publicarNegocioParaSeo('fluo');
    estudioConSesion('privado', 'p@correo.mx');
    publicarNegocioParaSeo('privado', privado: true);
    estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    publicarNegocioParaSeo('barberia-norte');

    $xml = $this->get('http://agendauno.mx/api/v1/pagina-negocio/negocios.xml')
        ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($xml)
        ->toContain('<loc>https://fluo.agendauno.mx/sitemap.xml</loc>')
        // Ni el «solo con enlace» ni el de la otra marca.
        ->not->toContain('privado.agendauno.mx')
        ->not->toContain('barberia-norte');
    expect($this->get('http://turnouno.mx/api/v1/pagina-negocio/negocios.xml')->assertOk()->getContent())
        ->toContain('<loc>https://barberia-norte.turnouno.mx/sitemap.xml</loc>');
    // Desde un subdominio u otro host, no.
    $this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/negocios.xml')->assertNotFound();
});
