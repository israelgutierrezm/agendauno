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
 *   periodo). Un periodo sin nada que cobrar (o con menos del cargo mínimo que se
 *   cobra, `renta.cargo_minimo_*`) queda `sin_cargo`.
 * - en la moneda de cobro del negocio: la tarifa en dólares se cobra en pesos en
 *   México, al tipo de cambio del día en que se emite (ADR 0107).
 *
 * Los negocios de citas con la tarifa por niveles no se cobran aquí: pagan su plan
 * por adelantado ({@see PlanCitasSaas}).
 *
 * @phpstan-import-type Desglose from CalcularRentaSaas
 */
class GenerarCargoRenta
{
    public function __construct(
        private readonly MedirUsoSaas $medir,
        private readonly CalcularRentaSaas $calcular,
        private readonly MonedaDeCobroSaas $moneda,
        private readonly PlanCitasSaas $planes,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * ¿Ese periodo se cobra por adelantado con el plan de citas (y no mes vencido)?
     * Sí, si al cierre del periodo ya regía la tarifa de citas por niveles.
     */
    public function porAdelantado(Estudio $estudio, string $periodo): bool
    {
        return $this->planes->aplica($estudio, $this->finDelPeriodo($estudio, $periodo));
    }

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
            ->where('clave', 'periodo')
            ->first();
        // Un cargo emitido no se recalcula.
        if ($existente instanceof CargoRenta) {
            return $existente;
        }
        if (! $this->cerrado($estudio, $periodo)) {
            throw new DomainException("El periodo {$periodo} aún no cierra para este negocio: por ahora solo hay una estimación.");
        }
        if ($this->porAdelantado($estudio, $periodo)) {
            throw new DomainException("El periodo {$periodo} se cobra por adelantado con el plan del negocio.");
        }

        $medicion = $this->medir->congelar($estudio, $periodo);
        [$desglose, $version, $monedaTarifa] = $this->cotizar($estudio, $medicion->metrica, $medicion->cantidad, $medicion->detalle ?? [], $periodo, $this->finDelPeriodo($estudio, $periodo));
        $final = $this->moneda->aplicar($estudio, $desglose, $monedaTarifa, CarbonImmutable::now());
        $desglose = $final['desglose'];
        // Vence a los días para pagar desde el fin del mes o, si se emite después (p. ej.
        // esperó el tipo de cambio), desde que se emite: no nace vencido.
        $hoy = CarbonImmutable::parse(CarbonImmutable::now(self::zona($estudio))->toDateString());
        $finDelMes = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01')?->endOfMonth()->startOfDay() ?? $hoy;
        $vence = ($finDelMes->greaterThan($hoy) ? $finDelMes : $hoy)->addDays(max(1, $this->parametros->entero('renta.dias_para_pagar')));

        return CargoRenta::query()->firstOrCreate(
            ['estudio_id' => $estudio->getKey(), 'periodo' => $periodo, 'clave' => 'periodo'],
            [
                'concepto' => 'renta',
                ...$final['columnas'],
                'modo_cobro' => $estudio->modo_cobro->value,
                'metrica' => $medicion->metrica,
                'alumnos_activos' => $medicion->cantidad,
                'medicion_id' => $medicion->getKey(),
                'regla_version' => $medicion->regla_version,
                'tarifa_version' => $version,
                'desglose' => $desglose,
                'monto_minor' => $desglose['total_minor'],
                // Menos del cargo mínimo (Stripe no lo cobra): queda sin cargo.
                'estado' => $this->moneda->cobrable($desglose['total_minor'], $final['columnas']['moneda'])
                    ? EstadoCargoRenta::Pendiente->value
                    : EstadoCargoRenta::SinCargo->value,
                'vence_en' => $vence->toDateString(),
                'emitido_en' => now(),
            ],
        );
    }

    /**
     * Cotiza un periodo a partir de un uso (medido o estimado): desglose del cargo (en
     * la moneda de la tarifa, con el IVA del país del negocio), versión de la tarifa
     * aplicada (null con cuota fija) y su moneda. La tarifa es la vigente en
     * `$tarifaAl` (el cierre del periodo al emitir el cargo; ahora, al estimar).
     *
     * @param  array<string, mixed>  $detalle
     * @return array{0: Desglose, 1: int|null, 2: string}
     */
    public function cotizar(Estudio $estudio, string $metrica, int $cantidad, array $detalle, string $periodo, ?CarbonImmutable $tarifaAl = null): array
    {
        if ($estudio->modo_cobro === ModoCobroSaas::Fijo) {
            $desglose = $this->calcular->fija((int) $estudio->cuota_fija_minor);
            $version = null;
            $monedaTarifa = (string) ($estudio->cuota_fija_moneda ?: 'MXN');
        } else {
            $modalidad = $metrica === ModalidadServicio::Citas->metrica() ? ModalidadServicio::Citas : ModalidadServicio::Clases;
            $tarifa = TarifaSaas::vigenteEn($modalidad, $tarifaAl ?? CarbonImmutable::now());
            $definicion = MonedaDeCobroSaas::conIvaDelPais($tarifa->definicion ?? [], $estudio);
            $desglose = $modalidad === ModalidadServicio::Citas
                ? $this->calcular->citas($definicion, $cantidad, (int) ($detalle['personas_fuera_de_cita'] ?? 0))
                : $this->calcular->clases($definicion, $cantidad);
            $version = $tarifa?->version;
            $monedaTarifa = MonedaDeCobroSaas::deTarifa($definicion);
        }

        [$cobrables, $dias] = $this->diasCobrables($estudio, $periodo);

        return [$this->calcular->prorratear($desglose, $cobrables, $dias), $version, $monedaTarifa];
    }

    /**
     * La estimación de un periodo en la moneda de cobro (al tipo de cambio de hoy; sin
     * tipo de cambio, en la moneda de la tarifa).
     *
     * @param  array<string, mixed>  $detalle
     * @return array{0: Desglose, 1: int|null}
     */
    public function estimar(Estudio $estudio, string $metrica, int $cantidad, array $detalle, string $periodo): array
    {
        [$desglose, $version, $monedaTarifa] = $this->cotizar($estudio, $metrica, $cantidad, $detalle, $periodo);

        return [$this->moneda->aplicar($estudio, $desglose, $monedaTarifa, CarbonImmutable::now(), estimacion: true)['desglose'], $version];
    }

    private function finDelPeriodo(Estudio $estudio, string $periodo): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01', self::zona($estudio))?->endOfMonth()
            ?? CarbonImmutable::now()->endOfMonth();
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
