<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuerpoPaginaNegocio;
use App\Modules\Tenancy\Application\DatosPublicosNegocio;
use App\Modules\Tenancy\Application\SeoPaginaNegocio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La página pública de un negocio en su subdominio para buscadores y vistas previas
 * (ADR 0128, 0155). Las pide nginx, no la web: el negocio sale del host (no de la ruta)
 * y la página, de `X-Pagina` (la dirección que pidió el navegador).
 */
class PaginaNegocioSeoController
{
    public function __construct(
        private readonly SeoPaginaNegocio $seo,
        private readonly DatosPublicosNegocio $datos,
        private readonly CuerpoPaginaNegocio $cuerpo,
    ) {}

    /**
     * Su cabecera HTML. 204 (sin cuerpo) si no es una página pública del negocio o algo
     * falla: nginx deja entonces la genérica sin indexar, y la página se sirve igual.
     */
    public function cabeza(Request $request): Response
    {
        try {
            $estudio = $this->seo->negocioDelHost($request->getHost());
            $direccion = (string) $request->header('X-Pagina', '/');
            $pagina = $estudio === null ? null : $this->seo->paraRuta($estudio, $direccion, $this->datosDe($estudio, $direccion));
            if ($estudio === null || $pagina === null) {
                return response()->noContent();
            }

            return response($this->seo->html($estudio, $pagina), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            report($e);

            return response()->noContent();
        }
    }

    /**
     * El contenido de su página en HTML (ADR 0155), que nginx pone dentro de `#app`. 204
     * si no es una página pública del negocio o algo falla: la página se sirve igual.
     */
    public function cuerpo(Request $request): Response
    {
        try {
            $estudio = $this->seo->negocioDelHost($request->getHost());
            $pagina = $estudio === null ? null : $this->seo->cualPagina($estudio, (string) $request->header('X-Pagina', '/'));
            if ($estudio === null || $pagina === null) {
                return response()->noContent();
            }
            $html = $this->cuerpo->html($estudio, $pagina, $this->datos->de($estudio));

            return $html === '' ? response()->noContent() : response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            report($e);

            return response()->noContent();
        }
    }

    /**
     * ¿Existe la dirección? 204 sí; 403 no, y nginx sirve la aplicación con 404 (ADR
     * 0155). Si algo falla, 204: nunca se niega una página por un error de aquí.
     */
    public function existe(Request $request): Response
    {
        try {
            return $this->seo->existe($request->getHost(), (string) $request->header('X-Pagina', '/'))
                ? response()->noContent()
                : response('', 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->noContent();
        }
    }

    /**
     * Los datos públicos para los datos estructurados de Inicio; si no se pueden leer,
     * los básicos (la cabecera sale igual).
     *
     * @return array<string, mixed>|null
     */
    private function datosDe(Estudio $estudio, string $direccion): ?array
    {
        if (($this->seo->cualPagina($estudio, $direccion)['pagina'] ?? null) !== 'inicio') {
            return null;
        }
        try {
            return $this->datos->de($estudio);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function sitemap(Request $request): Response
    {
        $estudio = $this->seo->negocioDelHost($request->getHost());
        $xml = $estudio === null ? null : $this->seo->sitemap($estudio);
        if ($xml === null) {
            return response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Los sitemaps de los negocios de la marca, en su dominio principal (ADR 0155). */
    public function negocios(Request $request): Response
    {
        $xml = $this->seo->indiceDeNegocios($request->getHost());
        if ($xml === null) {
            return response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(Request $request): Response
    {
        return response($this->seo->robots($this->seo->negocioDelHost($request->getHost())), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
