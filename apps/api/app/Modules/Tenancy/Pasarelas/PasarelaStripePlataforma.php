<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

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
            'Renta de AgendaUno · '.$cargo->periodo,
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
     * Vence el intento anterior de pago de la renta (sesión abierta). `false` si ya
     * se pagó y hay que esperar su confirmación.
     *
     * @param  array<string, string>  $llaves
     */
    public function cancelarIntento(string $referencia, array $llaves): bool
    {
        $secretKey = $llaves['secret_key'] ?? '';
        if (! str_starts_with($referencia, 'cs_') || $secretKey === '') {
            return true;
        }

        return (new ClienteStripe($secretKey))->expirarSesion($referencia) !== 'complete';
    }
}
