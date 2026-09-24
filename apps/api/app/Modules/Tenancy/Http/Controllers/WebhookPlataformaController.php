<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use App\Modules\Tenancy\Pasarelas\Stripe\VerificarFirmaStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook publico de la pasarela de LA PLATAFORMA: `/webhooks/plataforma/{proveedor}`.
 * Confirma el cargo de renta pendiente correspondiente -> `pagado`. Idempotente. Para
 * Stripe verifica la firma con la `webhook_secret` global cuando esta configurada.
 * Espejo, a nivel plataforma, de {@see WebhookTenantController}.
 */
class WebhookPlataformaController
{
    public function __construct(
        private readonly ConfirmarCargoRenta $confirmar,
        private readonly RegistroDePasarelasPlataforma $registro,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $proveedor = (string) $request->route('proveedor');

        if ($proveedor === 'stripe') {
            return $this->stripe($request);
        }

        // Otros proveedores aun no tienen verificacion de firma dedicada. En PRODUCCION
        // no se acepta una confirmacion sin firma (evita que quien adivine una referencia
        // dispare el pago). En dev/test se permite por referencia (simulacion/pruebas).
        if (app()->environment('production')) {
            abort(400, 'Webhook no verificado para este proveedor.');
        }

        $referencia = (string) $request->input('referencia', '');
        if ($referencia !== '') {
            $this->confirmar->porReferencia($referencia, $proveedor);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    private function stripe(Request $request): JsonResponse
    {
        $secret = $this->registro->llaves('stripe')['webhook_secret'] ?? '';

        if ($secret === '') {
            // Sin webhook_secret no se puede verificar la firma: en PRODUCCION se rechaza;
            // en dev/test se permite para pruebas/simulacion.
            if (app()->environment('production')) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaStripe::valida($request->getContent(), $request->header('Stripe-Signature'), $secret)) {
            abort(400, 'Firma invalida.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $tipo = isset($payload['type']) ? (string) $payload['type'] : '';
        $objeto = $payload['data']['object'] ?? [];
        $referencia = is_array($objeto) && isset($objeto['id']) ? (string) $objeto['id'] : '';

        // Checkout: la sesión pagada (tarjeta al momento; OXXO cuando se paga en tienda).
        // PaymentIntent: cobros creados antes de usar Checkout.
        $sesionPagada = ($tipo === 'checkout.session.completed' && is_array($objeto) && ($objeto['payment_status'] ?? '') === 'paid')
            || $tipo === 'checkout.session.async_payment_succeeded';

        if (($sesionPagada || $tipo === 'payment_intent.succeeded') && $referencia !== '') {
            $this->confirmar->porReferencia($referencia, 'stripe');
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
