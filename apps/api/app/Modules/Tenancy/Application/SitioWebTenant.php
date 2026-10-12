<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\BorradorSitioWebTenant;
use App\Modules\Tenancy\Models\PublicacionSitioWebTenant;
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
 * Sin nada publicado, el público ve la plantilla esencial con todo visible (la página
 * de siempre). Las imágenes viven en `estudios/{id}/sitio/` del disco público; al
 * guardar y publicar se borran las que ya nadie usa.
 */
class SitioWebTenant
{
    /** Versiones publicadas cuyas imágenes se conservan. */
    private const PUBLICACIONES_CON_IMAGENES = 10;

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

        return ['plantilla' => $plantilla->value, 'secciones' => $secciones, 'banners' => []];
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

        return [
            'plantilla' => $plantilla->value,
            'secciones' => [
                $porTipo[CatalogoSitioWeb::INICIO] ?? self::seccion(CatalogoSitioWeb::INICIO),
                ...$movibles,
                $porTipo[CatalogoSitioWeb::CONTACTO] ?? self::seccion(CatalogoSitioWeb::CONTACTO),
            ],
            'banners' => $banners,
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
        $secciones = array_values(array_filter(
            (array) $contenido['secciones'],
            static fn (array $s): bool => ($s['visible'] || in_array($s['tipo'], [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true))
                && self::tieneContenido($s),
        ));
        $banners = array_values(array_filter(
            (array) $contenido['banners'],
            static fn (array $b): bool => ($b['desde'] === null || $b['desde'] <= $hoy) && ($b['hasta'] === null || $b['hasta'] >= $hoy),
        ));

        return [
            'plantilla' => $contenido['plantilla'],
            'secciones' => array_map(static function (array $s): array {
                unset($s['visible']);
                // Del video, solo con qué se arma el reproductor.
                if ($s['tipo'] === 'video') {
                    unset($s['datos']['url']);
                }

                return $s;
            }, $secciones),
            'banners' => array_map(static function (array $b): array {
                unset($b['desde'], $b['hasta']);

                return $b;
            }, $banners),
        ];
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
            foreach ([...(array) ($contenido['secciones'] ?? []), ...(array) ($contenido['banners'] ?? [])] as $parte) {
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
