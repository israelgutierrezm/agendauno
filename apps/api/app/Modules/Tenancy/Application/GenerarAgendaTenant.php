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
 * de fechas (R5). Por cada día del rango cuyo día de semana esté en la plantilla (y
 * que no sea un día cerrado) crea una sesión con `inicia_en`/`termina_en` en UTC
 * (desde la hora local + zona de la sucursal).
 *
 * - IDEMPOTENTE: cada sesión guarda su fecha de serie (`fecha_serie`, 2.5), así que
 *   regenerar no duplica ni resucita una instancia editada, movida (de hora o de día)
 *   o cancelada.
 * - Con las mismas reglas que al crear una sesión a mano ({@see VerificarAgendaTenant}):
 *   el instructor no puede tener otra cosa a esa hora y la sala no puede pasarse de su
 *   cupo, bajo el mismo candado por profesional y recurso.
 * - Lo que no se puede generar se omite y se REPORTA (fecha y motivo).
 */
class GenerarAgendaTenant
{
    public function __construct(private readonly VerificarAgendaTenant $agenda) {}

    public function ejecutar(PlantillaHorarioTenant $plantilla, string $desde, string $hasta): GeneracionDeAgenda
    {
        if (! $plantilla->activo) {
            return new GeneracionDeAgenda(0, []);
        }

        $zona = is_string($plantilla->sucursal?->zona_horaria) ? $plantilla->sucursal->zona_horaria : 'UTC';
        $capacidad = $plantilla->capacidad ?? $plantilla->oferta?->capacidad;
        $dias = array_map('intval', $plantilla->dias_semana ?? []);
        $recurso = $plantilla->recurso_id !== null
            ? RecursoTenant::query()->find($plantilla->recurso_id)
            : null;

        // Ventana efectiva = intersección del rango pedido con la vigencia.
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

        return DB::connection('tenant')->transaction(function () use ($plantilla, $inicio, $fin, $dias, $zona, $capacidad, $excepciones, $recurso): GeneracionDeAgenda {
            $this->agenda->bloquear($plantilla->instructor_id !== null ? (int) $plantilla->instructor_id : null, $recurso);

            $creadas = 0;
            $omitidas = [];

            for ($dia = $inicio; $dia->lte($fin); $dia = $dia->addDay()) {
                if (! in_array($dia->dayOfWeekIso, $dias, true) || $excepciones->has($dia->toDateString())) {
                    continue;
                }

                $iniciaEn = CarbonImmutable::parse($dia->toDateString().' '.$plantilla->hora_local, $zona)->utc();
                $terminaEn = $iniciaEn->addMinutes($plantilla->duracion_minutos);

                // Ya generada (aunque se haya movido, editado o cancelado): no se toca.
                $existe = SesionTenant::query()
                    ->where('serie_id', $plantilla->getKey())
                    ->where(fn ($q) => $q->whereDate('fecha_serie', $dia->toDateString())->orWhere('inicia_en', $iniciaEn))
                    ->exists();
                if ($existe) {
                    continue;
                }

                $conflictos = $this->agenda->conflictos(
                    $plantilla->instructor_id !== null ? (int) $plantilla->instructor_id : null,
                    $recurso,
                    $iniciaEn,
                    $terminaEn,
                    null,
                    (int) $plantilla->sucursal_id,
                    (int) $plantilla->getKey(),
                    MargenesServicio::de($plantilla->oferta),
                );
                if ($conflictos !== []) {
                    $omitidas[] = ['fecha' => $dia->toDateString(), 'motivo' => $conflictos[0]['mensaje']];

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
                        'fecha_serie' => $dia->toDateString(),
                    ],
                );

                if ($sesion->wasRecentlyCreated) {
                    $creadas++;
                }
            }

            return new GeneracionDeAgenda($creadas, $omitidas);
        });
    }
}
