<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\TipoCambioNoDisponible;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonInterface;

/**
 * En qué moneda se cobra la renta y con qué IVA (ADR 0107). Las tarifas se publican
 * en dólares: a un negocio de México se le cobra en pesos, al tipo de cambio del día
 * en que se emite el cargo, con el IVA de México; fuera de México, en dólares y con
 * el IVA de exportación de la tarifa (`iva_porcentaje_extranjero`). Lo que ya está en
 * pesos (tarifas anteriores, una cuota fija pactada en pesos) se cobra tal cual.
 *
 * @phpstan-import-type Desglose from CalcularRentaSaas
 */
class MonedaDeCobroSaas
{
    public function __construct(
        private readonly CalcularRentaSaas $calcular,
        private readonly TiposDeCambio $tipos,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * El cargo mínimo (total con IVA) que se cobra en esa moneda: Stripe no acepta
     * cobros menores (10 pesos, 50 centavos de dólar). Lo fija la plataforma.
     */
    public function cargoMinimo(string $moneda): int
    {
        return match (strtoupper($moneda)) {
            'MXN' => max(1, $this->parametros->entero('renta.cargo_minimo_mxn_centavos')),
            'USD' => max(1, $this->parametros->entero('renta.cargo_minimo_usd_centavos')),
            default => 1,
        };
    }

    /** ¿Ese total (en la moneda de cobro) llega al mínimo que se cobra? */
    public function cobrable(int $totalMinor, string $moneda): bool
    {
        return $totalMinor > 0 && $totalMinor >= $this->cargoMinimo($moneda);
    }

    /**
     * La moneda de una tarifa: las anteriores al ADR 0107 están en pesos.
     *
     * @param  array<string, mixed>  $definicion
     */
    public static function deTarifa(array $definicion): string
    {
        return is_string($definicion['moneda'] ?? null) ? $definicion['moneda'] : 'MXN';
    }

    /** En qué moneda se le cobra a un negocio lo que está en `$monedaTarifa`. */
    public static function deCobro(Estudio $estudio, string $monedaTarifa): string
    {
        return $monedaTarifa === 'USD' && $estudio->enMexico() ? 'MXN' : $monedaTarifa;
    }

    /**
     * La definición de la tarifa con el IVA que le toca al negocio por su país.
     *
     * @param  array<string, mixed>  $definicion
     * @return array<string, mixed>
     */
    public static function conIvaDelPais(array $definicion, Estudio $estudio): array
    {
        if (! $estudio->enMexico() && array_key_exists('iva_porcentaje_extranjero', $definicion)) {
            $definicion['iva_porcentaje'] = (int) $definicion['iva_porcentaje_extranjero'];
        }

        return $definicion;
    }

    /**
     * Pasa el desglose (en la moneda de la tarifa) a la moneda de cobro. Devuelve el
     * desglose final y las columnas del cargo con lo que costó en la moneda de la tarifa
     * y el tipo de cambio aplicado.
     *
     * Al ESTIMAR (`$estimacion`), si no hay tipo de cambio, se muestra en dólares en
     * vez de fallar; al emitir un cargo, falla y el cargo espera.
     *
     * @param  Desglose  $desglose
     * @return array{desglose: Desglose, columnas: array{moneda: string, monto_tarifa_minor: int, moneda_tarifa: string, tipo_cambio_diezmilesimas: int|null, tipo_cambio_fecha: string|null, tipo_cambio_fuente: string|null}}
     *
     * @throws TipoCambioNoDisponible
     */
    public function aplicar(Estudio $estudio, array $desglose, string $monedaTarifa, CarbonInterface $fecha, bool $estimacion = false): array
    {
        $moneda = self::deCobro($estudio, $monedaTarifa);
        $columnas = [
            'moneda' => $moneda,
            'monto_tarifa_minor' => $desglose['total_minor'],
            'moneda_tarifa' => $monedaTarifa,
            'tipo_cambio_diezmilesimas' => null,
            'tipo_cambio_fecha' => null,
            'tipo_cambio_fuente' => null,
        ];

        // Misma moneda, o nada que convertir (un cargo en cero ya es de la moneda de cobro).
        if ($moneda === $monedaTarifa || $desglose['total_minor'] === 0) {
            return ['desglose' => [...$desglose, 'moneda' => $moneda, 'conversion' => null], 'columnas' => $columnas];
        }

        try {
            $tipo = $this->tipos->usdMxn($fecha, alertar: ! $estimacion);
        } catch (TipoCambioNoDisponible $e) {
            if (! $estimacion) {
                throw $e;
            }
            $columnas['moneda'] = $monedaTarifa;

            return ['desglose' => [...$desglose, 'moneda' => $monedaTarifa, 'conversion' => null], 'columnas' => $columnas];
        }

        $convertido = $this->calcular->convertir($desglose, $tipo['diezmilesimas']);
        $convertido['moneda'] = $moneda;
        $convertido['conversion'] = [
            'de' => $monedaTarifa,
            'a' => $moneda,
            'tipo_cambio' => TiposDeCambio::formatear($tipo['diezmilesimas']),
            'fecha' => $tipo['fecha'],
            'fuente' => $tipo['fuente'],
            'subtotal_origen_minor' => $desglose['subtotal_minor'],
            'total_origen_minor' => $desglose['total_minor'],
        ];

        return ['desglose' => $convertido, 'columnas' => [
            ...$columnas,
            'tipo_cambio_diezmilesimas' => $tipo['diezmilesimas'],
            'tipo_cambio_fecha' => $tipo['fecha'],
            'tipo_cambio_fuente' => $tipo['fuente'],
        ]];
    }
}
