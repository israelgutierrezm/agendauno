<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EscaparateTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\SitioWebTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\SitioWeb\CatalogoSitioWeb;
use App\Modules\Tenancy\SitioWeb\PlantillaSitio;
use App\Modules\Tenancy\SitioWeb\VideoSitio;
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
        $validado = $this->validarBorrador($request, $estudio, $parametros);
        $this->sitio->guardarBorrador($validado, $estudio->modalidad(), $this->usuarioId($request), (int) $estudio->getKey());

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * El borrador que llega, revisado con las mismas reglas que al guardarlo.
     *
     * @return array<string, mixed>
     */
    private function validarBorrador(Request $request, Estudio $estudio, ParametrosTenant $parametros): array
    {
        $modalidad = $estudio->modalidad();
        $foto = $this->reglaFoto($estudio);
        $bloquesMaximos = $parametros->entero('sitio.bloques_maximos');
        $enlace = $this->reglaEnlace();
        $movibles = CatalogoSitioWeb::movibles($modalidad);
        $validado = $request->validate([
            'plantilla' => ['required', Rule::enum(PlantillaSitio::class)],
            // Inicio y, cada página propia, con las mismas reglas (ADR 0146).
            ...$this->reglasSecciones('secciones', CatalogoSitioWeb::tipos($modalidad), $bloquesMaximos, $foto, $enlace),
            'paginas' => ['nullable', 'array', 'max:'.$parametros->entero('sitio.paginas_maximas')],
            'paginas.*.id' => ['nullable', 'string', 'max:40'],
            'paginas.*.slug' => ['required', 'string', 'max:'.CatalogoSitioWeb::MAX_DIRECCION, 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
            'paginas.*.titulo' => ['required', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO_PAGINA],
            'paginas.*.en_menu' => ['nullable', 'boolean'],
            ...$this->reglasSeo('paginas.*.seo', $foto),
            ...$this->reglasSecciones('paginas.*.secciones', $movibles, $bloquesMaximos, $foto, $enlace, vacia: true),
            ...$this->reglasSeo('seo', $foto),
            'pie' => ['nullable', 'array'],
            'pie.texto' => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_PIE],
            // Estilo global y encabezado (ADR 0146, fase 3).
            'estilo' => ['nullable', 'array'],
            'estilo.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'estilo.tipografia' => ['nullable', Rule::in(CatalogoSitioWeb::TIPOGRAFIAS)],
            'estilo.esquinas' => ['nullable', Rule::in(CatalogoSitioWeb::ESQUINAS)],
            'estilo.tema' => ['nullable', Rule::in(CatalogoSitioWeb::TEMAS)],
            'encabezado' => ['nullable', 'array'],
            'encabezado.boton_texto' => ['nullable', 'string', 'max:40'],
            'encabezado.boton_enlace' => ['nullable', 'string', 'max:300', $enlace],
            'banners' => ['present', 'array', 'max:'.$parametros->entero('sitio.banners_maximos')],
            'banners.*.id' => ['nullable', 'string', 'max:26'],
            'banners.*.titulo' => ['required', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO],
            'banners.*.texto' => ['nullable', 'string', 'max:240'],
            'banners.*.enlace_texto' => ['nullable', 'string', 'max:40'],
            'banners.*.enlace_url' => ['nullable', 'string', 'max:300', $enlace],
            'banners.*.desde' => ['nullable', 'date_format:Y-m-d'],
            'banners.*.hasta' => ['nullable', 'date_format:Y-m-d'],
            'banners.*.foto_url' => ['nullable', 'string', 'max:500', $foto],
        ], [
            'banners.max' => 'Puedes tener hasta :max banners.',
            'paginas.max' => 'Tu sitio puede tener hasta :max páginas, además de Inicio.',
            'paginas.*.slug.regex' => 'La dirección lleva solo minúsculas, números y guiones (p. ej. «nosotros» o «preguntas-frecuentes»).',
            'paginas.*.slug.distinct' => 'Ya tienes otra página con esa dirección.',
            'estilo.color.regex' => 'Elige un color de la paleta.',
            'secciones.*.tipo.in' => 'Esa sección no existe en tu sitio.',
            'paginas.*.secciones.*.tipo.in' => 'Esa sección no existe en tu sitio.',
            'secciones.*.datos.fotos.max' => 'Una galería lleva hasta :max fotos.',
            'paginas.*.secciones.*.datos.fotos.max' => 'Una galería lleva hasta :max fotos.',
            'secciones.*.datos.items.max' => 'Caben hasta :max elementos.',
            'paginas.*.secciones.*.datos.items.max' => 'Caben hasta :max elementos.',
        ]);
        $this->validarSecciones((array) $validado['secciones'], $bloquesMaximos, 'secciones');
        if (($validado['encabezado']['boton_enlace'] ?? null) !== null && trim((string) ($validado['encabezado']['boton_texto'] ?? '')) === '') {
            throw ValidationException::withMessages(['encabezado.boton_texto' => 'Escribe el texto del botón.']);
        }
        foreach ((array) ($validado['paginas'] ?? []) as $p => $pagina) {
            $this->validarSecciones((array) ($pagina['secciones'] ?? []), $bloquesMaximos, "paginas.{$p}.secciones");
        }
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

        return $validado;
    }

    public function publicar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $publicacion = $this->sitio->publicar($estudio->modalidad(), $this->usuarioId($request), (int) $estudio->getKey());
        // Lo que ven buscadores y redes de cada página, sin abrir la base del negocio.
        $estudio->update(['seo_sitio' => $this->sitio->resumenSeo($this->sitio->normalizar((array) $publicacion->contenido, $estudio->modalidad()))]);

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * Vuelve el borrador a una versión publicada (de las últimas): se revisa y se publica
     * como cualquier cambio (ADR 0146, fase 3).
     */
    public function restaurar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->sitio->restaurar((string) $request->route('version'), $estudio->modalidad(), $this->usuarioId($request), (int) $estudio->getKey());

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

    /**
     * La vista previa en vivo del editor (ADR 0146, fase 3): lo mismo que la vista previa,
     * con el borrador que se está editando, sin guardarlo. Se revisa como al guardar.
     */
    public function vistaPreviaEnVivo(Request $request, ParametrosTenant $parametros, EscaparateTenant $escaparate): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $this->validarBorrador($request, $estudio, $parametros);

        return response()->json(['data' => $escaparate->datos(
            $estudio,
            $this->sitio->paraPublico($this->sitio->normalizar($validado, $estudio->modalidad())),
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
            // Las últimas versiones publicadas, para volver a una (ADR 0146, fase 3).
            'versiones' => $this->sitio->versiones(),
            // El color de su marca: el del sitio si no elige otro.
            'color_marca' => $estudio->color_marca,
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
                // Los bloques propios (ADR 0146) y los formularios que se pueden poner.
                'bloques' => CatalogoSitioWeb::BLOQUES,
                'bloques_con_texto' => CatalogoSitioWeb::BLOQUES_CON_TEXTO,
                'bloques_con_foto' => CatalogoSitioWeb::BLOQUES_CON_FOTO,
                'bloques_maximos' => $parametros->entero('sitio.bloques_maximos'),
                'fondos' => CatalogoSitioWeb::FONDOS,
                'alineaciones' => CatalogoSitioWeb::ALINEACIONES,
                'max_elementos' => CatalogoSitioWeb::MAX_ELEMENTOS,
                'max_preguntas' => CatalogoSitioWeb::MAX_PREGUNTAS,
                // Páginas propias (ADR 0146, fase 2).
                'paginas_maximas' => $parametros->entero('sitio.paginas_maximas'),
                'movibles' => CatalogoSitioWeb::movibles($modalidad),
                'max_direccion' => CatalogoSitioWeb::MAX_DIRECCION,
                'max_titulo_pagina' => CatalogoSitioWeb::MAX_TITULO_PAGINA,
                'max_seo_titulo' => CatalogoSitioWeb::MAX_SEO_TITULO,
                'max_seo_descripcion' => CatalogoSitioWeb::MAX_SEO_DESCRIPCION,
                'max_pie' => CatalogoSitioWeb::MAX_PIE,
                // Estilo global (fase 3).
                'tipografias' => CatalogoSitioWeb::TIPOGRAFIAS,
                'esquinas' => CatalogoSitioWeb::ESQUINAS,
                'temas' => CatalogoSitioWeb::TEMAS,
                'formularios' => FormularioCaptacionTenant::query()->where('activo', true)->orderBy('nombre')->get()
                    ->map(static fn (FormularioCaptacionTenant $f): array => ['id' => $f->ulid, 'nombre' => $f->nombre])->all(),
            ],
        ];
    }

    /**
     * Las reglas de una lista de secciones (la de Inicio o la de una página): sus tipos,
     * sus textos y lo de cada bloque propio.
     *
     * @param  list<string>  $tipos  las secciones del sistema que caben
     * @return array<string, mixed>
     */
    private function reglasSecciones(string $prefijo, array $tipos, int $bloquesMaximos, Closure $foto, Closure $enlace, bool $vacia = false): array
    {
        return [
            $prefijo => [$vacia ? 'present' : 'required', 'array', 'max:'.(count($tipos) + $bloquesMaximos)],
            "{$prefijo}.*.tipo" => ['required', 'string', Rule::in([...$tipos, ...CatalogoSitioWeb::BLOQUES])],
            "{$prefijo}.*.visible" => ['required', 'boolean'],
            "{$prefijo}.*.titulo" => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO],
            "{$prefijo}.*.texto" => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_TEXTO],
            "{$prefijo}.*.foto_url" => ['nullable', 'string', 'max:500', $foto],
            // Los bloques propios (ADR 0146): su id, su estilo y lo de su tipo.
            "{$prefijo}.*.id" => ['nullable', 'string', 'max:40'],
            "{$prefijo}.*.estilo" => ['nullable', 'array'],
            "{$prefijo}.*.estilo.fondo" => ['nullable', Rule::in(CatalogoSitioWeb::FONDOS)],
            "{$prefijo}.*.estilo.alineacion" => ['nullable', Rule::in(CatalogoSitioWeb::ALINEACIONES)],
            "{$prefijo}.*.datos" => ['nullable', 'array'],
            "{$prefijo}.*.datos.lado" => ['nullable', Rule::in(['izquierda', 'derecha'])],
            "{$prefijo}.*.datos.boton_texto" => ['nullable', 'string', 'max:40'],
            "{$prefijo}.*.datos.boton_enlace" => ['nullable', 'string', 'max:300', $enlace],
            "{$prefijo}.*.datos.fotos" => ['nullable', 'array', 'max:'.CatalogoSitioWeb::MAX_ELEMENTOS],
            "{$prefijo}.*.datos.fotos.*.url" => ['required', 'string', 'max:500', $foto],
            "{$prefijo}.*.datos.fotos.*.pie" => ['nullable', 'string', 'max:120'],
            "{$prefijo}.*.datos.items" => ['nullable', 'array', 'max:'.CatalogoSitioWeb::MAX_PREGUNTAS],
            "{$prefijo}.*.datos.items.*.pregunta" => ['nullable', 'string', 'max:200'],
            "{$prefijo}.*.datos.items.*.respuesta" => ['nullable', 'string', 'max:1000'],
            "{$prefijo}.*.datos.items.*.nombre" => ['nullable', 'string', 'max:80'],
            "{$prefijo}.*.datos.items.*.titulo" => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_TITULO],
            "{$prefijo}.*.datos.items.*.texto" => ['nullable', 'string', 'max:500'],
            "{$prefijo}.*.datos.items.*.foto_url" => ['nullable', 'string', 'max:500', $foto],
            "{$prefijo}.*.datos.url" => ['nullable', 'string', 'max:300'],
            "{$prefijo}.*.datos.formulario_id" => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Cómo aparece en buscadores y al compartirse (Inicio o una página).
     *
     * @return array<string, mixed>
     */
    private function reglasSeo(string $prefijo, Closure $foto): array
    {
        return [
            $prefijo => ['nullable', 'array'],
            "{$prefijo}.titulo" => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_SEO_TITULO],
            "{$prefijo}.descripcion" => ['nullable', 'string', 'max:'.CatalogoSitioWeb::MAX_SEO_DESCRIPCION],
            "{$prefijo}.imagen_url" => ['nullable', 'string', 'max:500', $foto],
        ];
    }

    /**
     * Lo que no se dice con reglas: cada sección del sistema una sola vez, cuántos
     * bloques propios, el video de YouTube o Vimeo, el formulario que existe y un botón
     * con su texto.
     *
     * @param  array<int, array<string, mixed>>  $secciones
     */
    private function validarSecciones(array $secciones, int $bloquesMaximos, string $prefijo): void
    {
        $vistas = [];
        $bloques = 0;
        foreach ($secciones as $i => $s) {
            $tipo = (string) $s['tipo'];
            if (! CatalogoSitioWeb::esBloque($tipo)) {
                if (isset($vistas[$tipo])) {
                    throw ValidationException::withMessages(["{$prefijo}.{$i}.tipo" => 'Cada sección va una sola vez.']);
                }
                $vistas[$tipo] = true;

                continue;
            }
            if (++$bloques > $bloquesMaximos) {
                throw ValidationException::withMessages([$prefijo => "Cada página puede tener hasta {$bloquesMaximos} bloques propios."]);
            }
            $datos = (array) ($s['datos'] ?? []);
            if (($datos['boton_enlace'] ?? null) !== null && trim((string) ($datos['boton_texto'] ?? '')) === '') {
                throw ValidationException::withMessages(["{$prefijo}.{$i}.datos.boton_texto" => 'Escribe el texto del botón.']);
            }
            if ($tipo === 'video' && trim((string) ($datos['url'] ?? '')) !== '' && VideoSitio::desdeUrl((string) $datos['url']) === null) {
                throw ValidationException::withMessages(["{$prefijo}.{$i}.datos.url" => 'Pega el enlace de un video de YouTube o de Vimeo.']);
            }
            $formulario = $datos['formulario_id'] ?? null;
            if ($tipo === 'formulario' && $formulario !== null
                && ! FormularioCaptacionTenant::query()->where('ulid', (string) $formulario)->exists()) {
                throw ValidationException::withMessages(["{$prefijo}.{$i}.datos.formulario_id" => 'Ese formulario ya no existe.']);
            }
        }
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
