<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\BorradorSitioWebTenant;
use App\Modules\Tenancy\Models\PublicacionSitioWebTenant;
use App\Modules\Tenancy\SitioWeb\CatalogoSitioWeb;
use App\Modules\Tenancy\SitioWeb\PlantillaSitio;
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

        $porTipo = [];
        foreach ((array) ($contenido['secciones'] ?? []) as $seccion) {
            $tipo = is_array($seccion) ? (string) ($seccion['tipo'] ?? '') : '';
            if ($tipo !== '' && ! isset($porTipo[$tipo])
                && ($tipo === CatalogoSitioWeb::INICIO || $tipo === CatalogoSitioWeb::CONTACTO || in_array($tipo, $permitidos, true))) {
                $porTipo[$tipo] = self::seccion($tipo, $seccion);
            }
        }
        $movibles = array_values(array_filter(
            $porTipo,
            static fn (array $s): bool => ! in_array($s['tipo'], [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true),
        ));
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
     * siempre) y los banners vigentes hoy en el negocio.
     *
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public function paraPublico(array $contenido): array
    {
        $hoy = $this->fechas->hoy();
        $secciones = array_values(array_filter(
            (array) $contenido['secciones'],
            static fn (array $s): bool => $s['visible'] || in_array($s['tipo'], [CatalogoSitioWeb::INICIO, CatalogoSitioWeb::CONTACTO], true),
        ));
        $banners = array_values(array_filter(
            (array) $contenido['banners'],
            static fn (array $b): bool => ($b['desde'] === null || $b['desde'] <= $hoy) && ($b['hasta'] === null || $b['hasta'] >= $hoy),
        ));

        return [
            'plantilla' => $contenido['plantilla'],
            'secciones' => array_map(static function (array $s): array {
                unset($s['visible']);

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
                if (is_array($parte) && is_string($parte['foto_url'] ?? null)) {
                    $usadas[basename($parte['foto_url'])] = true;
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
