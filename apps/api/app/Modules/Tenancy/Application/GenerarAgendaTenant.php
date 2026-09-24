<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materializa las sesiones de una plantilla de horario tenant-local dentro de un rango
 * de fechas (R5). Por cada dia del rango cuyo dia de semana este en la plantilla (y que no sea una
 * excepcion/feriado) crea una sesion con `inicia_en`/`termina_en` en UTC (desde la
 * hora local + zona de la sucursal). Es IDEMPOTENTE (firstOrCreate sobre
 * `(serie_id, inicia_en)` + el indice unico), asi que reejecutar el rango no duplica
 * ni resucita una instancia editada/cancelada (override por instancia).
 */
class GenerarAgendaTenant
{
    public function __construct(private readonly VerificarRecursoTenant $recursos) {}

    public function ejecutar(PlantillaHorarioTenant $plantilla, string $desde, string $hasta): int
    {
        if (! $plantilla->activo) {
            return 0;
        }

        $zona = is_string($plantilla->sucursal?->zona_horaria) ? $plantilla->sucursal->zona_horaria : 'UTC';
        $capacidad = $plantilla->capacidad ?? $plantilla->oferta?->capacidad;
        $dias = array_map('intval', $plantilla->dias_semana ?? []);
        $recurso = $plantilla->recurso_id !== null
            ? RecursoTenant::query()->find($plantilla->recurso_id)
            : null;

        // Ventana efectiva = interseccion del rango pedido con la vigencia.
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        if ($inicio->lt($plantilla->vigente_desde)) {
            $inicio = CarbonImmutable::parse($plantilla->vigente_desde->toDateString())->startOfDay();
        }

        $fin = CarbonImmutable::parse($hasta)->startOfDay();
        if ($plantilla->vigente_hasta !== null) {
            $limite = CarbonImmutable::parse($plantilla->vigente_hasta->toDateString())->startOfDay();
            if ($fin->gt($limite)) {
                $fin = $limite;
            }
        }

        $excepciones = ExcepcionHorarioTenant::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->pluck('fecha')
            ->map(fn ($fecha): string => $fecha->toDateString())
            ->flip();

        return DB::connection('tenant')->transaction(function () use ($plantilla, $inicio, $fin, $dias, $zona, $capacidad, $excepciones, $recurso): int {
            $creadas = 0;

            for ($dia = $inicio; $dia->lte($fin); $dia = $dia->addDay()) {
                if (! in_array($dia->dayOfWeekIso, $dias, true)) {
                    continue;
                }

                if ($excepciones->has($dia->toDateString())) {
                    continue;
                }

                $iniciaEn = CarbonImmutable::parse($dia->toDateString().' '.$plantilla->hora_local, $zona)->utc();
                $terminaEn = $iniciaEn->addMinutes($plantilla->duracion_minutos);

                // Recurso ocupado a su cupo por OTRA serie/sesion en ese horario: se
                // omite la instancia (se excluye la propia serie para no auto-chocar).
                if ($recurso instanceof RecursoTenant
                    && ! $this->recursos->disponible($recurso, $iniciaEn, $terminaEn, (int) $plantilla->getKey())) {
                    continue;
                }

                $sesion = SesionTenant::query()->firstOrCreate(
                    ['serie_id' => $plantilla->getKey(), 'inicia_en' => $iniciaEn],
                    [
                        'oferta_id' => $plantilla->oferta_id,
                        'sucursal_id' => $plantilla->sucursal_id,
                        'instructor_id' => $plantilla->instructor_id,
                        'recurso_id' => $plantilla->recurso_id,
                        'termina_en' => $terminaEn,
                        'zona_horaria' => $zona,
                        'capacidad' => $capacidad,
                        'estado' => EstadoSesionTenant::Programada->value,
                    ],
                );

                if ($sesion->wasRecentlyCreated) {
                    $creadas++;
                }
            }

            return $creadas;
        });
    }
}
