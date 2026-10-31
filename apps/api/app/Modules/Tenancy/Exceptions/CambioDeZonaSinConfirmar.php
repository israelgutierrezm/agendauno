<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Cambiar la zona horaria de una sede con fechas por venir las re-ancla y avisa a quien
 * tiene reserva: sin confirmarlo (`confirmar_zona`) no cambia nada y se responde cuántas
 * son, para que la pantalla pregunte (ADR 0230).
 */
class CambioDeZonaSinConfirmar extends TenancyException
{
    public function __construct(
        private readonly string $zonaActual,
        private readonly string $zonaNueva,
        private readonly int $sesiones,
        private readonly int $reservas,
    ) {
        parent::__construct("Cambiar la zona de esta sucursal mueve {$sesiones} fecha(s) con {$reservas} reserva(s): conservan su hora de reloj y se avisa a cada persona. Confírmalo para continuar.");
    }

    public function codigo(): string
    {
        return 'TIMEZONE_CHANGE_UNCONFIRMED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    public function meta(): array
    {
        return [
            'zona_actual' => $this->zonaActual,
            'zona_nueva' => $this->zonaNueva,
            'sesiones' => $this->sesiones,
            'reservas' => $this->reservas,
        ];
    }
}
