<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Cuotas\ConceptoCuota;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ConfiguracionCuotasTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * La anualidad (plan de mejoras B04, decisión del dueño del 2026-10-10): la cobra quien
 * tiene un plan que la cobra, una vez al año, en el aniversario de su alta o en la
 * fecha fija que eligió el negocio. Se genera como cuota por cobrar (no da clases y no
 * se carga sola a la tarjeta). La `referencia` (persona y año) impide cobrarla dos veces
 * aunque el proceso diario corra de nuevo.
 *
 * - **Aniversario:** la fecha de su alta (el inicio de su plan más antiguo en vigor de
 *   los que la cobran); la primera al cumplir un año. Quien entró un 29 de febrero
 *   paga el 28 en los años que no son bisiestos.
 * - **Fecha fija:** la misma para todos los que ya estaban de alta ese día.
 *
 * Solo se genera dentro de los {@see self::VENTANA_DIAS} días posteriores a la fecha: si
 * el proceso no corrió unos días se pone al corriente, pero activar la anualidad a medio
 * año no cobra de golpe las que ya pasaron.
 */
class AnualidadesTenant
{
    public const VENTANA_DIAS = 7;

    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
    ) {}

    /** Genera las anualidades que tocan hoy; devuelve cuántas. */
    public function generar(): int
    {
        $config = ConfiguracionCuotasTenant::actual();
        // Las cuotas son de las membresías: un negocio de citas no las cobra (ADR 0130).
        if ($config->anualidad_minor <= 0 || app(MembresiasPorModalidadTenant::class)->enCitas()) {
            return 0;
        }
        $hoy = CarbonImmutable::parse($this->fechas->hoy());

        // La fecha de alta de cada quien tiene un plan en vigor que la cobra.
        $altas = AcuerdoTenant::query()
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            // Lo que se pactó al contratar; los contratados antes, según su plan.
            ->where(fn (Builder $q) => $q->where('cobra_anualidad', true)
                ->orWhere(fn (Builder $antes) => $antes->whereNull('cobra_anualidad')
                    ->whereHas('producto', fn (Builder $p) => $p->where('cobra_anualidad', true))))
            ->selectRaw('persona_id, MIN(fecha_inicio) as alta')
            ->groupBy('persona_id')
            ->get();

        $generadas = 0;
        foreach ($altas as $fila) {
            $alta = CarbonImmutable::parse((string) $fila->getAttribute('alta'))->startOfDay();
            $vence = $this->vence($config, $alta, $hoy->year);
            if ($vence === null || $vence->greaterThan($hoy) || $vence->lessThan($hoy->subDays(self::VENTANA_DIAS))) {
                continue;
            }
            $persona = PersonaTenant::query()->find((int) $fila->getAttribute('persona_id'));
            if ($persona instanceof PersonaTenant && $this->crear($persona, $config->anualidad_minor, $hoy->year)) {
                $generadas++;
            }
        }

        return $generadas;
    }

    /**
     * Cuándo le toca este año (null si aún no: el aniversario empieza al año de su alta;
     * la fecha fija, si ya estaba de alta ese día).
     */
    public function vence(ConfiguracionCuotasTenant $config, CarbonImmutable $alta, int $anio): ?CarbonImmutable
    {
        if ($config->anualidad_modo === ConfiguracionCuotasTenant::FECHA_FIJA) {
            $fecha = self::fecha($anio, (int) $config->anualidad_mes, (int) $config->anualidad_dia);

            return $alta->lessThan($fecha) ? $fecha : null;
        }

        return $anio > $alta->year ? self::fecha($anio, $alta->month, $alta->day) : null;
    }

    /** El día de ese año; el 29 de febrero, el 28 si el año no es bisiesto. */
    private static function fecha(int $anio, int $mes, int $dia): CarbonImmutable
    {
        $primero = CarbonImmutable::create($anio, max(1, $mes), 1)?->startOfDay() ?? CarbonImmutable::now()->startOfDay();

        return $primero->setDay(min(max(1, $dia), $primero->daysInMonth));
    }

    /**
     * La anualidad de ese año como cuota por cobrar; false si ya existía.
     */
    private function crear(PersonaTenant $persona, int $monto, int $anio): bool
    {
        $referencia = ConceptoCuota::Anualidad->value.':'.$persona->ulid.':'.$anio;
        if (LineaOrdenTenant::query()->where('referencia', $referencia)->exists()) {
            return false;
        }

        try {
            DB::connection('tenant')->transaction(function () use ($persona, $monto, $referencia): void {
                $orden = OrdenTenant::query()->create([
                    'persona_id' => $persona->getKey(),
                    'sucursal_id' => $persona->sucursal_id,
                    'estado' => EstadoOrden::Pendiente->value,
                    'total_minor' => $monto,
                    'moneda' => $this->parametros->monedaDe($persona->sucursal),
                ]);
                $orden->lineas()->create([
                    'concepto' => ConceptoCuota::Anualidad->value,
                    'referencia' => $referencia,
                    'beneficiario_id' => $persona->getKey(),
                    'cantidad' => 1,
                    'precio_unitario_minor' => $monto,
                    'subtotal_minor' => $monto,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Otro proceso la generó al mismo tiempo: ya existe.
            return false;
        }

        return true;
    }
}
