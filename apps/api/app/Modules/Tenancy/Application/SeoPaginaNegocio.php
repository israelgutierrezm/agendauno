<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\CatalogoPaises;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use App\Modules\Tenancy\Support\RedesSociales;

/**
 * La página pública de un negocio en su subdominio, para quien no ejecuta JavaScript
 * (buscadores, vistas previas al compartir por WhatsApp o redes; ADR 0128):
 * - la cabecera HTML con que llega (título, descripción, imagen, canonical y datos
 *   estructurados), que nginx pide con SSI al servir la aplicación;
 * - su sitemap y su robots.
 *
 * Los textos son los que la web pone después en cada vista (`updateSeo`), con las mismas
 * etiquetas para que ella las reemplace en lugar de duplicarlas. Se indexa solo si el
 * negocio aparece en el directorio: «solo con enlace» (`privado`) se ve, pero no se
 * indexa. Solo lee el negocio del control plane: no abre su base de datos.
 *
 * Su sitio (ADR 0146): el SEO de Inicio y de cada página propia (`/estudio/{slug}/p/…`)
 * sale de lo que publicó, que se copia al negocio al publicar (`seo_sitio`).
 */
final class SeoPaginaNegocio
{
    /**
     * El tipo de schema.org de cada giro (lo más preciso que existe); los demás,
     * LocalBusiness.
     */
    private const TIPO_POR_GIRO = [
        'gimnasio' => 'ExerciseGym',
        'crossfit' => 'ExerciseGym',
        'hyrox' => 'ExerciseGym',
        'pilates' => 'SportsActivityLocation',
        'pole' => 'SportsActivityLocation',
        'yoga' => 'SportsActivityLocation',
        'danza' => 'SportsActivityLocation',
        'natacion' => 'SportsActivityLocation',
        'barberia' => 'HairSalon',
        'salon' => 'BeautySalon',
        'estetica' => 'BeautySalon',
        'spa' => 'DaySpa',
        'salud' => 'MedicalBusiness',
    ];

    /** Los días del horario (1 = lunes) como los nombra schema.org. */
    private const DIAS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * Imagen para compartir cuando el negocio no tiene portada ni logo: la de la marca
     * (la misma que `DEFAULT_IMAGE` de la web), con su texto alternativo.
     */
    private const IMAGEN_DE_MARCA = [
        'agendauno' => ['/assets/brand/agendauno/final-v2/open-graph.png', 'Logotipo de AgendaUno'],
        'turnouno' => ['/assets/landing/disciplinas/barberia-v1.jpg', 'Barbero atendiendo a un cliente en una barbería moderna'],
    ];

    /**
     * El negocio de un subdominio (`{slug}.agendauno.mx`), solo en el dominio de su
     * producto y con su página pública abierta. Null para cualquier otro host.
     */
    public function negocioDelHost(string $host): ?Estudio
    {
        $estudio = $this->delSubdominio($host);

        return $estudio !== false && $estudio !== null && $estudio->paginaPublica() ? $estudio : null;
    }

    /**
     * El negocio de un subdominio de un producto, abierto o no. False si el host no es
     * un subdominio de ningún producto (otro host: no es asunto de esta página); null si
     * lo es, pero de ningún negocio de ese producto.
     */
    private function delSubdominio(string $host): Estudio|false|null
    {
        $host = strtolower(trim($host, '.'));
        foreach (ProductoComercial::cases() as $producto) {
            $dominio = $producto->dominio();
            if ($dominio === '' || preg_match('/^([a-z0-9-]+)\.'.preg_quote($dominio, '/').'$/', $host, $partes) !== 1) {
                continue;
            }
            $estudio = Estudio::query()->where('slug', $partes[1])->first();

            return $estudio instanceof Estudio && $estudio->producto() === $producto ? $estudio : null;
        }

        return false;
    }

    /**
     * ¿Existe esa dirección? Para que nginx responda 404 de verdad (con la misma
     * aplicación) en lugar de 200 a todo: un subdominio sin negocio; la página pública
     * de un negocio que no la tiene abierta; una página propia de su sitio que no
     * existe. Lo demás (entrar, su panel, otro host) sí existe: lo resuelve la web.
     */
    public function existe(string $host, string $direccion): bool
    {
        $estudio = $this->delSubdominio($host);
        if ($estudio === false) {
            return true;
        }
        if ($estudio === null) {
            return false;
        }
        $pagina = $this->cualPagina($estudio, $direccion);
        if ($pagina === null) {
            return true;
        }
        if (! $estudio->paginaPublica()) {
            return false;
        }

        return $pagina['pagina'] !== 'propia'
            || $this->paginaPropia($estudio, is_array($estudio->seo_sitio) ? $estudio->seo_sitio : [], (string) $pagina['propia']) !== null;
    }

    /**
     * Qué página pública es una dirección del subdominio (con o sin query), o null si
     * no es una: su panel, entrar, una vista previa…
     *
     * @return array{pagina: string, propia: string|null}|null
     */
    public function cualPagina(Estudio $estudio, string $direccion): ?array
    {
        $camino = '/'.trim(strtolower(rawurldecode((string) preg_replace('/[?#].*$/s', '', $direccion))), '/');
        $slug = $estudio->slug;
        $citas = $estudio->modalidad() === ModalidadServicio::Citas;
        $pagina = match (true) {
            in_array($camino, ['/', "/{$slug}", "/estudio/{$slug}"], true) => 'inicio',
            in_array($camino, ['/enlaces', "/{$slug}/enlaces"], true) => 'enlaces',
            $camino === "/sucursales/{$slug}" => 'sucursales',
            $citas && $camino === "/agendar/{$slug}" => 'agendar',
            str_starts_with($camino, "/estudio/{$slug}/p/") => 'propia',
            default => null,
        };

        return $pagina === null ? null : [
            'pagina' => $pagina,
            'propia' => $pagina === 'propia' ? substr($camino, strlen("/estudio/{$slug}/p/")) : null,
        ];
    }

    /**
     * Lo que va en la cabecera de una dirección del subdominio (con o sin query). Null si
     * no es una página pública del negocio: su panel, entrar, una vista previa…
     *
     * Con los datos públicos del negocio (`DatosPublicosNegocio`), los datos estructurados
     * de Inicio van completos: su tipo, dirección, teléfono y horario por sucursal.
     *
     * @param  array<string, mixed>|null  $datos
     * @return array{titulo: string, descripcion: string, ruta: string, imagen: string|null, tipo: string, indexar: bool, json_ld: array<string, mixed>|null}|null
     */
    public function paraRuta(Estudio $estudio, string $direccion, ?array $datos = null): ?array
    {
        $cual = $this->cualPagina($estudio, $direccion);
        if ($cual === null) {
            return null;
        }
        $pagina = $cual['pagina'];
        $slug = $estudio->slug;
        $citas = $estudio->modalidad() === ModalidadServicio::Citas;
        $seo = is_array($estudio->seo_sitio) ? $estudio->seo_sitio : [];
        if ($pagina === 'propia') {
            return $this->paginaPropia($estudio, $seo, (string) $cual['propia']);
        }

        $nombre = (string) $estudio->nombre;
        $marca = $estudio->producto()->nombre();
        $lugar = implode(', ', array_filter([trim((string) $estudio->ciudad), CatalogoPaises::codigo($estudio->pais)]));
        $enLugar = $lugar !== '' ? " en {$lugar}" : '';
        $descripcionPropia = trim((string) $estudio->descripcion);
        $logo = $this->absoluta($estudio, $estudio->logo_url);

        // Lo que el negocio escribió para Inicio en su sitio, si lo hizo.
        $inicio = is_array($seo['inicio'] ?? null) ? $seo['inicio'] : [];

        return match ($pagina) {
            'inicio' => [
                'titulo' => self::propio($inicio['titulo'] ?? null)
                    ?? "{$nombre} | ".($citas ? 'Servicios y citas' : 'Horarios y clases')." en {$marca}",
                'descripcion' => self::propio($inicio['descripcion'] ?? null) ?? ($citas
                    ? "Consulta servicios, profesionales y horarios disponibles de {$nombre}{$enLugar}. Reserva tu cita en línea."
                    : "Consulta próximas clases, instructores y precios de {$nombre}{$enLugar}."),
                'ruta' => '/',
                'imagen' => $this->absoluta($estudio, self::propio($inicio['imagen_url'] ?? null))
                    ?? $this->absoluta($estudio, $estudio->portada_url) ?? $logo,
                'tipo' => 'profile',
                'indexar' => $estudio->enDirectorio(),
                'json_ld' => $this->datosEstructurados($estudio, $datos),
            ],
            'enlaces' => [
                'titulo' => "{$nombre} | Enlaces",
                'descripcion' => $descripcionPropia !== '' ? $descripcionPropia : "Enlaces de {$nombre}.",
                'ruta' => '/enlaces',
                'imagen' => $logo,
                'tipo' => 'profile',
                'indexar' => $estudio->enDirectorio(),
                'json_ld' => null,
            ],
            'sucursales' => [
                'titulo' => "{$nombre} | Sucursales en {$marca}",
                'descripcion' => "Elige la sucursal de {$nombre} y agenda en línea.",
                'ruta' => "/sucursales/{$slug}",
                'imagen' => $logo,
                'tipo' => 'website',
                'indexar' => $estudio->enDirectorio(),
                'json_ld' => null,
            ],
            default => [
                'titulo' => "Agenda en {$nombre} | {$marca}",
                'descripcion' => "Elige servicio, profesional y horario, y agenda tu cita en {$nombre}.",
                'ruta' => "/agendar/{$slug}",
                'imagen' => $logo,
                'tipo' => 'website',
                'indexar' => $estudio->enDirectorio(),
                'json_ld' => null,
            ],
        };
    }

    /**
     * Una página propia de su sitio publicado: su título y descripción (los suyos o, sin
     * ellos, su título con el nombre del negocio) y su imagen. Una que no existe, nada.
     *
     * @param  array<string, mixed>  $seo
     * @return array{titulo: string, descripcion: string, ruta: string, imagen: string|null, tipo: string, indexar: bool, json_ld: array<string, mixed>|null}|null
     */
    private function paginaPropia(Estudio $estudio, array $seo, string $direccion): ?array
    {
        foreach ((array) ($seo['paginas'] ?? []) as $p) {
            if (! is_array($p) || ($p['slug'] ?? null) !== $direccion) {
                continue;
            }
            $nombre = (string) $estudio->nombre;
            $titulo = (string) ($p['titulo'] ?? '');

            return [
                'titulo' => self::propio($p['seo_titulo'] ?? null) ?? "{$titulo} | {$nombre}",
                'descripcion' => self::propio($p['descripcion'] ?? null) ?? "{$titulo} de {$nombre}.",
                'ruta' => "/estudio/{$estudio->slug}/p/{$direccion}",
                'imagen' => $this->absoluta($estudio, self::propio($p['imagen_url'] ?? null))
                    ?? $this->absoluta($estudio, $estudio->portada_url)
                    ?? $this->absoluta($estudio, $estudio->logo_url),
                'tipo' => 'website',
                'indexar' => $estudio->enDirectorio(),
                'json_ld' => null,
            ];
        }

        return null;
    }

    /**
     * Los datos estructurados de su página (schema.org): el tipo de su giro, y con sus
     * datos públicos, la dirección, el teléfono y el horario de cada sucursal (con una,
     * en el negocio; con varias, cada una como `department`). Los mismos que pone la
     * web (el escaparate se los da), para que no se contradigan.
     *
     * @param  array<string, mixed>|null  $datos  los de `DatosPublicosNegocio` (o null)
     * @return array<string, mixed>
     */
    public function datosEstructurados(Estudio $estudio, ?array $datos = null): array
    {
        $tipo = self::TIPO_POR_GIRO[$estudio->perfil_negocio->value] ?? 'LocalBusiness';
        $ciudad = trim((string) $estudio->ciudad);
        $pais = CatalogoPaises::codigo($estudio->pais);
        $lugar = implode(', ', array_filter([$ciudad, $pais]));
        $descripcion = trim((string) $estudio->descripcion);
        $sucursales = array_values(array_filter((array) ($datos['sucursales'] ?? []), 'is_array'));

        $base = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $tipo,
            'name' => (string) $estudio->nombre,
            'url' => $this->urlBase($estudio).'/',
            'image' => $this->absoluta($estudio, $estudio->logo_url),
            'description' => $descripcion !== '' ? $descripcion : null,
            'sameAs' => array_column(RedesSociales::publicas($estudio->redes), 'url'),
        ], fn (mixed $valor): bool => $valor !== null);

        $deSucursal = function (array $s) use ($ciudad, $pais): array {
            $direccion = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => self::propio($s['direccion'] ?? null),
                'addressLocality' => $ciudad !== '' ? $ciudad : null,
                'addressRegion' => self::propio($s['region'] ?? null),
                'addressCountry' => $pais,
            ], fn (mixed $valor): bool => $valor !== null);
            $horario = array_map(static fn (array $d): array => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/'.self::DIAS[(int) $d['dia']],
                'opens' => (string) $d['abre'],
                'closes' => (string) $d['cierra'],
            ], array_values(array_filter((array) ($s['horario'] ?? []), static fn (mixed $d): bool => is_array($d) && isset(self::DIAS[(int) ($d['dia'] ?? 0)]))));

            return array_filter([
                'address' => count($direccion) > 2 ? $direccion : null,
                'telephone' => self::propio($s['telefono'] ?? null),
                'openingHoursSpecification' => $horario !== [] ? $horario : null,
            ], fn (mixed $valor): bool => $valor !== null);
        };

        if (count($sucursales) === 1) {
            return [...$base, ...($deSucursal($sucursales[0]) ?: ($lugar !== '' ? ['address' => $lugar] : []))];
        }
        if (count($sucursales) > 1) {
            return [
                ...$base,
                ...($lugar !== '' ? ['address' => $lugar] : []),
                'department' => array_map(fn (array $s): array => [
                    '@type' => $tipo,
                    'name' => trim((string) $estudio->nombre.' · '.(string) ($s['nombre'] ?? '')),
                    ...$deSucursal($s),
                ], $sucursales),
            ];
        }

        return [...$base, ...($lugar !== '' ? ['address' => $lugar] : [])];
    }

    /** Un texto propio con algo escrito, o null. */
    private static function propio(mixed $texto): ?string
    {
        return is_string($texto) && trim($texto) !== '' ? trim($texto) : null;
    }

    /**
     * Las etiquetas de la cabecera, en el formato de `renderSeoHead` de la web.
     *
     * @param  array{titulo: string, descripcion: string, ruta: string, imagen: string|null, tipo: string, indexar: bool, json_ld: array<string, mixed>|null}  $pagina
     */
    public function html(Estudio $estudio, array $pagina): string
    {
        $producto = $estudio->producto();
        [$imagenMarca, $altMarca] = self::IMAGEN_DE_MARCA[$producto->value];
        $imagen = $pagina['imagen'] ?? 'https://'.$producto->dominio().$imagenMarca;
        $alt = $pagina['imagen'] !== null ? (string) $estudio->nombre : $altMarca;
        $e = fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $etiquetas = [
            "<title>{$e($pagina['titulo'])}</title>",
            "<meta name=\"description\" content=\"{$e($pagina['descripcion'])}\">",
            '<meta name="robots" content="'.($pagina['indexar'] ? 'index,follow,max-image-preview:large' : 'noindex,follow').'">',
            '<meta property="og:locale" content="es_MX">',
            "<meta property=\"og:site_name\" content=\"{$e($producto->nombre())}\">",
            "<meta property=\"og:type\" content=\"{$e($pagina['tipo'])}\">",
            "<meta property=\"og:title\" content=\"{$e($pagina['titulo'])}\">",
            "<meta property=\"og:description\" content=\"{$e($pagina['descripcion'])}\">",
            "<meta property=\"og:image\" content=\"{$e($imagen)}\">",
            "<meta property=\"og:image:alt\" content=\"{$e($alt)}\">",
            '<meta name="twitter:card" content="summary_large_image">',
            "<meta name=\"twitter:title\" content=\"{$e($pagina['titulo'])}\">",
            "<meta name=\"twitter:description\" content=\"{$e($pagina['descripcion'])}\">",
            "<meta name=\"twitter:image\" content=\"{$e($imagen)}\">",
            "<meta name=\"twitter:image:alt\" content=\"{$e($alt)}\">",
        ];
        if ($pagina['indexar']) {
            $url = $e($this->urlBase($estudio).$pagina['ruta']);
            $etiquetas[] = "<link rel=\"canonical\" href=\"{$url}\">";
            $etiquetas[] = "<meta property=\"og:url\" content=\"{$url}\">";
        }
        if ($pagina['json_ld'] !== null) {
            $json = json_encode($pagina['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
            $etiquetas[] = "<script id=\"agendauno-route-jsonld\" type=\"application/ld+json\">{$json}</script>";
        }

        return implode("\n", $etiquetas)."\n";
    }

    /** Su sitemap: solo si se indexa (aparece en el directorio). */
    public function sitemap(Estudio $estudio): ?string
    {
        if (! $estudio->enDirectorio()) {
            return null;
        }
        $base = $this->urlBase($estudio);
        $rutas = ['/', '/enlaces'];
        if ($estudio->modalidad() === ModalidadServicio::Citas) {
            $rutas[] = "/agendar/{$estudio->slug}";
        }
        // Las páginas propias de su sitio publicado (ADR 0146).
        foreach ((array) (is_array($estudio->seo_sitio) ? ($estudio->seo_sitio['paginas'] ?? []) : []) as $p) {
            if (is_array($p) && is_string($p['slug'] ?? null) && preg_match('/^[a-z0-9-]+$/', $p['slug']) === 1) {
                $rutas[] = "/estudio/{$estudio->slug}/p/{$p['slug']}";
            }
        }
        $modificado = $estudio->updated_at?->toDateString();

        $urls = array_map(fn (string $ruta): string => '  <url><loc>'.htmlspecialchars($base.$ruta, ENT_XML1, 'UTF-8').'</loc>'
            .($modificado !== null ? "<lastmod>{$modificado}</lastmod>" : '').'</url>', $rutas);

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .implode("\n", $urls)
            ."\n</urlset>\n";
    }

    /**
     * El índice de los sitemaps de los negocios de una marca, en su dominio principal
     * (`agendauno.mx/sitemap-negocios.xml`; ADR 0155): uno por negocio que aparece en el
     * directorio, en su subdominio. Null si el host no es el dominio de una marca. (Que
     * un buscador lea sitemaps de otros subdominios desde aquí pide verificar el
     * dominio completo en su consola; cada subdominio igual anuncia el suyo en su robots.)
     */
    public function indiceDeNegocios(string $host): ?string
    {
        $host = strtolower(trim($host, '.'));
        $producto = null;
        foreach (ProductoComercial::cases() as $p) {
            if ($p->dominio() !== '' && $p->dominio() === $host) {
                $producto = $p;
            }
        }
        if ($producto === null) {
            return null;
        }
        $sitemaps = Estudio::query()->where('publicado', true)->where('privado', false)->orderBy('slug')->get()
            ->filter(static fn (Estudio $e): bool => $e->enDirectorio() && $e->producto() === $producto)
            ->map(fn (Estudio $e): string => '  <sitemap><loc>'.htmlspecialchars($this->urlBase($e).'/sitemap.xml', ENT_XML1, 'UTF-8').'</loc>'
                .($e->updated_at !== null ? '<lastmod>'.$e->updated_at->toDateString().'</lastmod>' : '').'</sitemap>')
            ->all();

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .implode("\n", $sitemaps).($sitemaps !== [] ? "\n" : '')
            ."</sitemapindex>\n";
    }

    /**
     * Su robots: todo se puede rastrear (lo que no es página pública ya dice `noindex`);
     * con su sitemap si se indexa.
     */
    public function robots(?Estudio $estudio): string
    {
        $robots = "User-agent: *\nAllow: /\n";

        return $estudio !== null && $estudio->enDirectorio()
            ? $robots."\nSitemap: {$this->urlBase($estudio)}/sitemap.xml\n"
            : $robots;
    }

    /** `https://{slug}.{dominio de su producto}`, sin diagonal final. */
    public function urlBase(Estudio $estudio): string
    {
        return "https://{$estudio->slug}.{$estudio->producto()->dominio()}";
    }

    /** Una imagen guardada como ruta del sitio (`/storage/...`) se vuelve absoluta. */
    private function absoluta(Estudio $estudio, ?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $this->urlBase($estudio).$url : $url;
    }
}
