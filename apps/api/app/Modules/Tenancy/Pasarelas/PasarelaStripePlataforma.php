<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;

/**
 * Cobro en linea de la renta del SaaS con Stripe, usando la `secret_key` de LA
 * PLATAFORMA: abre una sesión de Checkout y devuelve `pendiente` con la URL; el
 * webhook firmado confirma. Sin llave no cobra (la pasarela no está lista).
 */
class PasarelaStripePlataforma implements PasarelaPlataforma
{
    public function nombre(): string
    {
        return 'stripe';
    }

    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago
    {
        $secretKey = $llaves['secret_key'] ?? '';

        if ($secretKey === '') {
            throw new PasarelaNoDisponible('Stripe no tiene llaves configuradas.');
        }

        $retorno = RetornoPago::urls($cargo->retorno ?? '/renta');
        $cargo->loadMissing('estudio');

        $sesion = (new ClienteStripe($secretKey))->crearSesionCheckout(
            $cargo->monto_minor,
            $cargo->moneda,
            ReciboRentaPdf::concepto($cargo),
            $retorno['exito'],
            $retorno['cancelado'],
            'tarjeta',
            $cargo->estudio?->contacto_email,
            ['cargo_renta' => (string) $cargo->ulid],
        );

        return ResultadoPago::pendiente($sesion['id'], [
            'tipo' => 'redirect',
            'url' => $sesion['url'],
        ]);
    }

    /**
     * Cómo quedó un intento de pago de la renta en Stripe: `pagado`, `terminado` (ya
     * no se puede pagar) o `en_proceso` (abierto, o un pago en tienda sin completar).
     *
     * @param  array<string, string>  $llaves
     */
    public function estadoIntento(string $referencia, array $llaves): string
    {
        $secretKey = $llaves['secret_key'] ?? '';
        // Un cargo a la tarjeta domiciliada (ADR 0107).
        if (str_starts_with($referencia, 'pi_') && $secretKey !== '') {
            return match ((new ClienteStripe($secretKey))->estadoIntent($referencia)) {
                'succeeded' => 'pagado',
                'canceled', 'requires_payment_method' => 'terminado',
                default => 'en_proceso',
            };
        }
        if (! str_starts_with($referencia, 'cs_') || $secretKey === '') {
            return 'en_proceso';
        }
        $sesion = (new ClienteStripe($secretKey))->sesion($referencia);

        return match (true) {
            in_array($sesion['pago'], ['paid', 'no_payment_required'], true) => 'pagado',
            $sesion['estado'] === 'expired',
            $sesion['estado'] === 'complete' && in_array($sesion['cobro'], ['canceled', 'requires_payment_method'], true) => 'terminado',
            default => 'en_proceso',
        };
    }

    /**
     * Vence el intento anterior de pago de la renta (sesión abierta). `false` si ya
     * se pagó y hay que esperar su confirmación.
     *
     * @param  array<string, string>  $llaves
     */
    public function cancelarIntento(string $referencia, array $llaves): bool
    {
        $secretKey = $llaves['secret_key'] ?? '';
        // Un cargo a la tarjeta en proceso: solo se anula si aún no se cobra.
        if (str_starts_with($referencia, 'pi_') && $secretKey !== '') {
            return (new ClienteStripe($secretKey))->anularIntent($referencia);
        }
        if (! str_starts_with($referencia, 'cs_') || $secretKey === '') {
            return true;
        }

        return (new ClienteStripe($secretKey))->expirarSesion($referencia) !== 'complete';
    }
}
