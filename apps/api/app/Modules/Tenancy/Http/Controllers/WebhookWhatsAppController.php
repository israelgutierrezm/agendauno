<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EstadosWhatsApp;
use App\Modules\Tenancy\Application\RespuestasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook público de WhatsApp (Meta Cloud API, ADR 0074): `/webhooks/whatsapp`.
 *
 * - GET: Meta lo verifica al configurarlo; responde el `hub.challenge` si el token es
 *   el nuestro.
 * - POST: los estados de entrega (entregado, leído, fallido) y los mensajes de quien
 *   contesta, a los que se responde solo (ADR 0083). Verifica la firma con el App
 *   Secret; sin él, en producción se rechaza. Siempre responde 200 a lo que aceptó (si
 *   no, Meta reintenta).
 */
class WebhookWhatsAppController
{
    public function __construct(
        private readonly ClienteWhatsApp $whatsapp,
        private readonly EstadosWhatsApp $estados,
        private readonly RespuestasWhatsApp $respuestas,
    ) {}

    public function verificar(Request $request): Response
    {
        $modo = (string) $request->query('hub_mode', (string) $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token', ''));
        $reto = (string) $request->query('hub_challenge', (string) $request->query('hub.challenge', ''));

        abort_unless($modo === 'subscribe' && $this->whatsapp->tokenDeVerificacionValido($token), 403);

        return response($reto, 200, ['Content-Type' => 'text/plain']);
    }

    public function recibir(Request $request): JsonResponse
    {
        $firma = $this->whatsapp->firmaValida($request->getContent(), $request->header('X-Hub-Signature-256'));
        if ($firma === false || ($firma === null && app()->environment('production'))) {
            abort(403, 'Firma inválida.');
        }

        /** @var array<string, mixed> $aviso */
        $aviso = $request->json()->all();
        $aplicados = $this->estados->procesar($aviso);
        $respuestas = $this->respuestas->procesar($aviso);

        return response()->json(['data' => ['ok' => true, 'aplicados' => $aplicados, 'respuestas' => $respuestas]]);
    }
}
