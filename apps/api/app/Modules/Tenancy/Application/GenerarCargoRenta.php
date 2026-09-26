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
use DomainException;

/**
 * Emite el cargo de renta del SaaS de un estudio para un periodo, MES VENCIDO (ADR
 * 0019 y 0032):
 *
 * - solo cuando el mes ya cerró en la zona horaria del negocio (antes, lo que hay es
 *   la estimación del apartado de renta); su medición se CONGELA;
 * - con la tarifa vigente al cierre de ESE mes (no la de hoy), o la cuota fija pactada,
 *   sin cobrar los días de prueba gratis;
 * - guarda con qué se calculó (medición, regla, tarifa) y cuándo se emitió;
 * - una vez emitido NO se recalcula: volver a correr el proceso no lo cambia aunque
 *   después cambien la tarifa, la prueba o la medición. Idempotente por (estudio,
 *   periodo). Un periodo sin nada que cobrar queda `sin_cargo`.
 *
 * @phpstan-import-type Desglose from CalcularRentaSaas
 */
class GenerarCargoRenta
{
    public function __construct(
        private readonly MedirUsoSaas $medir,
        private readonly CalcularRentaSaas $calcular,
    ) {}

    /**
     * ¿El periodo (YYYY-MM) ya terminó en la zona horaria del negocio?
     */
    public function cerrado(Estudio $estudio, string $periodo): bool
    {
        return $periodo < CarbonImmutable::now(self::zona($estudio))->format('Y-m');
    }

    public function paraEstudio(Estudio $estudio, string $periodo): CargoRenta
    {
        $existente = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('periodo', $periodo)
            ->first();
        // Un cargo emitido no se recalcula.
        if ($existente instanceof CargoRenta) {
            return $existente;
        }
        if (! $this->cerrado($estudio, $periodo)) {
            throw new DomainException("El periodo {$periodo} aún no cierra para este negocio: por ahora solo hay una estimación.");
        }

        $medicion = $this->medir->congelar($estudio, $periodo);
        $finDelPeriodo = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01', self::zona($estudio))?->endOfMonth();
        [$desglose, $version] = $this->cotizar($estudio, $medicion->metrica, $medicion->cantidad, $medicion->detalle ?? [], $periodo, $finDelPeriodo);

        return CargoRenta::query()->firstOrCreate(
            ['estudio_id' => $estudio->getKey(), 'periodo' => $periodo],
            [
                'modo_cobro' => $estudio->modo_cobro->value,
                'metrica' => $medicion->metrica,
                'alumnos_activos' => $medicion->cantidad,
                'medicion_id' => $medicion->getKey(),
                'regla_version' => $medicion->regla_version,
                'tarifa_version' => $version,
                'desglose' => $desglose,
                'monto_minor' => $desglose['total_minor'],
                'moneda' => $estudio->moneda,
                'estado' => $desglose['total_minor'] > 0 ? EstadoCargoRenta::Pendiente->value : EstadoCargoRenta::SinCargo->value,
                'vence_en' => CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01')?->endOfMonth()->addDays(10)->toDateString(),
                'emitido_en' => now(),
            ],
        );
    }

    /**
     * Cotiza un periodo a partir de un uso (medido o estimado): desglose del cargo y
     * versión de la tarifa aplicada (null con cuota fija). La tarifa es la vigente en
     * `$tarifaAl` (el cierre del periodo al emitir el cargo; ahora, al estimar).
     *
     * @param  array<string, mixed>  $detalle
     * @return array{0: Desglose, 1: int|null}
     */
    public function cotizar(Estudio $estudio, string $metrica, int $cantidad, array $detalle, string $periodo, ?CarbonImmutable $tarifaAl = null): array
    {
        if ($estudio->modo_cobro === ModoCobroSaas::Fijo) {
            $desglose = $this->calcular->fija((int) $estudio->cuota_fija_minor);
            $version = null;
        } else {
            $modalidad = $metrica === ModalidadServicio::Citas->metrica() ? ModalidadServicio::Citas : ModalidadServicio::Clases;
            $tarifa = TarifaSaas::vigenteEn($modalidad, $tarifaAl ?? CarbonImmutable::now());
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

    private static function zona(Estudio $estudio): string
    {
        return (string) ($estudio->zona_horaria ?: 'UTC');
    }
}
