<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * Resultado de un intento de cobro devuelto por una pasarela. `datos` lleva la
 * información que el cliente necesita para completar el pago (client_secret,
 * init_point de redirección, referencia de voucher…).
 */
final readonly class ResultadoPago
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function __construct(
        public EstadoResultado $estado,
        public ?string $referencia = null,
        public ?string $motivo = null,
        public array $datos = [],
    ) {}

    public static function aprobado(string $referencia): self
    {
        return new self(EstadoResultado::Aprobado, $referencia);
    }

    public static function rechazado(string $motivo): self
    {
        return new self(EstadoResultado::Rechazado, null, $motivo);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public static function pendiente(string $referencia, array $datos = []): self
    {
        return new self(EstadoResultado::Pendiente, $referencia, null, $datos);
    }

    public function esAprobado(): bool
    {
        return $this->estado === EstadoResultado::Aprobado;
    }

    public function esPendiente(): bool
    {
        return $this->estado === EstadoResultado::Pendiente;
    }
}
