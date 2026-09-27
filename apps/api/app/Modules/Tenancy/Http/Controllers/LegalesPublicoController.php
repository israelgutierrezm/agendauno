<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use Illuminate\Http\JsonResponse;

/**
 * Documentos legales públicos de la plataforma (aviso de privacidad y términos):
 * solo las versiones PUBLICADAS por el superadministrador, con su número y fecha
 * (el registro las manda de vuelta al aceptarlas). Sin autenticación. Si aún no se
 * publican, vienen en null.
 */
class LegalesPublicoController
{
    public function __invoke(DocumentosLegales $legales): JsonResponse
    {
        $aviso = $legales->vigente(DocumentoLegal::AVISO);
        $terminos = $legales->vigente(DocumentoLegal::TERMINOS);
        $version = static fn (?DocumentoLegal $d): ?array => $d === null ? null : [
            'version' => $d->version,
            'vigente_desde' => $d->vigente_desde->toIso8601String(),
        ];

        return response()->json(['data' => [
            'aviso_privacidad' => $aviso?->contenido,
            'terminos' => $terminos?->contenido,
            'versiones' => [
                'aviso_privacidad' => $version($aviso),
                'terminos' => $version($terminos),
            ],
            'responsable' => $aviso?->responsable,
        ]]);
    }
}
