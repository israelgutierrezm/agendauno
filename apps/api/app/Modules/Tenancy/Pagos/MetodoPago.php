<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Método de pago elegido para un cobro. Los métodos disponibles dependen del
 * proveedor (p. ej. OXXO/SPEI los ofrecen las pasarelas en línea).
 */
enum MetodoPago: string
{
    case Tarjeta = 'tarjeta';
    case Oxxo = 'oxxo';
    case Spei = 'spei';
    case Efectivo = 'efectivo';
    case Ventanilla = 'ventanilla';
    // Cobro en caja con varias formas (ADR 0177): sus partes dicen cuánto en cada una.
    case Mixto = 'mixto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Tarjeta => 'Tarjeta',
            self::Oxxo => 'OXXO',
            self::Spei => 'Transferencia SPEI',
            self::Efectivo => 'Efectivo',
            self::Ventanilla => 'Ventanilla',
            self::Mixto => 'Mixto',
        };
    }
}
