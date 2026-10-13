<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\BorradorSitioWebTenant;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\Models\PublicacionSitioWebTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\SitioWeb\CatalogoSitioWeb;
use App\Modules\Tenancy\SitioWeb\PlantillaSitio;
use App\Modules\Tenancy\SitioWeb\VideoSitio;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El sitio público del negocio (ADR 0114): el borrador que edita y la versión que
 * publicó. El contenido es una configuración con forma fija (validada al guardar):
 *
 *   plantilla: esencial | portada | compacta
 *   secciones: [{tipo, visible, titulo, texto, foto_url}] — inicio primero, contacto al
 *              final, cada tipo una vez y solo los de su modalidad
 *   banners:   [{id, titulo, texto, enlace_texto, enlace_url, desde, hasta, foto_url}]
 *
 * Entre las secciones van los bloques propios del negocio (ADR 0146), las veces que
 * quiera: {id, tipo, visible, titulo, texto, foto_url, estilo: {fondo, alineacion},
 * datos}. Sus `datos` tienen forma fija según el tipo (fotos, preguntas, testimonios,
 * beneficios, botón, video o formulario).
 *
 * Varias páginas (ADR 0146, fase 2): Inicio es la de arriba; además, `paginas`
 * [{id, slug, titulo, en_menu, seo, secciones}] con secciones del sistema (una vez por
 * página) y bloques propios. `seo` es el de Inicio y `pie`, el texto del pie de página.
 *
 * Sin nada publicado, el público ve la plantilla esencial con todo visible (la página
 * de siempre). Las imágenes viven en `estudios/{id}/sitio/` del disco público; al
 * guardar y publicar se borran las que ya nadie usa.
 */
class SitioWebTenant
{
    /** Versiones publicadas cuyas imágenes se conservan (a las que se puede volver). */
    private const PUBLICACIONES_CON_IMAGENES = CatalogoSitioWeb::VERSIONES;

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    /**
     * La configuración de una plantilla, sin textos ni banners.
     *
     * @return array<string, mixed>
     */
    public function porDefecto(ModalidadServicio $modalidad, PlantillaSitio $plantilla = PlantillaSitio::Esencial): array
    {
        $secciones = array_map(
            static fn (string $tipo): array => self::seccion($tipo),
            [CatalogoSitioWeb::INICIO, ...$plantilla->orden($modalidad), CatalogoSitioWeb::CONTACTO],
        );

        return [
            'plantilla' => $plantilla->value,
            'secciones' => $secciones,
            'banners' => [],
            'seo' => self::seo([]),
            'paginas' => [],
            'pie' => ['texto' => null],
            'estilo' => self::estilo([]),
            'encabezado' => self::encabezado([]),
        ];
    }

    /**
     * Ordena y completa un contenido ya validado: inicio primero y contacto al final, cada
     * tipo una vez, solo los de la modalidad (un tipo que falte se agrega visible al
     * final, en el orden de la plantilla), texto y foto solo donde caben, y cada banner
     * con su id.
     *
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public function normalizar(array $contenido, ModalidadServicio $modalidad): array
    {
        $plantilla = PlantillaSitio::tryFrom((string) ($contenido['plantilla'] ?? '')) ?? PlantillaSitio::Esencial;
        $permitidos = CatalogoSitioWeb::movibles($modalidad);

        // Las del sistema, una vez cada una; los bloques propios, los que haya, cada uno
        // con su id (uno repetido, al duplicar, recibe otro).
        $porTipo = [];
        $movibles = [];
        $ids = [];
        foreach ((array) ($contenido['secciones'] ?? []) as $seccion) {
            $tipo = is_array($seccion) ? (string) ($seccion['tipo'] ?? '') : '';
            if (is_array($seccion) && CatalogoSitioWeb::esBloque($tipo)) {
                $bloque = self::bloque($tipo, $seccion);
                if (isset($ids[$bloque['id']])) {
                    $bloque['id'] = self::idNuevo();
                }
                $ids[$bloque['id']] = true;
                $movibles[] = $bloque;

                continue;
            }
            if ($tipo !== '' && ! isset($porTipo[$tipo])
                && ($tipo === CatalogoSitioWeb::INICIO || $tipo === CatalogoSitioWeb::CONTACTO || in_array($tipo, $permitidos, true))) {
                $porTipo[$tipo] = self::seccion($tipo, (array) $seccion);
                if (! in_array($tipo, [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true)) {
                    $movibles[] = $porTipo[$tipo];
                }
            }
        }
        foreach ($plantilla->orden($modalidad) as $tipo) {
            if (! isset($porTipo[$tipo])) {
                $movibles[] = self::seccion($tipo);
            }
        }

        $banners = [];
        foreach ((array) ($contenido['banners'] ?? []) as $banner) {
            if (is_array($banner)) {
                $banners[] = self::banner($banner);
            }
        }

        // Las páginas propias, cada una con su dirección única.
        $paginas = [];
        $direcciones = [];
        foreach ((array) ($contenido['paginas'] ?? []) as $pagina) {
            if (is_array($pagina)) {
                $paginas[] = self::pagina($pagina, $permitidos, $ids, $direcciones);
            }
        }
        $pie = is_array($contenido['pie'] ?? null) ? $contenido['pie'] : [];

        return [
            'plantilla' => $plantilla->value,
            'secciones' => [
                $porTipo[CatalogoSitioWeb::INICIO] ?? self::seccion(CatalogoSitioWeb::INICIO),
                ...$movibles,
                $porTipo[CatalogoSitioWeb::CONTACTO] ?? self::seccion(CatalogoSitioWeb::CONTACTO),
            ],
            'banners' => $banners,
            'seo' => self::seo(is_array($contenido['seo'] ?? null) ? $contenido['seo'] : []),
            'paginas' => $paginas,
            'pie' => ['texto' => self::texto($pie['texto'] ?? null)],
            'estilo' => self::estilo(is_array($contenido['estilo'] ?? null) ? $contenido['estilo'] : []),
            'encabezado' => self::encabezado(is_array($contenido['encabezado'] ?? null) ? $contenido['encabezado'] : []),
        ];
    }

    /**
     * Las últimas versiones publicadas, de la más reciente a la más antigua: cuándo y
     * quién. La primera es la que ve el público.
     *
     * @return list<array{id: string, publicada_en: string|null, publicada_por: string|null, actual: bool}>
     */
    public function versiones(): array
    {
        $versiones = PublicacionSitioWebTenant::query()->orderByDesc('id')->limit(CatalogoSitioWeb::VERSIONES)->get();
        $nombres = Usuario::query()->whereIn('id', $versiones->pluck('publicada_por_usuario_id')->filter()->all())->pluck('name', 'id');

        return array_values($versiones->values()->map(static fn (PublicacionSitioWebTenant $v, int $i): array => [
            'id' => (string) $v->ulid,
            'publicada_en' => $v->created_at?->toIso8601String(),
            'publicada_por' => $v->publicada_por_usuario_id !== null ? ($nombres[$v->publicada_por_usuario_id] ?? null) : null,
            'actual' => $i === 0,
        ])->all());
    }

    /**
     * Vuelve el borrador a una versión publicada (de las últimas, cuyas fotos siguen):
     * no se publica hasta que el negocio publica.
     *
     * @return array<string, mixed>
     */
    public function restaurar(string $version, ModalidadServicio $modalidad, ?int $usuarioId, int $estudioId): array
    {
        $ids = PublicacionSitioWebTenant::query()->orderByDesc('id')->limit(CatalogoSitioWeb::VERSIONES)->pluck('id');
        $publicacion = PublicacionSitioWebTenant::query()->whereIn('id', $ids)->where('ulid', $version)->firstOrFail();

        return $this->guardarBorrador((array) $publicacion->contenido, $modalidad, $usuarioId, $estudioId);
    }

    /**
     * El SEO publicado de su sitio, para la cabecera del servidor y el sitemap (se copia
     * al negocio en el control plane al publicar).
     *
     * @param  array<string, mixed>  $contenido  normalizado
     * @return array{inicio: array{titulo: string|null, descripcion: string|null, imagen_url: string|null}, paginas: list<array{slug: string, titulo: string, seo_titulo: string|null, descripcion: string|null, imagen_url: string|null}>}
     */
    public function resumenSeo(array $contenido): array
    {
        $seo = self::seo((array) ($contenido['seo'] ?? []));

        return [
            'inicio' => $seo,
            'paginas' => array_values(array_map(static fn (array $p): array => [
                'slug' => (string) $p['slug'],
                'titulo' => (string) $p['titulo'],
                'seo_titulo' => $p['seo']['titulo'] ?? null,
                'descripcion' => $p['seo']['descripcion'] ?? null,
                'imagen_url' => $p['seo']['imagen_url'] ?? null,
            ], (array) ($contenido['paginas'] ?? []))),
        ];
    }

    /**
     * Lo que se edita: el borrador guardado o, sin él, lo publicado (o la plantilla).
     *
     * @return array<string, mixed>
     */
    public function borrador(ModalidadServicio $modalidad): array
    {
        $guardado = BorradorSitioWebTenant::query()->first()?->borrador;

        return is_array($guardado) ? $this->normalizar($guardado, $modalidad) : $this->publicado($modalidad);
    }

    /**
     * Lo que ve el público: la última versión publicada o, sin ninguna, la plantilla.
     *
     * @return array<string, mixed>
     */
    public function publicado(ModalidadServicio $modalidad): array
    {
        $ultima = $this->ultimaPublicacion();

        return $ultima !== null ? $this->normalizar($ultima->contenido, $modalidad) : $this->porDefecto($modalidad);
    }

    public function ultimaPublicacion(): ?PublicacionSitioWebTenant
    {
        return PublicacionSitioWebTenant::query()->orderByDesc('id')->first();
    }

    /** ¿El borrador tiene cambios sin publicar? */
    public function hayCambios(ModalidadServicio $modalidad): bool
    {
        return $this->borrador($modalidad) !== $this->publicado($modalidad);
    }

    /**
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public function guardarBorrador(array $contenido, ModalidadServicio $modalidad, ?int $usuarioId, int $estudioId): array
    {
        $normalizado = $this->normalizar($contenido, $modalidad);
        BorradorSitioWebTenant::query()->updateOrCreate([], [
            'borrador' => $normalizado,
            'actualizado_por_usuario_id' => $usuarioId,
        ]);
        $this->borrarImagenesSinUso($estudioId);

        return $normalizado;
    }

    /** Publica el borrador como una versión nueva: desde ahora es lo que ve el público. */
    public function publicar(ModalidadServicio $modalidad, ?int $usuarioId, int $estudioId): PublicacionSitioWebTenant
    {
        $publicacion = PublicacionSitioWebTenant::query()->create([
            'contenido' => $this->borrador($modalidad),
            'publicada_por_usuario_id' => $usuarioId,
        ]);
        BorradorSitioWebTenant::query()->update(['borrador' => null]);
        $this->borrarImagenesSinUso($estudioId);

        return $publicacion;
    }

    /** Descarta el borrador: se vuelve a lo publicado. */
    public function descartar(int $estudioId): void
    {
        BorradorSitioWebTenant::query()->update(['borrador' => null]);
        $this->borrarImagenesSinUso($estudioId);
    }

    /**
     * Para la página pública: solo las secciones visibles (la portada y el contacto
     * siempre), los bloques que tienen algo que mostrar y los banners vigentes hoy en
     * el negocio.
     *
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public function paraPublico(array $contenido): array
    {
        $hoy = $this->fechas->hoy();
        $banners = array_values(array_filter(
            (array) $contenido['banners'],
            static fn (array $b): bool => ($b['desde'] === null || $b['desde'] <= $hoy) && ($b['hasta'] === null || $b['hasta'] >= $hoy),
        ));

        // Un bloque de formulario solo sale si su formulario sigue encendido (si no, en
        // la página habría un formulario que no recibe nada).
        $formularios = FormularioCaptacionTenant::query()->where('activo', true)->pluck('ulid')->all();

        return [
            'plantilla' => $contenido['plantilla'],
            'secciones' => self::seccionesPublicas((array) $contenido['secciones'], $formularios),
            'seo' => $contenido['seo'] ?? self::seo([]),
            'pie' => $contenido['pie'] ?? ['texto' => null],
            'estilo' => $contenido['estilo'] ?? self::estilo([]),
            'encabezado' => $contenido['encabezado'] ?? self::encabezado([]),
            'paginas' => array_values(array_map(static fn (array $p): array => [
                ...$p,
                'secciones' => self::seccionesPublicas((array) $p['secciones'], $formularios),
            ], (array) ($contenido['paginas'] ?? []))),
            'banners' => array_map(static function (array $b): array {
                unset($b['desde'], $b['hasta']);

                return $b;
            }, $banners),
        ];
    }

    /**
     * Solo las visibles (la portada y el contacto siempre) que tienen algo que mostrar,
     * sin la marca de visible. Del video, solo con qué se arma el reproductor; de un
     * formulario, solo si sigue encendido.
     *
     * @param  array<int, array<string, mixed>>  $secciones
     * @param  list<string>  $formulariosActivos
     * @return list<array<string, mixed>>
     */
    private static function seccionesPublicas(array $secciones, array $formulariosActivos): array
    {
        $visibles = array_filter(
            $secciones,
            static fn (array $s): bool => ($s['visible'] || in_array($s['tipo'], [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true))
                && self::tieneContenido($s)
                && ($s['tipo'] !== 'formulario' || in_array((string) ($s['datos']['formulario_id'] ?? ''), $formulariosActivos, true)),
        );

        return array_values(array_map(static function (array $s): array {
            unset($s['visible']);
            if ($s['tipo'] === 'video') {
                unset($s['datos']['url']);
            }

            return $s;
        }, $visibles));
    }

    /** Carpeta de las imágenes del sitio de un negocio en el disco público. */
    public static function carpetaImagenes(int $estudioId): string
    {
        return 'estudios/'.$estudioId.'/sitio';
    }

    /** ¿Es la URL de una imagen subida para el sitio de este negocio? */
    public static function esImagenDelNegocio(string $url, int $estudioId): bool
    {
        $prefijo = Storage::disk('public')->url(self::carpetaImagenes($estudioId).'/');

        return str_starts_with($url, $prefijo)
            && preg_match('/^[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)$/', substr($url, strlen($prefijo))) === 1;
    }

    /**
     * Borra las imágenes del sitio que no usa el borrador ni las últimas versiones
     * publicadas (las que se subieron y luego se quitaron).
     */
    private function borrarImagenesSinUso(int $estudioId): void
    {
        $usadas = [];
        $contenidos = [
            BorradorSitioWebTenant::query()->first()?->borrador,
            ...PublicacionSitioWebTenant::query()->orderByDesc('id')->limit(self::PUBLICACIONES_CON_IMAGENES)->pluck('contenido')->all(),
        ];
        foreach ($contenidos as $contenido) {
            $contenido = is_string($contenido) ? json_decode($contenido, true) : $contenido;
            if (! is_array($contenido)) {
                continue;
            }
            // Las de Inicio y de cada página, sus banners y las imágenes para compartir.
            $paginas = array_filter((array) ($contenido['paginas'] ?? []), 'is_array');
            $partes = [
                ...(array) ($contenido['secciones'] ?? []),
                ...(array) ($contenido['banners'] ?? []),
                ...array_merge([], ...array_map(static fn (array $p): array => (array) ($p['secciones'] ?? []), array_values($paginas))),
            ];
            foreach ([$contenido['seo'] ?? null, ...array_map(static fn (array $p): mixed => $p['seo'] ?? null, $paginas)] as $seo) {
                if (is_array($seo) && is_string($seo['imagen_url'] ?? null)) {
                    $usadas[basename($seo['imagen_url'])] = true;
                }
            }
            foreach ($partes as $parte) {
                if (! is_array($parte)) {
                    continue;
                }
                // La suya y las de sus fotos (galería) o sus testimonios.
                $datos = is_array($parte['datos'] ?? null) ? $parte['datos'] : [];
                $urls = [
                    $parte['foto_url'] ?? null,
                    ...array_map(static fn (mixed $f): mixed => is_array($f) ? ($f['url'] ?? null) : null, (array) ($datos['fotos'] ?? [])),
                    ...array_map(static fn (mixed $i): mixed => is_array($i) ? ($i['foto_url'] ?? null) : null, (array) ($datos['items'] ?? [])),
                ];
                foreach ($urls as $url) {
                    if (is_string($url)) {
                        $usadas[basename($url)] = true;
                    }
                }
            }
        }

        $disco = Storage::disk('public');
        foreach ($disco->files(self::carpetaImagenes($estudioId)) as $archivo) {
            if (! isset($usadas[basename($archivo)])) {
                $disco->delete($archivo);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{tipo: string, visible: bool, titulo: string|null, texto: string|null, foto_url: string|null}
     */
    private static function seccion(string $tipo, array $datos = []): array
    {
        $fija = in_array($tipo, [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true);

        return [
            'tipo' => $tipo,
            'visible' => $fija || (bool) ($datos['visible'] ?? true),
            'titulo' => self::texto($datos['titulo'] ?? null),
            'texto' => in_array($tipo, CatalogoSitioWeb::CON_TEXTO, true) ? self::texto($datos['texto'] ?? null) : null,
            'foto_url' => in_array($tipo, CatalogoSitioWeb::CON_FOTO, true) ? self::texto($datos['foto_url'] ?? null) : null,
        ];
    }

    /**
     * Una página propia (ADR 0146, fase 2): su dirección (única; una repetida recibe un
     * número), su título, si va en el menú, su SEO y sus secciones (las del sistema, una
     * vez cada una; los bloques, los que haya).
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>  $permitidos
     * @param  array<string, bool>  $ids  los ids de bloque ya usados en el sitio
     * @param  array<string, bool>  $direcciones  las direcciones ya usadas
     * @return array<string, mixed>
     */
    private static function pagina(array $datos, array $permitidos, array &$ids, array &$direcciones): array
    {
        $id = self::texto($datos['id'] ?? null);
        $titulo = (string) (self::texto($datos['titulo'] ?? null) ?? 'Página');
        $base = Str::slug((string) (self::texto($datos['slug'] ?? null) ?? $titulo));
        $base = $base !== '' ? Str::limit($base, CatalogoSitioWeb::MAX_DIRECCION, '') : 'pagina';
        $slug = $base;
        for ($n = 2; isset($direcciones[$slug]); $n++) {
            $slug = Str::limit($base, CatalogoSitioWeb::MAX_DIRECCION - 3, '').'-'.$n;
        }
        $direcciones[$slug] = true;

        $porTipo = [];
        $secciones = [];
        foreach ((array) ($datos['secciones'] ?? []) as $seccion) {
            $tipo = is_array($seccion) ? (string) ($seccion['tipo'] ?? '') : '';
            if (is_array($seccion) && CatalogoSitioWeb::esBloque($tipo)) {
                $bloque = self::bloque($tipo, $seccion);
                if (isset($ids[$bloque['id']])) {
                    $bloque['id'] = self::idNuevo();
                }
                $ids[$bloque['id']] = true;
                $secciones[] = $bloque;
            } elseif (in_array($tipo, $permitidos, true) && ! isset($porTipo[$tipo])) {
                $porTipo[$tipo] = true;
                $secciones[] = self::seccion($tipo, (array) $seccion);
            }
        }

        return [
            'id' => $id !== null && preg_match('/^[a-z0-9]{10,26}$/', $id) === 1 ? $id : self::idNuevo(),
            'slug' => $slug,
            'titulo' => $titulo,
            'en_menu' => (bool) ($datos['en_menu'] ?? true),
            'seo' => self::seo(is_array($datos['seo'] ?? null) ? $datos['seo'] : []),
            'secciones' => $secciones,
        ];
    }

    /**
     * Cómo aparece en buscadores y al compartirse: título, descripción e imagen (vacíos:
     * los de siempre).
     *
     * @param  array<string, mixed>  $datos
     * @return array{titulo: string|null, descripcion: string|null, imagen_url: string|null}
     */
    private static function seo(array $datos): array
    {
        return [
            'titulo' => self::texto($datos['titulo'] ?? null),
            'descripcion' => self::texto($datos['descripcion'] ?? null),
            'imagen_url' => self::texto($datos['imagen_url'] ?? null),
        ];
    }

    /**
     * El estilo de todo el sitio (fase 3): su color (`#rrggbb`; null, el de su marca),
     * su tipografía, sus esquinas y si se ve claro, oscuro o como lo tenga quien lo visita.
     *
     * @param  array<string, mixed>  $datos
     * @return array{color: string|null, tipografia: string, esquinas: string, tema: string}
     */
    private static function estilo(array $datos): array
    {
        $color = self::texto($datos['color'] ?? null);

        return [
            'color' => $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null,
            'tipografia' => in_array($datos['tipografia'] ?? null, CatalogoSitioWeb::TIPOGRAFIAS, true) ? (string) $datos['tipografia'] : 'predeterminada',
            'esquinas' => in_array($datos['esquinas'] ?? null, CatalogoSitioWeb::ESQUINAS, true) ? (string) $datos['esquinas'] : 'suaves',
            'tema' => in_array($datos['tema'] ?? null, CatalogoSitioWeb::TEMAS, true) ? (string) $datos['tema'] : 'automatico',
        ];
    }

    /**
     * El encabezado del sitio: el botón principal (p. ej. «Agendar» hacia `#agenda`).
     *
     * @param  array<string, mixed>  $datos
     * @return array{boton_texto: string|null, boton_enlace: string|null}
     */
    private static function encabezado(array $datos): array
    {
        return [
            'boton_texto' => self::texto($datos['boton_texto'] ?? null),
            'boton_enlace' => self::texto($datos['boton_enlace'] ?? null),
        ];
    }

    /**
     * Un bloque propio (ADR 0146), con solo los campos de su tipo.
     *
     * @param  array<string, mixed>  $datos
     * @return array{id: string, tipo: string, visible: bool, titulo: string|null, texto: string|null, foto_url: string|null, estilo: array{fondo: string, alineacion: string}, datos: array<string, mixed>}
     */
    private static function bloque(string $tipo, array $datos): array
    {
        $id = self::texto($datos['id'] ?? null);
        $estilo = is_array($datos['estilo'] ?? null) ? $datos['estilo'] : [];

        return [
            'id' => $id !== null && preg_match('/^[a-z0-9]{10,26}$/', $id) === 1 ? $id : self::idNuevo(),
            'tipo' => $tipo,
            'visible' => (bool) ($datos['visible'] ?? true),
            'titulo' => self::texto($datos['titulo'] ?? null),
            'texto' => in_array($tipo, CatalogoSitioWeb::BLOQUES_CON_TEXTO, true) ? self::texto($datos['texto'] ?? null) : null,
            'foto_url' => in_array($tipo, CatalogoSitioWeb::BLOQUES_CON_FOTO, true) ? self::texto($datos['foto_url'] ?? null) : null,
            'estilo' => [
                'fondo' => in_array($estilo['fondo'] ?? null, CatalogoSitioWeb::FONDOS, true) ? (string) $estilo['fondo'] : 'normal',
                'alineacion' => in_array($estilo['alineacion'] ?? null, CatalogoSitioWeb::ALINEACIONES, true) ? (string) $estilo['alineacion'] : 'izquierda',
            ],
            'datos' => self::datosDe($tipo, is_array($datos['datos'] ?? null) ? $datos['datos'] : []),
        ];
    }

    /**
     * Lo propio de cada tipo de bloque; lo que viene vacío no se guarda.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function datosDe(string $tipo, array $d): array
    {
        $lista = static fn (string $clave, int $maximo): array => array_slice(
            array_values(array_filter((array) ($d[$clave] ?? []), 'is_array')),
            0,
            $maximo,
        );
        $boton = ['boton_texto' => self::texto($d['boton_texto'] ?? null), 'boton_enlace' => self::texto($d['boton_enlace'] ?? null)];

        switch ($tipo) {
            case 'texto_imagen':
                return ['lado' => ($d['lado'] ?? null) === 'derecha' ? 'derecha' : 'izquierda', ...$boton];
            case 'llamado':
                return $boton;
            case 'galeria':
                $fotos = array_map(static fn (array $f): array => [
                    'url' => self::texto($f['url'] ?? null),
                    'pie' => self::texto($f['pie'] ?? null),
                ], $lista('fotos', CatalogoSitioWeb::MAX_ELEMENTOS));

                return ['fotos' => array_values(array_filter($fotos, static fn (array $f): bool => $f['url'] !== null))];
            case 'preguntas':
                $items = array_map(static fn (array $i): array => [
                    'pregunta' => self::texto($i['pregunta'] ?? null),
                    'respuesta' => self::texto($i['respuesta'] ?? null),
                ], $lista('items', CatalogoSitioWeb::MAX_PREGUNTAS));

                return ['items' => array_values(array_filter($items, static fn (array $i): bool => $i['pregunta'] !== null))];
            case 'testimonios':
                $items = array_map(static fn (array $i): array => [
                    'nombre' => self::texto($i['nombre'] ?? null),
                    'texto' => self::texto($i['texto'] ?? null),
                    'foto_url' => self::texto($i['foto_url'] ?? null),
                ], $lista('items', CatalogoSitioWeb::MAX_ELEMENTOS));

                return ['items' => array_values(array_filter($items, static fn (array $i): bool => $i['texto'] !== null))];
            case 'beneficios':
                $items = array_map(static fn (array $i): array => [
                    'titulo' => self::texto($i['titulo'] ?? null),
                    'texto' => self::texto($i['texto'] ?? null),
                ], $lista('items', CatalogoSitioWeb::MAX_ELEMENTOS));

                return ['items' => array_values(array_filter($items, static fn (array $i): bool => $i['titulo'] !== null))];
            case 'video':
                $url = self::texto($d['url'] ?? null);
                $video = VideoSitio::desdeUrl($url);

                return ['url' => $video !== null ? $url : null, 'proveedor' => $video['proveedor'] ?? null, 'video_id' => $video['id'] ?? null];
            case 'formulario':
                return ['formulario_id' => self::texto($d['formulario_id'] ?? null)];
            default:
                return [];
        }
    }

    /**
     * ¿Tiene algo que mostrar? Las secciones del sistema, siempre; un bloque vacío (una
     * galería sin fotos, un video sin enlace) no sale en la página.
     *
     * @param  array<string, mixed>  $s
     */
    private static function tieneContenido(array $s): bool
    {
        if (! CatalogoSitioWeb::esBloque((string) $s['tipo'])) {
            return true;
        }
        $datos = (array) ($s['datos'] ?? []);

        return match ($s['tipo']) {
            'texto_imagen', 'llamado' => ($s['titulo'] ?? null) !== null || ($s['texto'] ?? null) !== null || ($s['foto_url'] ?? null) !== null,
            'galeria' => ($datos['fotos'] ?? []) !== [],
            'preguntas', 'testimonios', 'beneficios' => ($datos['items'] ?? []) !== [],
            'video' => ($datos['video_id'] ?? null) !== null,
            'formulario' => ($datos['formulario_id'] ?? null) !== null,
            default => false,
        };
    }

    private static function idNuevo(): string
    {
        return Str::lower((string) Str::ulid());
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, string|null>
     */
    private static function banner(array $datos): array
    {
        $id = self::texto($datos['id'] ?? null);

        return [
            'id' => $id !== null && preg_match('/^[A-Za-z0-9]{10,26}$/', $id) === 1 ? $id : Str::lower((string) Str::ulid()),
            'titulo' => (string) self::texto($datos['titulo'] ?? null),
            'texto' => self::texto($datos['texto'] ?? null),
            'enlace_texto' => self::texto($datos['enlace_texto'] ?? null),
            'enlace_url' => self::texto($datos['enlace_url'] ?? null),
            'desde' => self::texto($datos['desde'] ?? null),
            'hasta' => self::texto($datos['hasta'] ?? null),
            'foto_url' => self::texto($datos['foto_url'] ?? null),
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }
}
