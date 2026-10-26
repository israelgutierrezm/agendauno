<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * Cómo lleva el IVA un concepto del CFDI (ADR 0212): el general del negocio, exento
 * (p. ej. servicios médicos) o a tasa cero. Lo define cada producto, servicio o artículo;
 * sin dato, el general.
 */
enum TratamientoIva: string
{
    case General = 'general';
    case Exento = 'exento';
    case Cero = 'cero';

    public static function de(mixed $valor): self
    {
        return is_string($valor) ? (self::tryFrom($valor) ?? self::General) : self::General;
    }

    /** La tasa que de verdad se cobra, en puntos base (1600 = 16 %). */
    public function bps(int $ivaGeneralBps): int
    {
        return $this === self::General ? $ivaGeneralBps : 0;
    }

    /**
     * Los impuestos del concepto como los pide FacturAPI.
     *
     * @return list<array<string, mixed>>
     */
    public function impuestos(int $ivaGeneralBps): array
    {
        return match ($this) {
            self::General => [['type' => 'IVA', 'rate' => $ivaGeneralBps / 10000]],
            self::Cero => [['type' => 'IVA', 'rate' => 0]],
            self::Exento => [['type' => 'IVA', 'factor' => 'Exento']],
        };
    }
}
