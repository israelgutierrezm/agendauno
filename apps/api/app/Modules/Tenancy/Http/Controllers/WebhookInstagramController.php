<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Bandeja\ClienteMetaBandeja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhooks públicos de Instagram y Messenger para la bandeja (ADR 0200):
 * `/webhooks/instagram` y `/webhooks/messenger`. GET: Meta los verifica con nuestro
 * token. POST: los mensajes de las cuentas o páginas conectadas, con la firma de la
 * app; sin secreto con qué revisarla, solo en local y pruebas.
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
        $this->exigirFirma($request, 'instagram');
        /** @var array<string, mixed> $aviso */
        $aviso = $request->json()->all();

        return response()->json(['data' => ['ok' => true, 'procesados' => $this->bandeja->procesarInstagram($aviso)]]);
    }

    /** Messenger (`/webhooks/messenger`): el mismo formato, firmado por la app de Meta. */
    public function recibirMessenger(Request $request): JsonResponse
    {
        $this->exigirFirma($request, 'messenger');
        /** @var array<string, mixed> $aviso */
        $aviso = $request->json()->all();

        return response()->json(['data' => ['ok' => true, 'procesados' => $this->bandeja->procesarMessenger($aviso)]]);
    }

    private function exigirFirma(Request $request, string $canal): void
    {
        $firma = $this->meta->firmaValida($request->getContent(), $request->header('X-Hub-Signature-256'), $canal);
        if ($firma === false || ($firma === null && ! app()->environment(['local', 'testing']))) {
            abort(403, 'Firma inválida.');
        }
    }
}
