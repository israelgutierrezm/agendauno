<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EscaparateTenant;
use App\Modules\Tenancy\Application\SeoPaginaNegocio;
use App\Modules\Tenancy\Application\SitioWebTenant;
use App\Modules\Tenancy\Application\VisitasPaginaTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escaparate PÚBLICO del estudio (embudo público, P0 #3): sus datos públicos
 * ({@see EscaparateTenant}) con su sitio publicado (ADR 0114). Sin auth, pero SOLO con la
 * página pública abierta ({@see Estudio::paginaPublica()}).
 */
class EscaparateController
{
    public function __invoke(Request $request, EscaparateTenant $escaparate, SitioWebTenant $sitio, VisitasPaginaTenant $visitas, SeoPaginaNegocio $seo): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        // El escaparate es la cara pública: solo con la página abierta.
        abort_unless($estudio->paginaPublica(), 404);
        // Cuenta la visita del día para el embudo (C08), sin guardar quién; si falla, la
        // página se muestra igual.
        try {
            $visitas->registrar($estudio, $request->ip(), $request->userAgent());
        } catch (\Throwable $e) {
            report($e);
        }

        $datos = $escaparate->datos($estudio, $sitio->paraPublico($sitio->publicado($estudio->modalidad())));

        return response()->json(['data' => [
            ...$datos,
            // Sus datos estructurados, los mismos que da la cabecera del servidor (ADR 0155).
            'json_ld' => $seo->datosEstructurados($estudio, $datos),
        ]]);
    }
}
