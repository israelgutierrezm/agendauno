<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\MercadoPago;

/**
 * Firma de las notificaciones (webhooks) de Mercado Pago: `x-signature` trae
 * `ts=…,v1=…`; `v1` es el HMAC-SHA256 (hex) con la clave secreta del webhook sobre
 * `id:{data.id};request-id:{x-request-id};ts:{ts};`. El `data.id` es el de la URL,
 * en minúsculas; si falta un dato, su parte se quita del manifiesto.
 */
final class VerificarFirmaMercadoPago
{
    public static function valida(?string $dataId, ?string $requestId, ?string $xSignature, string $secreto): bool
    {
        if ($xSignature === null || $xSignature === '' || $secreto === '') {
            return false;
        }

        $partes = [];
        foreach (explode(',', $xSignature) as $segmento) {
            [$clave, $valor] = array_pad(explode('=', trim($segmento), 2), 2, '');
            $partes[trim($clave)] = trim($valor);
        }
        $marca = $partes['ts'] ?? '';
        $firma = $partes['v1'] ?? '';
        if ($marca === '' || $firma === '') {
            return false;
        }

        $manifiesto = '';
        if ($dataId !== null && $dataId !== '') {
            $manifiesto .= 'id:'.strtolower($dataId).';';
        }
        if ($requestId !== null && $requestId !== '') {
            $manifiesto .= 'request-id:'.$requestId.';';
        }
        $manifiesto .= 'ts:'.$marca.';';

        return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), $firma);
    }
}
