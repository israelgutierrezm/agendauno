<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

use App\Modules\Tenancy\Application\ParametrosTenant;

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
     * Minutos antes del inicio en que se envía (horas configurables, ADR 0042). El
     * primero es el "24 h" y el segundo el "2 h" de sus nombres históricos; 0 = el
     * segundo no se envía.
     */
    public function minutos(ParametrosTenant $parametros): int
    {
        return 60 * match ($this) {
            self::UnDia => $parametros->entero('recordatorios.primero_horas'),
            self::DosHoras => $parametros->entero('recordatorios.segundo_horas'),
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
