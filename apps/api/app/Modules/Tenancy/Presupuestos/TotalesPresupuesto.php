<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presupuestos;

/**
 * Las cuentas de un presupuesto, en centavos (sin flotantes):
 * - cada línea: cantidad × precio unitario − su descuento (nunca menos de cero);
 * - base: la suma de las líneas − el descuento general (nunca menos de cero);
 * - desglosados: impuesto = base × tasa; total = base + impuesto;
 * - incluidos: total = base; el impuesto que contiene = base − base ÷ (1 + tasa).
 * La tasa va en puntos base (1600 = 16 %).
 */
final readonly class TotalesPresupuesto
{
    public function __construct(
        public int $subtotalMinor,
        public int $descuentoMinor,
        public int $impuestoMinor,
        public int $totalMinor,
    ) {}

    public static function linea(int $cantidad, int $precioUnitarioMinor, int $descuentoMinor): int
    {
        return max(0, $cantidad * $precioUnitarioMinor - max(0, $descuentoMinor));
    }

    /**
     * @param  list<int>  $subtotalesDeLineas
     */
    public static function calcular(array $subtotalesDeLineas, int $descuentoMinor, ModoImpuestos $modo, int $tasaBp): self
    {
        $subtotal = array_sum($subtotalesDeLineas);
        $descuento = min(max(0, $descuentoMinor), $subtotal);
        $base = $subtotal - $descuento;
        $tasa = max(0, $tasaBp);

        if ($modo === ModoImpuestos::Desglosados) {
            $impuesto = intdiv($base * $tasa + 5000, 10000);

            return new self($subtotal, $descuento, $impuesto, $base + $impuesto);
        }

        $sinImpuesto = $tasa === 0 ? $base : intdiv($base * 10000 + intdiv(10000 + $tasa, 2), 10000 + $tasa);

        return new self($subtotal, $descuento, $base - $sinImpuesto, $base);
    }
}
