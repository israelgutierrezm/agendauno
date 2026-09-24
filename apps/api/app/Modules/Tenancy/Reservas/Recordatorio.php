<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

/**
 * Avisos previos a una clase o cita. Cada uno se emite una sola vez por reserva
 * (marca en `reservas.recordatorio_{valor}_en`) como el evento `reserva.recordatorio_{valor}`,
 * que las plantillas de mensajes automáticos convierten en correo.
 */
enum Recordatorio: string
{
    case UnDia = '24h';
    case DosHoras = '2h';

    /**
     * Minutos antes del inicio en que se envía.
     */
    public function minutos(): int
    {
        return match ($this) {
            self::UnDia => 24 * 60,
            self::DosHoras => 2 * 60,
        };
    }

    /**
     * Aviso más cercano al inicio: acota la ventana de este para que, si el envío se
     * atrasó, no lleguen los dos juntos.
     */
    public function siguiente(): ?self
    {
        return match ($this) {
            self::UnDia => self::DosHoras,
            self::DosHoras => null,
        };
    }

    public function columna(): string
    {
        return 'recordatorio_'.$this->value.'_en';
    }

    public function evento(): string
    {
        return 'reserva.recordatorio_'.$this->value;
    }
}
