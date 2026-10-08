<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

/**
 * Verifica la firma de un webhook de Stripe (cabecera `Stripe-Signature`):
 * `HMAC-SHA256(t.'.'.cuerpo, webhook_secret)` comparado con cada `v1` (al rotar el
 * secreto, Stripe manda más de una). Un aviso firmado hace más de 5 minutos se
 * rechaza: no se acepta repetir uno viejo.
 */
class VerificarFirmaStripe
{
    /** Segundos de diferencia aceptados entre la firma y el reloj del servidor. */
    public const TOLERANCIA = 300;

    public static function valida(string $cuerpo, ?string $encabezado, string $secreto): bool
    {
        if ($encabezado === null || $encabezado === '') {
            return false;
        }

        $marca = '';
        $firmas = [];
        foreach (explode(',', $encabezado) as $segmento) {
            [$clave, $valor] = array_pad(explode('=', trim($segmento), 2), 2, '');
            if ($clave === 't') {
                $marca = $valor;
            } elseif ($clave === 'v1' && $valor !== '') {
                $firmas[] = $valor;
            }
        }

        if (! ctype_digit($marca) || $firmas === []) {
            return false;
        }
        if (abs(now()->getTimestamp() - (int) $marca) > self::TOLERANCIA) {
            return false;
        }

        $esperada = hash_hmac('sha256', $marca.'.'.$cuerpo, $secreto);
        foreach ($firmas as $firma) {
            if (hash_equals($esperada, $firma)) {
                return true;
            }
        }

        return false;
    }
}
