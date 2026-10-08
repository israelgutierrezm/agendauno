<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CupoProfesionalesExcedido;
use App\Modules\Tenancy\Exceptions\PlanNoPermitido;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ModoCobroSaas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El plan de un negocio de citas (ADR 0107): nivel (Individual, Premium o Pro), los
 * profesionales contratados y si paga mensual o anual (10 meses). Se cobra POR
 * ADELANTADO, por periodos de calendario: el primero empieza al terminar la prueba
 * (el mes se prorratea) y cada uno al día siguiente del anterior.
 *
 * - Subir (cuesta más) aplica al momento y se cobra la diferencia de los días que
 *   faltan del periodo pagado; bajar, o pasar de mensual a anual, desde el siguiente.
 * - Nunca se cobran menos profesionales de los que tiene el negocio, y después de la
 *   prueba no puede sumar más de los contratados (en la prueba, hasta el máximo).
 * - Si no eligió plan, al terminar la prueba queda en Individual (un profesional) o en
 *   Premium con los que tiene.
 *
 * Lo cobrado se calcula en dólares (la tarifa) y se cobra en la moneda del negocio
 * ({@see MonedaDeCobroSaas}).
 *
 * @phpstan-import-type Desglose from CalcularRentaSaas
 *
 * @phpstan-type Plan array{nivel: string, profesionales: int, periodicidad: string}
 * @phpstan-type PlanElegido array{nivel: string, profesionales: int, periodicidad: string, elegido: bool}
 */
class PlanCitasSaas
{
    public const NIVELES = ['individual', 'premium', 'pro'];

    public const PERIODICIDADES = ['mensual', 'anual'];

    public function __construct(
        private readonly CalcularRentaSaas $calcular,
        private readonly MonedaDeCobroSaas $moneda,
        private readonly TiposDeCambio $tipos,
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
    ) {}

    /** Días para pagar un cargo del plan (parámetro de la plataforma). */
    private function diasParaPagar(): int
    {
        return max(1, $this->parametros->entero('renta.dias_para_pagar'));
    }

    /**
     * La tarifa de citas por niveles vigente en un momento; null si la vigente es de
     * las anteriores (por profesionales activos, mes vencido).
     */
    public function tarifa(?CarbonInterface $al = null): ?TarifaSaas
    {
        $tarifa = TarifaSaas::vigenteEn(ModalidadServicio::Citas, $al ?? CarbonImmutable::now());

        return $tarifa !== null && is_array($tarifa->definicion['niveles'] ?? null) ? $tarifa : null;
    }

    /** ¿El negocio paga por plan? Citas, sin cuota fija pactada, con la tarifa por niveles. */
    public function aplica(Estudio $estudio, ?CarbonInterface $al = null): bool
    {
        return $estudio->modalidad() === ModalidadServicio::Citas
            && $estudio->modo_cobro !== ModoCobroSaas::Fijo
            && $this->tarifa($al) !== null;
    }

    /**
     * Cuántos profesionales se pueden contratar como máximo (más: cotización).
     *
     * @param  array<string, mixed>  $definicion
     */
    public static function maxProfesionales(array $definicion): int
    {
        $max = 1;
        foreach (['premium', 'pro'] as $nivel) {
            $precios = $definicion['niveles'][$nivel] ?? [];
            foreach (is_array($precios) ? array_keys($precios) : [] as $profesionales) {
                $max = max($max, (int) $profesionales);
            }
        }

        return $max;
    }

    public static function minProfesionales(string $nivel): int
    {
        return $nivel === 'individual' ? 1 : 2;
    }

    public function enPrueba(Estudio $estudio): bool
    {
        return $estudio->trial_termina_en !== null
            && $estudio->trial_termina_en->toDateString() >= $this->hoy($estudio)->toDateString();
    }

    /** Los profesionales que tiene hoy el negocio (los que se pueden agendar). */
    public function profesionalesActuales(Estudio $estudio): int
    {
        $contar = static fn (): int => Usuario::query()->profesionales()->count();

        return $this->gestor->actual()?->is($estudio) === true
            ? $contar()
            : (int) $this->gestor->ejecutarEn($estudio, $contar);
    }

    /**
     * Cuántos profesionales puede tener hoy; null si su cobro no es por plan.
     */
    public function limiteProfesionales(Estudio $estudio): ?int
    {
        $tarifa = $this->aplica($estudio) ? $this->tarifa() : null;
        if ($tarifa === null) {
            return null;
        }
        $max = self::maxProfesionales($tarifa->definicion);
        if ($this->enPrueba($estudio) || $estudio->plan_profesionales === null) {
            return $max;
        }

        return min($max, max(1, (int) $estudio->plan_profesionales));
    }

    /**
     * Antes de sumar profesionales: que quepan en lo contratado.
     *
     * @throws CupoProfesionalesExcedido
     */
    public function exigirCupo(Estudio $estudio, int $nuevos = 1): void
    {
        $limite = $this->limiteProfesionales($estudio);
        if ($limite === null || $nuevos <= 0) {
            return;
        }
        $actuales = $this->profesionalesActuales($estudio);
        if ($actuales + $nuevos > $limite) {
            throw new CupoProfesionalesExcedido($limite, $actuales);
        }
    }

    /**
     * El plan con que se cobra el siguiente periodo: el contratado (con el cambio que
     * espera al siguiente periodo, si lo hay) o el que le toca si no eligió; nunca con
     * menos profesionales de los que tiene.
     *
     * @return PlanElegido
     */
    public function planSiguiente(Estudio $estudio, ?int $actuales = null): array
    {
        $siguiente = is_array($estudio->plan_siguiente) ? $estudio->plan_siguiente : [];

        return $this->normalizar(
            $estudio,
            $siguiente['nivel'] ?? $estudio->plan_nivel,
            $siguiente['profesionales'] ?? $estudio->plan_profesionales,
            $siguiente['periodicidad'] ?? $estudio->plan_periodicidad,
            $actuales,
        );
    }

    /**
     * El plan que rige hoy (lo ya cobrado o, sin cobrar aún, lo elegido o lo que le toca).
     *
     * @return PlanElegido
     */
    public function planActual(Estudio $estudio, ?int $actuales = null): array
    {
        return $this->normalizar($estudio, $estudio->plan_nivel, $estudio->plan_profesionales, $estudio->plan_periodicidad, $actuales);
    }

    /**
     * El siguiente periodo por cobrar: desde el día siguiente a lo ya cubierto (o al
     * fin de la prueba) hasta el fin de ese mes (mensual) o un año después (anual).
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}
     */
    public function siguientePeriodo(Estudio $estudio, string $periodicidad): array
    {
        if ($estudio->plan_cubierto_hasta !== null) {
            $desde = CarbonImmutable::parse($estudio->plan_cubierto_hasta->toDateString())->addDay();
            // Un periodo que pasó completo sin cobrarse (el negocio estaba suspendido) no
            // se cobra: se sigue desde el mes en curso.
            $hoy = $this->hoy($estudio);
            $fin = $periodicidad === 'anual' ? $desde->addYearNoOverflow()->subDay() : $desde->endOfMonth()->startOfDay();
            if ($fin->lessThan($hoy)) {
                $desde = $hoy->startOfMonth();
            }
        } else {
            $desde = $this->hoy($estudio)->startOfMonth();
            if ($estudio->trial_termina_en !== null) {
                $finPrueba = CarbonImmutable::parse($estudio->trial_termina_en->toDateString())->addDay();
                $desde = $finPrueba->greaterThan($desde) ? $finPrueba : $desde;
            }
        }
        $hasta = $periodicidad === 'anual'
            ? $desde->addYearNoOverflow()->subDay()
            : $desde->endOfMonth()->startOfDay();

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /**
     * Emite los cargos de los periodos que ya empezaron (por adelantado). Idempotente:
     * lo ya cubierto no se vuelve a cobrar.
     *
     * @return list<CargoRenta>
     */
    public function emitirPendientes(Estudio $estudio): array
    {
        $emitidos = [];
        // Tope de vueltas: un negocio que se quedó atrás se pone al día, pero sin ciclos.
        for ($vuelta = 0; $vuelta < 24; $vuelta++) {
            $cargo = $this->emitirSiguiente($estudio->refresh());
            if ($cargo === null) {
                break;
            }
            $emitidos[] = $cargo;
        }

        return $emitidos;
    }

    /**
     * Lo que se cobrará en el siguiente periodo (estimado, al tipo de cambio de hoy).
     *
     * @return array{plan: PlanElegido, desde: string, hasta: string, desglose: Desglose}
     */
    public function estimarSiguiente(Estudio $estudio): array
    {
        $plan = $this->planSiguiente($estudio);
        $periodo = $this->siguientePeriodo($estudio, $plan['periodicidad']);
        $tarifa = $this->tarifa($periodo['desde']) ?? $this->tarifa();
        $definicion = $tarifa->definicion ?? [];
        $desglose = $this->desglosePeriodo($estudio, $definicion, $plan, $periodo['desde'], $periodo['hasta']);
        $final = $this->moneda->aplicar($estudio, $desglose, MonedaDeCobroSaas::deTarifa($definicion), CarbonImmutable::now(), estimacion: true);

        return [
            'plan' => $plan,
            'desde' => $periodo['desde']->toDateString(),
            'hasta' => $periodo['hasta']->toDateString(),
            'desglose' => $final['desglose'],
        ];
    }

    /**
     * Para «Mi suscripción»: el plan, lo cubierto, el cambio que espera al siguiente
     * periodo, el cupo de profesionales y los precios de la tarifa.
     *
     * @return array<string, mixed>
     */
    public function resumen(Estudio $estudio): array
    {
        $definicion = $this->tarifa()->definicion ?? [];
        $actuales = $this->profesionalesActuales($estudio);
        $actual = $this->planActual($estudio, $actuales);
        $monedaTarifa = MonedaDeCobroSaas::deTarifa($definicion);
        $tipo = $estudio->enMexico() && $monedaTarifa === 'USD' ? $this->tipos->ultimo() : null;

        return [
            'nivel' => $actual['nivel'],
            'profesionales' => $actual['profesionales'],
            'periodicidad' => $actual['periodicidad'],
            'elegido' => $actual['elegido'],
            'en_prueba' => $this->enPrueba($estudio),
            'cubierto_hasta' => $estudio->plan_cubierto_hasta?->toDateString(),
            'siguiente' => $estudio->plan_siguiente,
            'profesionales_actuales' => $actuales,
            'limite_profesionales' => $this->limiteProfesionales($estudio),
            'max_profesionales' => self::maxProfesionales($definicion),
            'meses_anual' => CalcularRentaSaas::mesesAnual($definicion),
            'moneda_tarifa' => $monedaTarifa,
            'moneda_cobro' => MonedaDeCobroSaas::deCobro($estudio, $monedaTarifa),
            'iva_porcentaje' => (int) (MonedaDeCobroSaas::conIvaDelPais($definicion, $estudio)['iva_porcentaje'] ?? 16),
            'tipo_cambio' => $tipo === null ? null : [
                'valor' => TiposDeCambio::formatear($tipo['diezmilesimas']), 'fecha' => $tipo['fecha'], 'fuente' => $tipo['fuente'],
            ],
            'precios' => $definicion['niveles'] ?? [],
            // Qué nivel abre cada función (lo fija el superadmin en la tarifa).
            'funciones' => FuncionesPlan::mapa($definicion),
        ];
    }

    /**
     * Cambia el plan. En la prueba, o sin un periodo cobrado que cubra hoy, solo se
     * guarda. Con un periodo cobrado: si el plan nuevo cuesta más, aplica hoy y se
     * cobra la diferencia de los días que faltan; si cuesta igual o menos, o cambia
     * entre mensual y anual, aplica desde el siguiente periodo.
     *
     * `$cobrarDiferencia` en falso (solo el superadmin, como cortesía): sube hoy sin
     * cobrar la diferencia; lo siguiente se cobra con el plan nuevo.
     *
     * @return array{aplica: 'ahora'|'siguiente', ajuste: CargoRenta|null}
     *
     * @throws PlanNoPermitido
     */
    public function cambiar(Estudio $estudio, string $nivel, int $profesionales, string $periodicidad, bool $cobrarDiferencia = true): array
    {
        $tarifa = $this->aplica($estudio) ? $this->tarifa() : null;
        if ($tarifa === null) {
            throw new PlanNoPermitido('Este negocio no se cobra por plan (es de clases o tiene una cuota pactada).');
        }
        $definicion = $tarifa->definicion;
        $this->validar($definicion, $nivel, $profesionales, $periodicidad, $this->profesionalesActuales($estudio));

        // Con un periodo pagado puede cobrarse una diferencia: el tipo de cambio se
        // consulta antes de bloquear. En la prueba no se cobra nada.
        $cubierto = $estudio->plan_cubierto_hasta !== null
            && $estudio->plan_cubierto_hasta->toDateString() >= $this->hoy($estudio)->toDateString();
        if ($cobrarDiferencia && $cubierto && $estudio->enMexico() && MonedaDeCobroSaas::deTarifa($definicion) === 'USD') {
            $this->tipos->usdMxn(CarbonImmutable::now());
        }

        return DB::transaction(function () use ($estudio, $definicion, $nivel, $profesionales, $periodicidad, $cobrarDiferencia): array {
            /** @var Estudio $e */
            $e = Estudio::query()->whereKey($estudio->getKey())->lockForUpdate()->firstOrFail();
            $hoy = $this->hoy($e);
            $nuevo = ['nivel' => $nivel, 'profesionales' => $profesionales, 'periodicidad' => $periodicidad];

            $cubierto = $e->plan_cubierto_hasta !== null && $e->plan_cubierto_hasta->toDateString() >= $hoy->toDateString();
            if (! $cubierto) {
                $e->update(['plan_nivel' => $nivel, 'plan_profesionales' => $profesionales, 'plan_periodicidad' => $periodicidad, 'plan_siguiente' => null]);

                return ['aplica' => 'ahora', 'ajuste' => null];
            }

            $actual = $this->planActual($e);
            $precioActual = CalcularRentaSaas::precioPlan($definicion, $actual['nivel'], $actual['profesionales']) ?? 0;
            $precioNuevo = CalcularRentaSaas::precioPlan($definicion, $nivel, $profesionales) ?? 0;

            if ($precioNuevo <= $precioActual) {
                $igual = $nuevo === ['nivel' => $actual['nivel'], 'profesionales' => $actual['profesionales'], 'periodicidad' => $actual['periodicidad']];
                $e->update(['plan_siguiente' => $igual ? null : $nuevo]);

                return ['aplica' => 'siguiente', 'ajuste' => null];
            }

            // Sube: aplica hoy; mensual/anual cambia hasta el siguiente periodo.
            $ajuste = $cobrarDiferencia
                ? $this->cobrarDiferencia($e, $definicion, $actual, $nuevo, $precioNuevo - $precioActual, $hoy)
                : null;
            $e->update([
                'plan_nivel' => $nivel,
                'plan_profesionales' => $profesionales,
                'plan_siguiente' => $periodicidad !== $actual['periodicidad'] ? $nuevo : null,
            ]);

            return ['aplica' => 'ahora', 'ajuste' => $ajuste];
        });
    }

    /**
     * @param  array<string, mixed>  $definicion
     *
     * @throws PlanNoPermitido
     */
    private function validar(array $definicion, string $nivel, int $profesionales, string $periodicidad, int $actuales): void
    {
        if (! in_array($nivel, self::NIVELES, true) || ! in_array($periodicidad, self::PERIODICIDADES, true)) {
            throw new PlanNoPermitido('Ese plan no existe.');
        }
        $max = self::maxProfesionales($definicion);
        if ($nivel === 'individual' && $profesionales !== 1) {
            throw new PlanNoPermitido('El plan Individual es para un profesional.');
        }
        if ($nivel !== 'individual' && ($profesionales < self::minProfesionales($nivel) || $profesionales > $max)) {
            throw new PlanNoPermitido("Los planes Premium y Pro son de 2 a {$max} profesionales; para más, pide una cotización.");
        }
        if (CalcularRentaSaas::precioPlan($definicion, $nivel, $profesionales) === null) {
            throw new PlanNoPermitido('Ese plan no está en la tarifa vigente.');
        }
        if ($profesionales < $actuales) {
            throw new PlanNoPermitido("Tienes {$actuales} profesionales: da de baja a alguno antes de contratar menos.");
        }
    }

    /**
     * El cargo con la diferencia de los días que faltan del periodo pagado.
     *
     * @param  array<string, mixed>  $definicion
     * @param  Plan  $actual
     * @param  Plan  $nuevo
     */
    private function cobrarDiferencia(Estudio $e, array $definicion, array $actual, array $nuevo, int $diferenciaMensual, CarbonImmutable $hoy): ?CargoRenta
    {
        $hasta = CarbonImmutable::parse((string) $e->plan_cubierto_hasta?->toDateString());
        $anual = $actual['periodicidad'] === 'anual';
        $periodo = CargoRenta::query()->where('estudio_id', $e->getKey())->where('clave', 'periodo')
            ->whereDate('cubre_hasta', $hasta->toDateString())->first();
        $desde = $periodo?->cubre_desde !== null ? CarbonImmutable::parse($periodo->cubre_desde->toDateString()) : null;
        // Base del prorrateo: los días del mes (mensual) o los del año pagado (anual).
        $base = $anual && $desde !== null ? (int) $desde->diffInDays($hasta) + 1 : $hasta->daysInMonth;
        $dias = (int) $hoy->diffInDays($hasta) + 1;
        $importe = intdiv($diferenciaMensual * ($anual ? CalcularRentaSaas::mesesAnual($definicion) : 1) * $dias, max(1, $base));
        if ($importe <= 0) {
            return null;
        }

        $desglose = $this->calcular->totalizar([[
            'concepto' => 'Cambio a Plan '.CalcularRentaSaas::nombreNivel($nuevo['nivel']).' · '.$nuevo['profesionales'].' '.($nuevo['profesionales'] === 1 ? 'profesional' : 'profesionales'),
            'detalle' => 'Diferencia del '.$this->fecha($hoy).' al '.$this->fecha($hasta).' ('.$dias.' '.($dias === 1 ? 'día' : 'días').')',
            'importe_minor' => $importe,
        ]], MonedaDeCobroSaas::conIvaDelPais($definicion, $e));
        $desglose['cubre'] = ['desde' => $hoy->toDateString(), 'hasta' => $hasta->toDateString()];
        $final = $this->moneda->aplicar($e, $desglose, MonedaDeCobroSaas::deTarifa($definicion), CarbonImmutable::now());

        return CargoRenta::query()->create([
            'estudio_id' => $e->getKey(),
            'periodo' => $hoy->format('Y-m'),
            'clave' => 'ajuste:'.Str::lower((string) Str::ulid()),
            'concepto' => 'ajuste',
            'cubre_desde' => $hoy->toDateString(),
            'cubre_hasta' => $hasta->toDateString(),
            'modo_cobro' => ModoCobroSaas::Activos->value,
            'metrica' => 'profesionales_contratados',
            'alumnos_activos' => $nuevo['profesionales'],
            'tarifa_version' => $this->tarifa()?->version,
            'desglose' => $final['desglose'],
            'monto_minor' => $final['desglose']['total_minor'],
            ...$final['columnas'],
            'estado' => EstadoCargoRenta::Pendiente->value,
            'vence_en' => $hoy->addDays($this->diasParaPagar())->toDateString(),
            'emitido_en' => now(),
        ]);
    }

    /**
     * Emite el cargo del siguiente periodo si ya empezó.
     */
    private function emitirSiguiente(Estudio $estudio): ?CargoRenta
    {
        if (! $this->aplica($estudio)) {
            return null;
        }
        $plan = $this->planSiguiente($estudio);
        if ($this->siguientePeriodo($estudio, $plan['periodicidad'])['desde']->greaterThan($this->hoy($estudio))) {
            return null;
        }
        $actuales = $this->profesionalesActuales($estudio);
        // El tipo de cambio se consulta antes de bloquear el negocio.
        if ($estudio->enMexico() && MonedaDeCobroSaas::deTarifa($this->tarifa()->definicion ?? []) === 'USD') {
            $this->tipos->usdMxn(CarbonImmutable::now());
        }

        return DB::transaction(function () use ($estudio, $actuales): ?CargoRenta {
            /** @var Estudio $e */
            $e = Estudio::query()->whereKey($estudio->getKey())->lockForUpdate()->firstOrFail();
            $plan = $this->planSiguiente($e, $actuales);
            $periodo = $this->siguientePeriodo($e, $plan['periodicidad']);
            if ($periodo['desde']->greaterThan($this->hoy($e))) {
                return null;
            }
            $tarifa = $this->tarifa($periodo['desde']) ?? $this->tarifa();
            $definicion = $tarifa->definicion ?? [];
            $desglose = $this->desglosePeriodo($e, $definicion, $plan, $periodo['desde'], $periodo['hasta']);
            $final = $this->moneda->aplicar($e, $desglose, MonedaDeCobroSaas::deTarifa($definicion), CarbonImmutable::now());
            $total = $final['desglose']['total_minor'];

            $cargo = CargoRenta::query()->firstOrCreate(
                ['estudio_id' => $e->getKey(), 'periodo' => $periodo['desde']->format('Y-m'), 'clave' => 'periodo'],
                [
                    'concepto' => 'plan',
                    'cubre_desde' => $periodo['desde']->toDateString(),
                    'cubre_hasta' => $periodo['hasta']->toDateString(),
                    'modo_cobro' => ModoCobroSaas::Activos->value,
                    'metrica' => 'profesionales_contratados',
                    'alumnos_activos' => $plan['profesionales'],
                    'tarifa_version' => $tarifa?->version,
                    'desglose' => $final['desglose'],
                    'monto_minor' => $total,
                    ...$final['columnas'],
                    'estado' => $total > 0 ? EstadoCargoRenta::Pendiente->value : EstadoCargoRenta::SinCargo->value,
                    'vence_en' => $periodo['desde']->addDays($this->diasParaPagar())->toDateString(),
                    'emitido_en' => now(),
                ],
            );

            $e->update([
                'plan_nivel' => $plan['nivel'],
                'plan_profesionales' => $plan['profesionales'],
                'plan_periodicidad' => $plan['periodicidad'],
                'plan_cubierto_hasta' => $cargo->cubre_hasta?->toDateString() ?? $periodo['hasta']->toDateString(),
                'plan_siguiente' => null,
            ]);

            return $cargo;
        });
    }

    /**
     * El desglose de un periodo del plan, en la moneda de la tarifa; el primer mes,
     * si no empieza el día 1, se prorratea.
     *
     * @param  array<string, mixed>  $definicion
     * @param  Plan  $plan
     * @return Desglose
     */
    private function desglosePeriodo(Estudio $estudio, array $definicion, array $plan, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $anual = $plan['periodicidad'] === 'anual';
        $detalle = ($anual ? 'Anual ('.CalcularRentaSaas::mesesAnual($definicion).' meses), ' : 'Mensual, ')
            .'del '.$this->fecha($desde).' al '.$this->fecha($hasta);
        $desglose = $this->calcular->plan(MonedaDeCobroSaas::conIvaDelPais($definicion, $estudio), $plan['nivel'], $plan['profesionales'], $anual, $detalle);
        if (! $anual && $desde->day !== 1) {
            $desglose = $this->calcular->prorratear($desglose, (int) $desde->diffInDays($hasta) + 1, $desde->daysInMonth);
        }

        return [...$desglose, 'cubre' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]];
    }

    /**
     * @return PlanElegido
     */
    private function normalizar(Estudio $estudio, mixed $nivel, mixed $profesionales, mixed $periodicidad, ?int $actuales): array
    {
        $definicion = $this->tarifa()->definicion ?? [];
        $max = self::maxProfesionales($definicion);
        $actuales ??= $this->profesionalesActuales($estudio);
        $elegido = is_string($nivel) && in_array($nivel, self::NIVELES, true);
        $nivel = $elegido ? $nivel : ($actuales > 1 ? 'premium' : 'individual');
        $cantidad = max(is_numeric($profesionales) ? (int) $profesionales : 0, min($actuales, $max), self::minProfesionales($nivel));
        // Con más de un profesional ya no es Individual.
        if ($nivel === 'individual' && $cantidad > 1) {
            $nivel = 'premium';
        }

        return [
            'nivel' => $nivel,
            'profesionales' => $nivel === 'individual' ? 1 : min($cantidad, $max),
            'periodicidad' => $periodicidad === 'anual' ? 'anual' : 'mensual',
            'elegido' => $elegido,
        ];
    }

    /** Hoy en la zona del negocio (solo la fecha). */
    private function hoy(Estudio $estudio): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'))->toDateString());
    }

    private function fecha(CarbonImmutable $fecha): string
    {
        return $fecha->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
