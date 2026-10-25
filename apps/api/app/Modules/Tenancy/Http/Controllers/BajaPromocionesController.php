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
 *
 * Abrir el enlace (GET) solo pregunta: los filtros de seguridad del correo abren los
 * enlaces solos y no deben dar de baja a nadie. Confirmar (POST a la misma dirección
 * firmada) da de baja; el cliente de correo también lo hace en un clic con
 * `List-Unsubscribe-Post` (RFC 8058).
 */
class BajaPromocionesController
{
    public function __invoke(Request $request, RegistrarAuditoria $auditoria): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        $negocio = e((string) $estudio->nombre);

        if (! $request->isMethod('post')) {
            if (! $persona->recibe_promociones) {
                return self::pagina('Listo', "Ya no recibes promociones de {$negocio}.",
                    'Sigues recibiendo los avisos de tus reservas y pagos. Si cambias de opinión, puedes volver a activarlas en tu cuenta.');
            }

            return self::pagina('Dejar de recibir promociones', "¿Dejar de recibir promociones de {$negocio}?",
                'Seguirás recibiendo los avisos de tus reservas y pagos.',
                '<form method="post" action="'.e($request->fullUrl()).'">'
                .'<button type="submit" style="font:inherit;padding:.5rem 1rem;border-radius:.375rem;border:1px solid #111827;background:#111827;color:#fff;cursor:pointer">Dejar de recibirlas</button>'
                .'</form>');
        }

        if ($persona->recibe_promociones) {
            $persona->update(['recibe_promociones' => false]);
            $auditoria->registrar(null, 'persona.sin_promociones', 'persona', (string) $persona->ulid,
                ['recibe_promociones' => true], ['recibe_promociones' => false], 'Se dio de baja desde el enlace de una campaña');
        }

        return self::pagina('Listo', "Listo: ya no recibirás promociones de {$negocio}.",
            'Seguirás recibiendo los avisos de tus reservas y pagos. Si cambias de opinión, puedes volver a activarlas en tu cuenta.');
    }

    /** Una página mínima, sin plantilla (el texto del negocio ya va escapado). */
    private static function pagina(string $titulo, string $encabezado, string $texto, string $extra = ''): Response
    {
        return response(
            '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>'.e($titulo).'</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5">'
            ."<h1 style=\"font-size:1.25rem;font-weight:500\">{$encabezado}</h1>"
            ."<p>{$texto}</p>{$extra}"
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex'],
        );
    }
}
