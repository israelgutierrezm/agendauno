<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Application\AvisosRentaPlataforma;
use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\MercadoPago\VerificarFirmaMercadoPago;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use App\Modules\Tenancy\Pasarelas\Stripe\VerificarFirmaStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook publico de la pasarela de LA PLATAFORMA: `/webhooks/plataforma/{proveedor}`.
 * Confirma el cargo de renta pendiente correspondiente -> `pagado`. Idempotente. Para
 * Stripe verifica la firma con la `webhook_secret` global cuando esta configurada; Mercado
 * Pago, su firma `x-signature`; OpenPay, su usuario y contraseña (HTTP Basic). De estas
 * dos, el cobro se relee en su API antes de confirmar (ADR 0192).
 * Espejo, a nivel plataforma, de {@see WebhookTenantController}.
 */
class WebhookPlataformaController
{
    public function __construct(
        private readonly ConfirmarCargoRenta $confirmar,
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly DomiciliacionRenta $domiciliacion,
        private readonly AvisosRentaPlataforma $avisos,
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

        // Otros proveedores aun no tienen verificacion de firma dedicada. En PRODUCCION
        // no se acepta una confirmacion sin firma (evita que quien adivine una referencia
        // dispare el pago). En dev/test se permite por referencia (simulacion/pruebas).
        if (! app()->environment(['local', 'testing'])) {
            abort(400, 'Webhook no verificado para este proveedor.');
        }

        $referencia = (string) $request->input('referencia', '');
        if ($referencia !== '') {
            $this->confirmar->porReferencia($referencia, $proveedor);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Mercado Pago: `?data.id=…&type=…` (PHP lo entrega como `data_id`) firmado en
     * `x-signature` con el secreto de la plataforma.
     */
    private function mercadoPago(Request $request): JsonResponse
    {
        $secreto = $this->registro->llaves('mercadopago')['webhook_secret'] ?? '';
        $dataId = (string) $request->query('data_id', '');
        if ($secreto === '') {
            if (! app()->environment(['local', 'testing'])) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaMercadoPago::valida($dataId, $request->header('x-request-id'), $request->header('x-signature'), $secreto)) {
            abort(401, 'Firma inválida.');
        }

        $this->registro->anotarAviso('mercadopago');
        $tipo = (string) ($request->query('type') ?? $request->json('type') ?? $request->query('topic') ?? '');
        $id = $dataId !== '' ? $dataId : (string) ($request->json('data.id') ?? $request->query('id') ?? '');
        $this->avisos->mercadoPago($tipo, $id);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * OpenPay: HTTP Basic con el usuario y la contraseña que guardó la plataforma. La
     * verificación del webhook (`verification`) solo se contesta.
     */
    private function openPay(Request $request): JsonResponse
    {
        $llaves = $this->registro->llaves('openpay');
        $usuario = $llaves['webhook_user'] ?? '';
        $clave = $llaves['webhook_password'] ?? '';
        if ($usuario === '' || $clave === '') {
            if (! app()->environment(['local', 'testing'])) {
                abort(400, 'Webhook sin usuario y contraseña configurados.');
            }
        } else {
            $encabezado = (string) $request->header('Authorization', '');
            $decodificado = str_starts_with($encabezado, 'Basic ') ? (string) base64_decode(substr($encabezado, 6), true) : '';
            [$recibidoUsuario, $recibidaClave] = array_pad(explode(':', $decodificado, 2), 2, '');
            if (! hash_equals($usuario, $recibidoUsuario) || ! hash_equals($clave, $recibidaClave)) {
                abort(401, 'Credenciales inválidas.');
            }
        }

        $this->registro->anotarAviso('openpay');
        /** @var array<string, mixed> $evento */
        $evento = $request->json()->all();
        $this->avisos->openPay($evento);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function stripe(Request $request): JsonResponse
    {
        $secret = $this->registro->llaves('stripe')['webhook_secret'] ?? '';

        if ($secret === '') {
            // Sin webhook_secret no se puede verificar la firma: en PRODUCCION se rechaza;
            // en dev/test se permite para pruebas/simulacion.
            if (! app()->environment(['local', 'testing'])) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaStripe::valida($request->getContent(), $request->header('Stripe-Signature'), $secret)) {
            abort(400, 'Firma invalida.');
        }

        $this->registro->anotarAviso('stripe');
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $tipo = isset($payload['type']) ? (string) $payload['type'] : '';
        $objeto = $payload['data']['object'] ?? [];
        $referencia = is_array($objeto) && isset($objeto['id']) ? (string) $objeto['id'] : '';

        // Checkout en modo `setup`: el dueño guardó la tarjeta para domiciliar la renta.
        if ($tipo === 'checkout.session.completed' && is_array($objeto) && ($objeto['mode'] ?? '') === 'setup' && $referencia !== '') {
            $this->domiciliacion->guardarDeSesion($referencia);

            return response()->json(['data' => ['ok' => true]]);
        }

        // Checkout: la sesión pagada (tarjeta al momento; OXXO cuando se paga en tienda).
        // PaymentIntent: cobros creados antes de usar Checkout.
        $sesionPagada = ($tipo === 'checkout.session.completed' && is_array($objeto) && ($objeto['payment_status'] ?? '') === 'paid')
            || $tipo === 'checkout.session.async_payment_succeeded';

        if (($sesionPagada || $tipo === 'payment_intent.succeeded') && $referencia !== '') {
            // Si el cargo no tiene esa referencia (Stripe cobró pero no se alcanzó a
            // guardar), se busca por el cargo que va en `metadata.cargo_renta`.
            $metadata = is_array($objeto['metadata'] ?? null) ? $objeto['metadata'] : [];
            $monto = $objeto['amount_received'] ?? $objeto['amount_total'] ?? $objeto['amount'] ?? null;
            $this->confirmar->porReferencia(
                $referencia,
                'stripe',
                is_string($metadata['cargo_renta'] ?? null) ? $metadata['cargo_renta'] : null,
                is_numeric($monto) ? (int) $monto : null,
            );
        }

        // El intento ya no se puede pagar: la sesión venció o el pago en tienda (OXXO)
        // no se completó. Queda cerrado y se puede reintentar.
        if (in_array($tipo, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true) && $referencia !== '') {
            $this->confirmar->intentoTerminado($referencia);
        }

        // Un cobro a la tarjeta domiciliada que quedó en proceso y después falló (o se
        // anuló): cuenta como un rechazo y se programa el siguiente intento.
        if (in_array($tipo, ['payment_intent.payment_failed', 'payment_intent.canceled'], true) && $referencia !== '') {
            $error = is_array($objeto['last_payment_error'] ?? null) ? $objeto['last_payment_error'] : [];
            $codigo = $error['decline_code'] ?? $error['code'] ?? null;
            $this->confirmar->intentoTerminado($referencia, is_string($codigo) && $codigo !== '' ? $codigo : null);
        }

        // Una devolución o una disputa de un cobro de la plataforma (renta o timbres): el
        // cargo no se toca solo; el superadmin lo revisa.
        if (in_array($tipo, ['charge.refunded', 'charge.dispute.created'], true) && is_array($objeto)) {
            $intent = (string) ($objeto['payment_intent'] ?? '');
            $cargo = $intent !== '' ? CargoRenta::query()->where('referencia_pago', $intent)->first() : null;
            $monto = (int) ($objeto['amount_refunded'] ?? $objeto['amount'] ?? 0);
            $dinero = number_format($monto / 100, 2, '.', ',').' '.strtoupper((string) ($objeto['currency'] ?? ''));
            app(AlertasPlataforma::class)->registrar(
                $tipo === 'charge.refunded' ? 'renta_devuelta' : 'renta_en_disputa',
                $intent !== '' ? $intent : $referencia,
                ($tipo === 'charge.refunded' ? 'Stripe registró una devolución' : 'El banco disputó un cobro')
                    ." de la plataforma ({$dinero}, {$intent})"
                    .($cargo instanceof CargoRenta ? " del cargo {$cargo->periodo} ({$cargo->concepto})" : '')
                    .'. Revisa la renta o los timbres de ese negocio.',
                $cargo?->estudio?->slug,
            );
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
