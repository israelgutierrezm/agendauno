<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AvisosPasarelaTenant;
use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Pasarelas\MercadoPago\VerificarFirmaMercadoPago;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\VerificarFirmaStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook publico de pasarela por estudio: `/webhooks/tenant/{estudio}/{proveedor}`.
 * El estudio se resuelve (y su BD se conecta) por `estudio.resolver`. Verificación por
 * pasarela: Stripe y Mercado Pago con la firma (`webhook_secret`); OpenPay con el
 * usuario y contraseña del webhook.
 *
 * Con la firma comprobada, el aviso se guarda y se contesta al momento (ADR 0173):
 * procesarlo (confirmar el pago y lo que compró; Mercado Pago y OpenPay además releen
 * el cobro en su API) va después de responder, y si falla se reintenta aparte
 * ({@see AvisosPasarelaTenant}). Así la pasarela no corta por tiempo ni repite el aviso.
 */
class WebhookTenantController
{
    public function __construct(
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly AvisosPasarelaTenant $avisos,
        private readonly GestorDeConexionTenant $gestor,
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
        if (! app()->environment(['local', 'testing'])) {
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
            if (! app()->environment(['local', 'testing'])) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaMercadoPago::valida($dataId, $request->header('x-request-id'), $request->header('x-signature'), $secreto)) {
            abort(401, 'Firma inválida.');
        }

        $tipo = (string) ($request->query('type') ?? $request->json('type') ?? $request->query('topic') ?? '');
        $id = $dataId !== '' ? $dataId : (string) ($request->json('data.id') ?? $request->query('id') ?? '');

        return $this->guardarYContestar('mercadopago', $tipo, ['tipo' => $tipo, 'id' => $id]);
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
            if (! app()->environment(['local', 'testing'])) {
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

        return $this->guardarYContestar('openpay', isset($evento['type']) ? (string) $evento['type'] : null, $evento);
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
            if (! app()->environment(['local', 'testing'])) {
                abort(400, 'Webhook sin secreto configurado.');
            }
        } elseif (! VerificarFirmaStripe::valida($request->getContent(), $request->header('Stripe-Signature'), $secret)) {
            abort(400, 'Firma invalida.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        // El id del aviso (`evt_…`): si Stripe lo repite, no se guarda dos veces.
        return $this->guardarYContestar(
            'stripe',
            isset($payload['type']) ? (string) $payload['type'] : null,
            $payload,
            isset($payload['id']) ? (string) $payload['id'] : null,
        );
    }

    /**
     * Guarda el aviso y contesta; se procesa después de responder (si falla, el comando
     * de cada minuto lo reintenta).
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardarYContestar(string $proveedor, ?string $tipo, array $payload, ?string $clave = null): JsonResponse
    {
        $aviso = $this->avisos->recibir($proveedor, $tipo, $payload, $clave);
        $estudio = $this->gestor->actual();
        if ($aviso->estado === AvisoPasarelaTenant::PENDIENTE && $estudio instanceof Estudio) {
            $id = (int) $aviso->getKey();
            defer(function () use ($estudio, $id): void {
                $this->gestor->ejecutarAislado($estudio, function () use ($id): void {
                    $aviso = AvisoPasarelaTenant::query()->find($id);
                    if ($aviso instanceof AvisoPasarelaTenant) {
                        $this->avisos->procesar($aviso);
                    }
                }, null);
            });
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
