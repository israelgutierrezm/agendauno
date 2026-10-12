<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Las llaves de la pasarela de la plataforma cambian de modo (prueba ↔ real): eso
 * quita las tarjetas y los pagos en curso de todos los negocios. Se pide confirmarlo
 * (`confirmar_cambio_modo`; ADR 0132).
 */
class CambioDeModoSinConfirmar extends TenancyException
{
    public function __construct(private readonly string $de, private readonly string $a)
    {
        parent::__construct("Cambiar de {$de} a {$a} quita las tarjetas guardadas y los pagos en curso de todos los negocios. Confírmalo para continuar.");
    }

    public function codigo(): string
    {
        return 'GATEWAY_MODE_CHANGE_UNCONFIRMED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    public function meta(): array
    {
        return ['de' => $this->de, 'a' => $this->a];
    }
}
