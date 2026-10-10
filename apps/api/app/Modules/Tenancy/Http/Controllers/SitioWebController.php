<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EscaparateTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\SitioWebTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\SitioWeb\CatalogoSitioWeb;
use App\Modules\Tenancy\SitioWeb\PlantillaSitio;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El constructor del sitio del negocio (ADR 0114), para quien configura el negocio
 * (`estudio.gestionar`): elegir plantilla, ordenar, mostrar u ocultar secciones, sus
 * títulos y textos, la foto de «Nosotros», los banners, la vista previa y publicar. Lo
 * que se guarda es un borrador; el público ve la última versión publicada.
 */
class SitioWebController
{
    public function __construct(private readonly SitioWebTenant $sitio) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->estudio($request))]);
    }

    public function guardar(Request $request, ParametrosTenant $parametros): JsonResponse
    {
        $estudio = $this->estudio($request);
        $modalidad = $estudio->modalidad();
        $foto = $this->reglaFoto($estudio);
        $validado = $request->validate([
            'plantilla' => ['required', Rule::enum(PlantillaSitio::class)],
            'secciones' => ['required', 'array', 'max:'.count(CatalogoSitioWeb::tipos($modalidad))],
            'secciones.*.tipo' => ['required', 'string', 'distinct', Rule::in(CatalogoSitioWeb::tipos($modalidad))],
            'secciones.*.visible' => ['required', 'boolean'],
            'secciones.*.titulo' => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO],
            'secciones.*.texto' => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_TEXTO],
            'secciones.*.foto_url' => ['nullable', 'string', 'max:500', $foto],
            'banners' => ['present', 'array', 'max:'.$parametros->entero('sitio.banners_maximos')],
            'banners.*.id' => ['nullable', 'string', 'max:26'],
            'banners.*.titulo' => ['required', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO],
            'banners.*.texto' => ['nullable', 'string', 'max:240'],
            'banners.*.enlace_texto' => ['nullable', 'string', 'max:40'],
            'banners.*.enlace_url' => ['nullable', 'string', 'max:300', $this->reglaEnlace()],
            'banners.*.desde' => ['nullable', 'date_format:Y-m-d'],
            'banners.*.hasta' => ['nullable', 'date_format:Y-m-d'],
            'banners.*.foto_url' => ['nullable', 'string', 'max:500', $foto],
        ], [
            'banners.max' => 'Puedes tener hasta :max banners.',
            'secciones.*.tipo.in' => 'Esa sección no existe en tu sitio.',
            'secciones.*.tipo.distinct' => 'Cada sección va una sola vez.',
        ]);
        /** @var list<array<string, mixed>> $banners */
        $banners = $validado['banners'];
        foreach ($banners as $i => $banner) {
            if (($banner['desde'] ?? null) !== null && ($banner['hasta'] ?? null) !== null && $banner['hasta'] < $banner['desde']) {
                throw ValidationException::withMessages(["banners.{$i}.hasta" => 'El banner termina antes de empezar.']);
            }
            if (($banner['enlace_url'] ?? null) !== null && trim((string) ($banner['enlace_texto'] ?? '')) === '') {
                throw ValidationException::withMessages(["banners.{$i}.enlace_texto" => 'Escribe el texto del botón del enlace.']);
            }
        }

        $this->sitio->guardarBorrador($validado, $modalidad, $this->usuarioId($request), (int) $estudio->getKey());

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    public function publicar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->sitio->publicar($estudio->modalidad(), $this->usuarioId($request), (int) $estudio->getKey());

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    public function descartar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->sitio->descartar((int) $estudio->getKey());

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * Lo que vería el público si publicara el borrador ahora: los datos de su página con
     * el borrador. Funciona aunque la página no esté publicada todavía.
     */
    public function vistaPrevia(Request $request, EscaparateTenant $escaparate): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => $escaparate->datos(
            $estudio,
            $this->sitio->paraPublico($this->sitio->borrador($estudio->modalidad())),
        )]);
    }

    /** Una foto para el sitio («Nosotros» o un banner): JPG, PNG o WebP de hasta 4 MB. */
    public function subirImagen(Request $request, ParametrosTenant $parametros): JsonResponse
    {
        $estudio = $this->estudio($request);
        $request->validate([
            // SVG excluido a propósito (riesgo de XSS al servirse en el navegador).
            'imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $carpeta = SitioWebTenant::carpetaImagenes((int) $estudio->getKey());
        $disco = Storage::disk('public');
        $maximo = $parametros->entero('sitio.imagenes_maximas');
        if (count($disco->files($carpeta)) >= $maximo) {
            throw ValidationException::withMessages([
                'imagen' => "Tu sitio ya tiene {$maximo} imágenes. Quita las que no uses y guarda o publica para liberar espacio.",
            ]);
        }
        $archivo = $request->file('imagen');
        abort_unless($archivo instanceof UploadedFile, 422);
        $ruta = $archivo->storeAs($carpeta, Str::lower((string) Str::ulid()).'.'.$archivo->extension(), 'public');

        return response()->json(['data' => ['url' => $disco->url((string) $ruta)]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio): array
    {
        $modalidad = $estudio->modalidad();
        $ultima = $this->sitio->ultimaPublicacion();
        $parametros = app(ParametrosTenant::class);

        return [
            'borrador' => $this->sitio->borrador($modalidad),
            'cambios_sin_publicar' => $this->sitio->hayCambios($modalidad),
            'publicado_en' => $ultima?->created_at?->toIso8601String(),
            // La página se ve en línea solo si el negocio la abrió (Configuración).
            'pagina_publica' => $estudio->paginaPublica(),
            // La plantilla «Portada» necesita la foto de portada (Página pública).
            'tiene_portada' => $estudio->portada_url !== null,
            'catalogo' => [
                'plantillas' => array_map(static fn (PlantillaSitio $p): array => [
                    'clave' => $p->value,
                    'orden' => $p->orden($modalidad),
                ], PlantillaSitio::cases()),
                'con_texto' => CatalogoSitioWeb::CON_TEXTO,
                'con_foto' => CatalogoSitioWeb::CON_FOTO,
                'max_titulo' => CatalogoSitioWeb::MAX_TITULO,
                'max_texto' => CatalogoSitioWeb::MAX_TEXTO,
                'banners_maximos' => $parametros->entero('sitio.banners_maximos'),
            ],
        ];
    }

    /** Solo imágenes subidas para el sitio de este negocio (nada de URL ajenas). */
    private function reglaFoto(Estudio $estudio): Closure
    {
        $estudioId = (int) $estudio->getKey();

        return static function (string $atributo, mixed $valor, Closure $falla) use ($estudioId): void {
            if (is_string($valor) && ! SitioWebTenant::esImagenDelNegocio($valor, $estudioId)) {
                $falla('Sube la foto desde aquí.');
            }
        };
    }

    /** Un enlace de banner: una página web (https), una ruta del sitio (/…) o una sección (#…). */
    private function reglaEnlace(): Closure
    {
        return static function (string $atributo, mixed $valor, Closure $falla): void {
            if (! is_string($valor)) {
                return;
            }
            $valido = (preg_match('#^https?://#i', $valor) === 1 && filter_var($valor, FILTER_VALIDATE_URL) !== false)
                || preg_match('#^/(?!/)[^\s]*$#', $valor) === 1
                || preg_match('/^#[a-z0-9-]+$/', $valor) === 1;
            if (! $valido) {
                $falla('El enlace debe ser una página (https://…), una ruta de tu sitio (/…) o una sección (#…).');
            }
        };
    }

    private function usuarioId(Request $request): ?int
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? (int) $usuario->getKey() : null;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
