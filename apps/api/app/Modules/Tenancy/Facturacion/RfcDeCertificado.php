<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * El RFC de un sello digital (CSD) del SAT, leído del propio certificado (ADR 0228): el
 * primer dato de `x500UniqueIdentifier` en su sujeto («RFC / CURP» o, en una persona
 * moral, «RFC / RFC del representante»). Sirve para rechazar un sello de otro RFC antes
 * de subirlo: en FacturAPI, subir un certificado reemplaza al que ya tiene la
 * organización.
 *
 * Null si no se puede leer: decide lo que responda FacturAPI.
 */
final class RfcDeCertificado
{
    private const RFC = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u';

    public static function leer(string $certificado): ?string
    {
        // El .cer del SAT viene en DER; también se acepta en PEM.
        $pem = str_contains($certificado, '-----BEGIN CERTIFICATE-----')
            ? $certificado
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($certificado), 64, "\n")."-----END CERTIFICATE-----\n";
        $datos = openssl_x509_parse($pem);
        $identificador = is_array($datos) ? ($datos['subject']['x500UniqueIdentifier'] ?? null) : null;
        if (! is_string($identificador)) {
            return null;
        }
        $rfc = mb_strtoupper(trim(explode('/', $identificador)[0]));

        return preg_match(self::RFC, $rfc) === 1 ? $rfc : null;
    }
}
