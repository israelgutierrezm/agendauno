<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\ModoCobroSaas;
use Carbon\CarbonImmutable;

/**
 * Genera (o actualiza) el cargo de renta del SaaS de un estudio para un periodo, MES
 * VENCIDO (ADR 0019): un periodo ya cerrado se factura con su medición CONGELADA; el
 * monto sale de la tarifa vigente de su modalidad (o de la cuota fija que pactó la
 * plataforma), sin cobrar los días de prueba gratis. Un periodo sin nada que cobrar
 * queda como `sin_cargo`. Idempotente por (estudio, periodo); no pisa un cargo pagado.
 *
 * @phpstan-import-type Desglose from CalcularRentaSaas
 */
class GenerarCargoRenta
{
    public function __construct(
        private readonly MedirUsoSaas $medir,
        private readonly CalcularRentaSaas $calcular,
    ) {}

    public function paraEstudio(Estudio $estudio, string $periodo): CargoRenta
    {
        $existente = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('periodo', $periodo)
            ->first();

        // Un cargo ya pagado no se re-genera.
        if ($existente !== null && $existente->estado === EstadoCargoRenta::Pagado) {
            return $existente;
        }

        // Periodo cerrado → se congela su medición (lo facturado no cambia después).
        $cerrado = $periodo < CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'))->format('Y-m');
        $medicion = $cerrado ? $this->medir->congelar($estudio, $periodo) : $this->medir->ejecutar($estudio, $periodo);

        [$desglose, $version] = $this->cotizar($estudio, $medicion->metrica, $medicion->cantidad, $medicion->detalle ?? [], $periodo);

        return CargoRenta::query()->updateOrCreate(
            ['estudio_id' => $estudio->getKey(), 'periodo' => $periodo],
            [
                'modo_cobro' => $estudio->modo_cobro->value,
                'metrica' => $medicion->metrica,
                'alumnos_activos' => $medicion->cantidad,
                'tarifa_version' => $version,
                'desglose' => $desglose,
                'monto_minor' => $desglose['total_minor'],
                'moneda' => $estudio->moneda,
                'estado' => $desglose['total_minor'] > 0 ? EstadoCargoRenta::Pendiente->value : EstadoCargoRenta::SinCargo->value,
                'vence_en' => CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01')?->endOfMonth()->addDays(10)->toDateString(),
            ],
        );
    }

    /**
     * Cotiza un periodo a partir de un uso (medido o estimado): desglose del cargo y
     * versión de la tarifa aplicada (null con cuota fija).
     *
     * @param  array<string, mixed>  $detalle
     * @return array{0: Desglose, 1: int|null}
     */
    public function cotizar(Estudio $estudio, string $metrica, int $cantidad, array $detalle, string $periodo): array
    {
        if ($estudio->modo_cobro === ModoCobroSaas::Fijo) {
            $desglose = $this->calcular->fija((int) $estudio->cuota_fija_minor);
            $version = null;
        } else {
            $modalidad = $metrica === ModalidadServicio::Citas->metrica() ? ModalidadServicio::Citas : ModalidadServicio::Clases;
            $tarifa = TarifaSaas::vigente($modalidad);
            $definicion = $tarifa->definicion ?? [];
            $desglose = $modalidad === ModalidadServicio::Citas
                ? $this->calcular->citas($definicion, (int) ($detalle['fte_milesimas'] ?? $cantidad * 1000), (int) ($detalle['personas_fuera_de_cita'] ?? 0))
                : $this->calcular->clases($definicion, $cantidad);
            $version = $tarifa?->version;
        }

        [$cobrables, $dias] = $this->diasCobrables($estudio, $periodo);

        return [$this->calcular->prorratear($desglose, $cobrables, $dias), $version];
    }

    /**
     * Días del periodo que ya no cubre la prueba gratis, y días totales del periodo.
     *
     * @return array{0: int, 1: int}
     */
    private function diasCobrables(Estudio $estudio, string $periodo): array
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01')?->startOfDay() ?? CarbonImmutable::now()->startOfMonth();
        $fin = $inicio->endOfMonth()->startOfDay();
        $dias = $inicio->daysInMonth;

        if ($estudio->trial_termina_en === null) {
            return [$dias, $dias];
        }

        // Se cobra desde el día siguiente al fin de la prueba.
        $desde = CarbonImmutable::parse($estudio->trial_termina_en->toDateString())->addDay()->startOfDay();
        if ($desde->greaterThan($fin)) {
            return [0, $dias];
        }
        if ($desde->lessThanOrEqualTo($inicio)) {
            return [$dias, $dias];
        }

        return [(int) $desde->diffInDays($fin) + 1, $dias];
    }
}
