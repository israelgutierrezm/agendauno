<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Bandeja\ClienteMetaBandeja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook público de Instagram para la bandeja (ADR 0200): `/webhooks/instagram`.
 * GET: Meta lo verifica con nuestro token. POST: los mensajes de las cuentas conectadas,
 * con la firma de la app; sin secreto con qué revisarla, solo en local y pruebas.
 */
class WebhookInstagramController
{
    public function __construct(
        private readonly ClienteMetaBandeja $meta,
        private readonly BandejaTenant $bandeja,
    ) {}

    public function verificar(Request $request): Response
    {
        $modo = (string) $request->query('hub_mode', (string) $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token', ''));
        $reto = (string) $request->query('hub_challenge', (string) $request->query('hub.challenge', ''));
        abort_unless($modo === 'subscribe' && $this->meta->tokenDeVerificacionValido($token), 403);

        return response($reto, 200, ['Content-Type' => 'text/plain']);
    }

    public function recibir(Request $request): JsonResponse
    {
        $firma = $this->meta->firmaValida($request->getContent(), $request->header('X-Hub-Signature-256'), 'instagram');
        if ($firma === false || ($firma === null && ! app()->environment(['local', 'testing']))) {
            abort(403, 'Firma inválida.');
        }
        /** @var array<string, mixed> $aviso */
        $aviso = $request->json()->all();

        return response()->json(['data' => ['ok' => true, 'procesados' => $this->bandeja->procesarInstagram($aviso)]]);
    }
}
