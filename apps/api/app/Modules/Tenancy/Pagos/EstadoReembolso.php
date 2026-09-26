<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Estado de una devolución (refund) de un pago. Manual/efectivo se aprueba en el
 * momento (dinero devuelto en caja). Una devolución en línea se registra
 * `solicitado` antes de pedirla a la pasarela; luego queda `aprobado`, `pendiente`
 * (la pasarela la confirma después), `fallido` (la pasarela dijo que no) o
 * `incierto` (no respondió: pudo haber devuelto; se confirma con la misma llave).
 */
enum EstadoReembolso: string
{
    case Solicitado = 'solicitado';
    case Pendiente = 'pendiente';
    case Incierto = 'incierto';
    case Aprobado = 'aprobado';
    case Fallido = 'fallido';

    /**
     * Los que ya comprometen dinero del pago: no se puede devolver de más contándolos.
     *
     * @return list<string>
     */
    public static function comprometidos(): array
    {
        return [self::Solicitado->value, self::Pendiente->value, self::Incierto->value, self::Aprobado->value];
    }

    /**
     * ¿Ya no cambia? (aprobada o fallida).
     */
    public function esFinal(): bool
    {
        return $this === self::Aprobado || $this === self::Fallido;
    }
}
