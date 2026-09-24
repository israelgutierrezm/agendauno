<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;
use Illuminate\Support\Str;

/**
 * Cobro en linea con Stripe usando la `secret_key` del estudio. Con llave crea un
 * PaymentIntent real (reusa ClienteStripe, testeable con Http::fake) y devuelve
 * `pendiente` con el client_secret para Stripe.js; el webhook firmado confirma.
 * SIN llave devuelve un intento simulado pendiente: el flujo queda listo para
 * activarse en cuanto el estudio cargue sus llaves.
 */
class PasarelaStripeTenant implements PasarelaReembolsable, PasarelaTenant
{
    public function nombre(): string
    {
        return 'stripe';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $secretKey = $llaves['secret_key'] ?? '';

        if ($secretKey === '') {
            // Listo para llaves: intento simulado pendiente (lo confirma el webhook).
            return ResultadoPago::pendiente('stripe_sim_'.Str::lower(Str::random(24)));
        }

        $orden = $pago->orden()->with(['lineas.producto', 'persona'])->first();
        $retorno = RetornoPago::urls($pago->retorno);

        $sesion = (new ClienteStripe($secretKey))->crearSesionCheckout(
            $pago->monto_minor,
            $pago->moneda,
            self::concepto($orden),
            $retorno['exito'],
            $retorno['cancelado'],
            $pago->metodo?->value,
            $orden?->persona?->email,
            ['pago' => (string) $pago->ulid],
        );

        return ResultadoPago::pendiente($sesion['id'], [
            'tipo' => 'redirect',
            'url' => $sesion['url'],
        ]);
    }

    /**
     * Devuelve dinero del cobro en Stripe. La referencia del pago es la sesión de
     * Checkout (cs_…) o, en cobros anteriores, el PaymentIntent (pi_…).
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves): ResultadoPago
    {
        $secretKey = $llaves['secret_key'] ?? '';
        if ($secretKey === '') {
            throw new PasarelaNoDisponible('Stripe no tiene llaves configuradas.');
        }

        $cliente = new ClienteStripe($secretKey);
        $referencia = (string) $pago->referencia_externa;
        $intent = str_starts_with($referencia, 'cs_') ? $cliente->paymentIntentDeSesion($referencia) : $referencia;
        if (! is_string($intent) || ! str_starts_with($intent, 'pi_')) {
            throw new PasarelaNoDisponible('No se encontró el cobro en Stripe.');
        }

        $reembolso = $cliente->crearReembolso(
            $intent,
            $montoMinor,
            'reembolso_'.$pago->ulid.'_'.Str::lower((string) Str::ulid()),
            ['pago' => (string) $pago->ulid],
        );

        return match ($reembolso['status']) {
            'succeeded' => ResultadoPago::aprobado($reembolso['id']),
            'pending', 'requires_action' => ResultadoPago::pendiente($reembolso['id']),
            default => ResultadoPago::rechazado('Stripe rechazó la devolución ('.$reembolso['status'].').'),
        };
    }

    /**
     * Lo que ve el cliente en la página de pago: los productos de la orden, o que es
     * una cita.
     */
    private static function concepto(?OrdenTenant $orden): string
    {
        $nombres = $orden?->lineas
            ->map(static fn (LineaOrdenTenant $l): ?string => $l->producto?->nombre)
            ->filter()
            ->unique()
            ->implode(', ');

        if (is_string($nombres) && $nombres !== '') {
            return Str::limit($nombres, 120);
        }

        return $orden?->sesion_id !== null ? 'Cita' : 'Compra';
    }
}
