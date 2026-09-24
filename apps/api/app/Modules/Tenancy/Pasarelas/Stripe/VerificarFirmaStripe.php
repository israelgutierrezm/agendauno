<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

/**
 * Verifica la firma de un webhook de Stripe (cabecera `Stripe-Signature`):
 * `HMAC-SHA256(t.'.'.cuerpo, webhook_secret)` comparado con `v1`.
 */
class VerificarFirmaStripe
{
    public static function valida(string $cuerpo, ?string $encabezado, string $secreto): bool
    {
        if ($encabezado === null || $encabezado === '') {
            return false;
        }

        $partes = [];
        foreach (explode(',', $encabezado) as $segmento) {
            [$clave, $valor] = array_pad(explode('=', trim($segmento), 2), 2, '');
            $partes[$clave] = $valor;
        }

        $marca = $partes['t'] ?? '';
        $firma = $partes['v1'] ?? '';
        if ($marca === '' || $firma === '') {
            return false;
        }

        $esperada = hash_hmac('sha256', $marca.'.'.$cuerpo, $secreto);

        return hash_equals($esperada, $firma);
    }
}
