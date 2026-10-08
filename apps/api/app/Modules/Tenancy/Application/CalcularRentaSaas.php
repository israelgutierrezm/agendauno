<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Calcula el cargo mensual del SaaS a partir del uso medido y de la tarifa vigente
 * (ADR 0019). Puro y determinista; dinero en minor (enteros), nunca float.
 * Devuelve un DESGLOSE legible (líneas + subtotal + IVA + total) que se guarda con
 * el cargo y se muestra al dueño.
 *
 * @phpstan-type Linea array{concepto: string, detalle: string, importe_minor: int}
 * @phpstan-type Desglose array{lineas: list<Linea>, subtotal_minor: int, iva_porcentaje: int, iva_minor: int, total_minor: int, prorrateo?: array{dias_cobrables: int, dias_periodo: int}, moneda?: string, conversion?: array<string, mixed>|null, cubre?: array{desde: string, hasta: string}}
 */
class CalcularRentaSaas
{
    /**
     * Clases: el precio de la banda en la que cae el número de alumnos activos (la
     * última banda, sin tope, es el techo). Sin alumnos activos no hay cargo.
     *
     * @param  array<string, mixed>  $definicion
     * @return Desglose
     */
    public function clases(array $definicion, int $alumnos): array
    {
        $lineas = [];
        if ($alumnos > 0) {
            $desde = 1;
            foreach ($this->lista($definicion['bandas'] ?? []) as $banda) {
                $hasta = isset($banda['hasta']) ? (int) $banda['hasta'] : null;
                if ($hasta === null || $alumnos <= $hasta) {
                    $lineas[] = [
                        'concepto' => 'Alumnos activos: '.$alumnos,
                        'detalle' => $hasta === null ? 'Plan de más de '.($desde - 1).' alumnos (tope)' : 'Plan de '.$desde.' a '.$hasta.' alumnos',
                        'importe_minor' => (int) ($banda['monto_minor'] ?? 0),
                    ];
                    break;
                }
                $desde = $hasta + 1;
            }
        }

        return $this->totalizar($lineas, $definicion);
    }

    /**
     * Citas: precio MARGINAL por profesional activo (el 1º cuesta más que el 2º, etc.).
     * Cada profesional cuenta completo, sin importar sus horas (ADR 0094). Más la regla
     * híbrida: cada profesional incluye N personas atendidas fuera de cita (hasta un
     * tope); las personas adicionales se cobran por unidad.
     *
     * @param  array<string, mixed>  $definicion
     * @return Desglose
     */
    public function citas(array $definicion, int $profesionales, int $personasFueraDeCita): array
    {
        $lineas = [];
        $restante = max(0, $profesionales);
        $previo = 0;
        foreach ($this->lista($definicion['tramos'] ?? []) as $tramo) {
            if ($restante <= 0) {
                break;
            }
            $hasta = isset($tramo['hasta']) ? (int) $tramo['hasta'] : null;
            $capacidad = $hasta === null ? $restante : max(0, $hasta - $previo);
            $tomado = min($restante, $capacidad);
            $unitario = (int) ($tramo['unitario_minor'] ?? 0);
            if ($tomado > 0 && $unitario > 0) {
                $lineas[] = [
                    'concepto' => 'Profesionales '.$this->rango($previo + 1, $hasta).': '.$tomado,
                    'detalle' => 'Precio por profesional en este tramo',
                    'importe_minor' => $tomado * $unitario,
                ];
            }
            $restante -= $tomado;
            $previo = $hasta ?? $previo;
        }

        $porProfesional = (int) ($definicion['personas_incluidas_por_profesional'] ?? 0);
        $tope = (int) ($definicion['tope_personas_incluidas'] ?? 0);
        $incluidas = min(max(0, $profesionales) * $porProfesional, $tope);
        $extra = max(0, $personasFueraDeCita - $incluidas);
        if ($extra > 0) {
            $lineas[] = [
                'concepto' => 'Personas atendidas fuera de cita: '.$personasFueraDeCita,
                'detalle' => $incluidas.' incluidas con tus profesionales; '.$extra.' adicionales',
                'importe_minor' => $extra * (int) ($definicion['extra_por_persona_minor'] ?? 0),
            ];
        }

        return $this->totalizar($lineas, $definicion);
    }

    /**
     * Citas por plan (ADR 0107): el precio mensual del nivel con los profesionales
     * contratados; el anual cuesta `meses_anual` meses (2 de cortesía).
     *
     * @param  array<string, mixed>  $definicion
     * @return Desglose
     */
    public function plan(array $definicion, string $nivel, int $profesionales, bool $anual, string $detalle): array
    {
        $mensual = self::precioPlan($definicion, $nivel, $profesionales) ?? 0;
        $meses = $anual ? self::mesesAnual($definicion) : 1;
        $lineas = $mensual > 0 ? [[
            'concepto' => 'Plan '.self::nombreNivel($nivel).' · '.$profesionales.' '.($profesionales === 1 ? 'profesional' : 'profesionales'),
            'detalle' => $detalle,
            'importe_minor' => $mensual * $meses,
        ]] : [];

        return $this->totalizar($lineas, $definicion);
    }

    /**
     * Precio MENSUAL (minor, sin IVA, en la moneda de la tarifa) de un nivel con esos
     * profesionales; null si la tarifa no lo tiene (p. ej. más de 20: cotización).
     *
     * @param  array<string, mixed>  $definicion
     */
    public static function precioPlan(array $definicion, string $nivel, int $profesionales): ?int
    {
        $precios = $definicion['niveles'][$nivel] ?? null;
        if (! is_array($precios)) {
            return null;
        }
        $precio = $precios[(string) $profesionales] ?? null;

        return is_numeric($precio) ? (int) $precio : null;
    }

    /**
     * Cuántos meses cuesta el pago anual.
     *
     * @param  array<string, mixed>  $definicion
     */
    public static function mesesAnual(array $definicion): int
    {
        return max(1, (int) ($definicion['meses_anual'] ?? 10));
    }

    public static function nombreNivel(string $nivel): string
    {
        return match ($nivel) {
            'individual' => 'Individual',
            'premium' => 'Premium',
            'pro' => 'Pro',
            default => ucfirst($nivel),
        };
    }

    /**
     * Pasa el desglose a otra moneda con un tipo de cambio en diezmilésimas: cada línea
     * y el subtotal se convierten (al centavo) y el IVA se calcula sobre el subtotal ya
     * convertido.
     *
     * @param  Desglose  $desglose
     * @return Desglose
     */
    public function convertir(array $desglose, int $diezmilesimas): array
    {
        $lineas = array_map(static fn (array $l): array => [
            ...$l,
            'importe_minor' => TiposDeCambio::convertir($l['importe_minor'], $diezmilesimas),
        ], $desglose['lineas']);
        $subtotal = TiposDeCambio::convertir($desglose['subtotal_minor'], $diezmilesimas);
        $iva = $this->iva($subtotal, $desglose['iva_porcentaje']);

        return [
            ...$desglose,
            'lineas' => $lineas,
            'subtotal_minor' => $subtotal,
            'iva_minor' => $iva,
            'total_minor' => $subtotal + $iva,
        ];
    }

    /**
     * Cuota fija acordada por la plataforma (el monto ya es el total, IVA incluido).
     *
     * @return Desglose
     */
    public function fija(int $cuotaMinor): array
    {
        $lineas = $cuotaMinor > 0
            ? [['concepto' => 'Cuota fija mensual', 'detalle' => 'Acordada con AgendaUno (IVA incluido)', 'importe_minor' => $cuotaMinor]]
            : [];

        return ['lineas' => $lineas, 'subtotal_minor' => $cuotaMinor, 'iva_porcentaje' => 0, 'iva_minor' => 0, 'total_minor' => $cuotaMinor];
    }

    /**
     * Cobra solo los días del periodo posteriores a la prueba gratis (proporcional).
     *
     * @param  Desglose  $desglose
     * @return Desglose
     */
    public function prorratear(array $desglose, int $diasCobrables, int $diasPeriodo): array
    {
        if ($diasPeriodo <= 0 || $diasCobrables >= $diasPeriodo) {
            return $desglose;
        }

        $subtotal = intdiv($desglose['subtotal_minor'] * max(0, $diasCobrables), $diasPeriodo);
        $iva = $this->iva($subtotal, $desglose['iva_porcentaje']);

        return [
            ...$desglose,
            'subtotal_minor' => $subtotal,
            'iva_minor' => $iva,
            'total_minor' => $subtotal + $iva,
            'prorrateo' => ['dias_cobrables' => max(0, $diasCobrables), 'dias_periodo' => $diasPeriodo],
        ];
    }

    /**
     * Suma las líneas y les pone el IVA de la definición.
     *
     * @param  list<Linea>  $lineas
     * @param  array<string, mixed>  $definicion
     * @return Desglose
     */
    public function totalizar(array $lineas, array $definicion): array
    {
        $subtotal = array_sum(array_column($lineas, 'importe_minor'));
        $porcentaje = (int) ($definicion['iva_porcentaje'] ?? 16);
        $iva = $this->iva($subtotal, $porcentaje);

        return [
            'lineas' => $lineas,
            'subtotal_minor' => $subtotal,
            'iva_porcentaje' => $porcentaje,
            'iva_minor' => $iva,
            'total_minor' => $subtotal + $iva,
        ];
    }

    /**
     * IVA redondeado al centavo (medio hacia arriba), en enteros.
     */
    private function iva(int $subtotal, int $porcentaje): int
    {
        return intdiv($subtotal * $porcentaje + 50, 100);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lista(mixed $valor): array
    {
        return is_array($valor) ? array_values(array_filter($valor, 'is_array')) : [];
    }

    private function rango(int $desde, ?int $hasta): string
    {
        if ($hasta === null) {
            return 'desde el '.$desde.'º';
        }

        return $desde === $hasta ? $desde.'º' : $desde.'º a '.$hasta.'º';
    }
}
