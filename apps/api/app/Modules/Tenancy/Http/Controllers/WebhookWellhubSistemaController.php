<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Integraciones\SistemaWellhub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El aviso de Wellhub de que un gimnasio eligió a AgendaUno como su sistema (ADR 0202),
 * firmado como los demás avisos de Wellhub (HMAC-SHA1 del cuerpo con la llave de
 * AgendaUno, `WELLHUB_WEBHOOK_SECRET`). Se contesta al momento (Wellhub espera un
 * segundo).
 */
class WebhookWellhubSistemaController
{
    public function __construct(private readonly SistemaWellhub $sistema) {}

    public function __invoke(Request $request): JsonResponse
    {
        $cuerpo = $request->getContent();
        $secreto = (string) config('agendauno.integraciones.wellhub_webhook_secret');
        if ($secreto === '') {
            abort_if(! app()->environment(['local', 'testing']), 400, 'Aviso sin llave secreta configurada.');
        } else {
            $recibida = strtoupper((string) preg_replace('/^0X/i', '', trim((string) $request->header('X-Gympass-Signature'))));
            abort_unless($recibida !== '' && hash_equals(strtoupper(hash_hmac('sha1', $cuerpo, $secreto)), $recibida), 401, 'Firma inválida.');
        }
        $datos = json_decode($cuerpo, true);
        $this->sistema->recibir(is_array($datos) ? $datos : []);

        return response()->json(['data' => ['ok' => true]]);
    }
}
