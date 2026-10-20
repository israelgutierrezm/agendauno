<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El proveedor de facturación no respondió a tiempo (o respondió con un error suyo): no
 * se sabe si timbró. Reintentar es seguro: la factura va con su llave de idempotencia y,
 * si ya se había timbrado, el proveedor devuelve la misma.
 */
class FacturacionSinRespuesta extends TenancyException
{
    public function __construct(string $mensaje = 'El servicio de facturación no respondió. Vuelve a intentarlo: si la factura ya se timbró, se registrará la misma, sin hacer otra.')
    {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return 'INVOICING_NO_RESPONSE';
    }

    public function estadoHttp(): int
    {
        return 503;
    }
}
