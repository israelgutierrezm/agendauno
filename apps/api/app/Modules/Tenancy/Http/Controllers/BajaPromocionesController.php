<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dejar de recibir promociones desde el enlace de una campaña (plan de mejoras B08). El
 * enlace va firmado y vence: nadie da de baja a otra persona adivinando su dirección.
 * Los avisos de operación (recordatorios, pagos) siguen llegando.
 */
class BajaPromocionesController
{
    public function __invoke(Request $request, RegistrarAuditoria $auditoria): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        if ($persona->recibe_promociones) {
            $persona->update(['recibe_promociones' => false]);
            $auditoria->registrar(null, 'persona.sin_promociones', 'persona', (string) $persona->ulid,
                ['recibe_promociones' => true], ['recibe_promociones' => false], 'Se dio de baja desde el enlace de una campaña');
        }

        $negocio = e((string) $estudio->nombre);

        return response(
            '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>Listo</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5">'
            ."<h1 style=\"font-size:1.25rem;font-weight:500\">Listo: ya no recibirás promociones de {$negocio}.</h1>"
            .'<p>Seguirás recibiendo los avisos de tus reservas y pagos. Si cambias de opinión, puedes volver a activarlas en tu cuenta.</p>'
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex'],
        );
    }
}
