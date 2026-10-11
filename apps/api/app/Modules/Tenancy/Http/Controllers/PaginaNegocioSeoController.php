<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\SeoPaginaNegocio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La página pública de un negocio en su subdominio para buscadores y vistas previas
 * (ADR 0128). Las pide nginx, no la web: el negocio sale del host (no de la ruta) y la
 * página, de `X-Pagina` (la dirección que pidió el navegador).
 */
class PaginaNegocioSeoController
{
    public function __construct(private readonly SeoPaginaNegocio $seo) {}

    /**
     * Su cabecera HTML. 204 (sin cuerpo) si no es una página pública del negocio o algo
     * falla: nginx deja entonces la genérica sin indexar, y la página se sirve igual.
     */
    public function cabeza(Request $request): Response
    {
        try {
            $estudio = $this->seo->negocioDelHost($request->getHost());
            $pagina = $estudio === null ? null : $this->seo->paraRuta($estudio, (string) $request->header('X-Pagina', '/'));
            if ($estudio === null || $pagina === null) {
                return response()->noContent();
            }

            return response($this->seo->html($estudio, $pagina), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            report($e);

            return response()->noContent();
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

    public function robots(Request $request): Response
    {
        return response($this->seo->robots($this->seo->negocioDelHost($request->getHost())), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
