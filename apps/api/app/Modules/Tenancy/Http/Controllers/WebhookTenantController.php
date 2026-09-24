<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Pasarelas\MercadoPago\NotificacionesMercadoPago;
use App\Modules\Tenancy\Pasarelas\MercadoPago\VerificarFirmaMercadoPago;
use App\Modules\Tenancy\Pasarelas\OpenPay\NotificacionesOpenPay;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\TarjetasStripe;
use App\Modules\Tenancy\Pasarelas\Stripe\VerificarFirmaStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook publico de pasarela por estudio: `/webhooks/tenant/{estudio}/{proveedor}`.
 * El estudio se resuelve (y su BD se conecta) por `estudio.resolver`. Confirma el
 * pago pendiente correspondiente -> fulfillment. Idempotente. Verificación por
 * pasarela: Stripe y Mercado Pago con la firma (`webhook_secret`); OpenPay con el
 * usuario y contraseña del webhook. Mercado Pago y OpenPay además releen el cobro
 * en su API antes de confirmar.
 */
class WebhookTenantController
{
    public function __construct(
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly TarjetasStripe $tarjetas,
        private readonly NotificacionesMercadoPago $mercadoPago,
        private readonly NotificacionesOpenPay $openPay,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $proveedor = (string) $request->route('proveedor');

        if ($proveedor === 'stripe') {
            return $this->stripe($request);
        }
        if ($proveedor === 'mercadopago') {
            return $this->mercadoPago($request);
        }
        if ($proveedor === 'openpay') {
            return $this->openPay($request);
        }

        // Otros proveedores aun no tienen verificacion de firma dedicada en el plano
        // tenant. En PRODUCCION no se acepta una confirmacion sin firma: evita que
        // quien conozca/adivine una `referencia_externa` dispare pago+fulfillment. En
        // dev/test se permite la confirmacion por referencia (simulacion/pruebas).
        if (app()->environment('production')) {
            abort(400, 'Webhook no verificado para este proveedor.');
        }

        $referencia = (string) $request->input('referencia', '');
        if ($referencia !== '') {
            $this->confirmar->porReferencia($referencia);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Mercado Pago: `?data.id=…&type=…` (PHP lo entrega como `data_id`) firmado en
     * `x-signature`; también acepta el formato anterior `?topic=…&id=…`.
     */
    private function mercadoPago(Request $request): JsonResponse
    {
        $secreto = $this->registro->llaves('mercadopago')['webhook_secret'] ?? '';
        $dataId = (string) $request->query('data_id', '');

        if ($secreto === '') {
            if (app()->environment('production')) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaMercadoPago::valida($dataId, $request->header('x-request-id'), $request->header('x-signature'), $secreto)) {
            abort(401, 'Firma inválida.');
        }

        $tipo = (string) ($request->query('type') ?? $request->json('type') ?? $request->query('topic') ?? '');
        $id = $dataId !== '' ? $dataId : (string) ($request->json('data.id') ?? $request->query('id') ?? '');
        $this->mercadoPago->procesar($tipo, $id);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * OpenPay: HTTP Basic con el usuario y contraseña que el negocio guardó.
     */
    private function openPay(Request $request): JsonResponse
    {
        $llaves = $this->registro->llaves('openpay');
        $usuario = $llaves['webhook_user'] ?? '';
        $clave = $llaves['webhook_password'] ?? '';

        if ($usuario === '' || $clave === '') {
            if (app()->environment('production')) {
                abort(400, 'Webhook sin usuario y contraseña configurados.');
            }
        } else {
            [$recibidoUsuario, $recibidaClave] = self::credencialesBasic($request->header('Authorization'));
            if (! hash_equals($usuario, $recibidoUsuario) || ! hash_equals($clave, $recibidaClave)) {
                abort(401, 'Credenciales inválidas.');
            }
        }

        /** @var array<string, mixed> $evento */
        $evento = $request->json()->all();
        $this->openPay->procesar($evento);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function credencialesBasic(?string $encabezado): array
    {
        if ($encabezado === null || ! str_starts_with($encabezado, 'Basic ')) {
            return ['', ''];
        }
        $decodificado = base64_decode(substr($encabezado, 6), true);
        if ($decodificado === false || ! str_contains($decodificado, ':')) {
            return ['', ''];
        }
        [$usuario, $clave] = explode(':', $decodificado, 2);

        return [$usuario, $clave];
    }

    private function stripe(Request $request): JsonResponse
    {
        $secret = $this->registro->llaves('stripe')['webhook_secret'] ?? '';

        if ($secret === '') {
            // Sin webhook_secret no se puede verificar la firma: en PRODUCCION se
            // rechaza; en dev/test se permite para pruebas/simulacion.
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
            $this->confirmar->porReferencia($referencia);
        }

        // Tarjeta autorizada para pagos automáticos: desde la cuenta del alumno (modo
        // setup) o al pagar una compra con domiciliación (tras concederla, arriba).
        if ($tipo === 'checkout.session.completed' && is_array($objeto)
            && (($objeto['mode'] ?? '') === 'setup' || $sesionPagada)) {
            $this->tarjetas->sesionCompletada($objeto);
        }

        // Un cargo automático que el banco procesaba terminó rechazado.
        if ($tipo === 'payment_intent.payment_failed' && $referencia !== '') {
            $this->confirmar->rechazarPorReferencia($referencia);
        }

        // Devoluciones que quedaron pendientes: Stripe avisa cómo terminaron.
        if (in_array($tipo, ['refund.updated', 'refund.failed', 'charge.refund.updated'], true) && is_array($objeto)) {
            $estado = (string) ($objeto['status'] ?? '');
            if (in_array($estado, ['succeeded', 'failed', 'canceled'], true)) {
                $this->reembolsos->conciliar($referencia, $estado === 'succeeded');
            }
        }

        // El intento ya no se puede pagar: la sesión venció o el pago en tienda (OXXO)
        // no se completó. Queda cerrado y se puede reintentar.
        if (in_array($tipo, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true) && $referencia !== '') {
            $this->confirmar->rechazarPorReferencia($referencia);
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
