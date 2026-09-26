<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Esta y las siguientes" sobre una clase recurrente (fase 2, punto 2.5): cambia los
 * días, la hora, la duración, el profesional o la sala desde una fecha.
 *
 * - El historial no se toca: si la serie ya tuvo fechas antes, se parte en dos (la
 *   anterior termina un día antes y una nueva empieza en la fecha elegida).
 * - Las sesiones futuras desde esa fecha se mueven con las reglas únicas de agenda.
 *   La que choca se queda como estaba y se explica por qué. La que se cambió a mano
 *   (`editada_en`) o ya tiene asistencia se conserva. Sus reservas las siguen y
 *   reciben el aviso del cambio.
 * - Días que dejan de ser de la clase: la fecha que nunca se usó se borra; la que tuvo
 *   movimiento se cancela; la que tiene reservas activas se conserva y se explica
 *   (cancelarla o mover a las personas es decisión del negocio). En los días nuevos se
 *   crean las fechas hasta donde ya estaba generada la serie (o el horizonte del
 *   negocio), con las mismas reglas que al generar.
 * - No duplica: cada sesión conserva su fecha de serie y pasa a la serie nueva, así
 *   la generación no la vuelve a crear.
 * - La vista previa ejecuta lo mismo dentro de una transacción que se revierte: lo
 *   que muestra es exactamente lo que pasaría.
 */
class CambiarSerieTenant
{
    /** Reservas que ocupan o esperan lugar: con ellas, la fecha no se quita sola. */
    private const ACTIVAS = [
        EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
        EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
    ];

    public function __construct(
        private readonly ReprogramarTenant $reprogramar,
        private readonly RegistrarAuditoria $auditoria,
        private readonly GenerarAgendaTenant $generar,
        private readonly ReservasTenant $reservas,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @param  array{dias_semana?: list<int>, hora_local?: string, duracion_minutos?: int, instructor_id?: int|null, recurso_id?: int|null}  $cambios
     */
    public function desde(PlantillaHorarioTenant $plantilla, string $fecha, array $cambios, ?Usuario $actor, bool $aplicar): CambioDeSerie
    {
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            $resultado = $this->aplicar($plantilla, $fecha, $cambios, $actor);
            $aplicar ? $db->commit() : $db->rollBack();

            return $resultado;
        } catch (Throwable $e) {
            $db->rollBack();

            throw $e;
        }
    }

    /**
     * @param  array{dias_semana?: list<int>, hora_local?: string, duracion_minutos?: int, instructor_id?: int|null, recurso_id?: int|null}  $cambios
     */
    private function aplicar(PlantillaHorarioTenant $plantilla, string $fecha, array $cambios, ?Usuario $actor): CambioDeSerie
    {
        $bloqueada = PlantillaHorarioTenant::query()->whereKey($plantilla->getKey())->lockForUpdate()->firstOrFail();
        $bloqueada->loadMissing('sucursal');
        $zona = (string) ($bloqueada->sucursal->zona_horaria ?? 'UTC');

        if ($fecha < CarbonImmutable::now($zona)->toDateString()) {
            throw ValidationException::withMessages(['desde' => ['Solo se cambian fechas de hoy en adelante.']]);
        }
        if ($bloqueada->vigente_hasta !== null && $fecha > $bloqueada->vigente_hasta->toDateString()) {
            throw ValidationException::withMessages(['desde' => ['Esa fecha ya no es de esta clase recurrente.']]);
        }

        $nuevos = array_intersect_key($cambios, array_flip(['dias_semana', 'hora_local', 'duracion_minutos', 'instructor_id', 'recurso_id']));
        $cambianDias = isset($nuevos['dias_semana'])
            && $nuevos['dias_semana'] !== $this->dias($bloqueada);
        // Hasta dónde estaba generada: los días nuevos llegan igual de lejos.
        $ultima = SesionTenant::query()->where('serie_id', $bloqueada->getKey())->max('inicia_en');

        // Historial: si la serie ya tuvo fechas antes, se parte en dos.
        if ($bloqueada->vigente_desde->toDateString() < $fecha) {
            $serie = $bloqueada->replicate(['ulid']);
            $serie->fill([...$nuevos, 'vigente_desde' => $fecha, 'vigente_hasta' => $bloqueada->vigente_hasta?->toDateString()]);
            $serie->save();
            $bloqueada->update(['vigente_hasta' => CarbonImmutable::parse($fecha)->subDay()->toDateString()]);
        } else {
            $bloqueada->update($nuevos);
            $serie = $bloqueada;
        }
        $dias = $this->dias($serie);

        $inicioDelDia = CarbonImmutable::parse($fecha, $zona)->startOfDay()->utc();
        // Todas las fechas desde ahí pasan a la serie nueva (también las canceladas o
        // ya pasadas de hoy, para que la generación no las vuelva a crear); solo las
        // programadas por venir se mueven.
        $sesiones = SesionTenant::query()
            ->where('serie_id', $bloqueada->getKey())
            ->where(fn ($q) => $q->whereDate('fecha_serie', '>=', $fecha)
                ->orWhere(fn ($q2) => $q2->whereNull('fecha_serie')->where('inicia_en', '>=', $inicioDelDia)))
            ->orderBy('inicia_en')
            ->lockForUpdate()
            ->get();

        $movidas = 0;
        $quitadas = 0;
        $conservadas = [];
        foreach ($sesiones as $sesion) {
            $dia = $sesion->fecha_serie?->toDateString()
                ?? CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona)->toDateString();
            // Pertenece a la serie nueva, aunque se conserve como estaba.
            $sesion->serie_id = (int) $serie->getKey();
            $sesion->setAttribute('fecha_serie', $dia);
            if ($sesion->estado !== EstadoSesionTenant::Programada || ! $sesion->inicia_en->isFuture()) {
                $sesion->save();

                continue;
            }

            $protegida = $sesion->editada_en !== null
                ? 'Se conserva el cambio hecho a esa fecha.'
                : ($sesion->reservas()->whereHas('asistencia')->exists() ? 'Ya tiene asistencia registrada.' : null);
            if ($protegida === null && ! in_array(CarbonImmutable::parse($dia)->dayOfWeekIso, $dias, true)) {
                $protegida = $this->quitar($sesion, $actor);
                if ($protegida === null) {
                    $quitadas++;

                    continue;
                }
            }
            if ($protegida !== null) {
                $sesion->save();
                $conservadas[] = ['fecha' => $dia, 'motivo' => $protegida];

                continue;
            }

            $inicia = CarbonImmutable::parse($dia.' '.$serie->hora_local, $zona)->utc();
            $termina = $inicia->addMinutes((int) $serie->duracion_minutos);
            $motivo = $this->reprogramar->moverInstancia(
                $sesion,
                $inicia,
                $termina,
                $serie->instructor_id !== null ? (int) $serie->instructor_id : null,
                $serie->recurso_id !== null ? (int) $serie->recurso_id : null,
            );
            if ($motivo !== null) {
                $sesion->save();
                $conservadas[] = ['fecha' => $dia, 'motivo' => $motivo];

                continue;
            }
            $movidas++;
        }

        // Días nuevos: sus fechas, hasta donde ya estaba generada la serie o el
        // horizonte del negocio (lo que llegue más lejos).
        $creadas = 0;
        $omitidas = [];
        if ($cambianDias) {
            $hasta = CarbonImmutable::now($zona)->addDays($this->parametros->entero('agenda.dias_a_generar'))->toDateString();
            if (is_string($ultima)) {
                $hasta = max($hasta, CarbonImmutable::parse($ultima)->setTimezone($zona)->toDateString());
            }
            $generacion = $this->generar->ejecutar($serie->refresh(), $fecha, $hasta);
            $creadas = $generacion->creadas;
            $omitidas = $generacion->omitidas;
        }

        $this->auditoria->registrar($actor, 'plantilla_horario.cambiada', 'plantilla_horario', (string) $serie->ulid, null, [
            'desde' => $fecha,
            'dias_semana' => $dias,
            'hora_local' => $serie->hora_local,
            'duracion_minutos' => $serie->duracion_minutos,
            'movidas' => $movidas,
            'conservadas' => count($conservadas),
            'quitadas' => $quitadas,
            'creadas' => $creadas,
        ]);

        return new CambioDeSerie($movidas, $conservadas, (string) $serie->ulid, $quitadas, $creadas, $omitidas);
    }

    /**
     * Una fecha que deja de ser de la clase. Con reservas activas se conserva (dice
     * por qué); si nunca se usó se borra; si tuvo movimiento (reservas canceladas,
     * pagos, check-ins, accesos o personal) se cancela para no perder ese historial.
     */
    private function quitar(SesionTenant $sesion, ?Usuario $actor): ?string
    {
        if ($sesion->reservas()->whereIn('estado', self::ACTIVAS)->exists()) {
            return 'Tiene reservas: cancela esa fecha o cambia a las personas de fecha.';
        }

        $db = DB::connection('tenant');
        $id = (int) $sesion->getKey();
        $usada = $sesion->reservas()->exists()
            || $db->table('checkins')->where('sesion_id', $id)->exists()
            || $db->table('accesos')->where('sesion_id', $id)->exists()
            || $db->table('asignaciones_sesion')->where('sesion_id', $id)->exists()
            || $db->table('ordenes')->where('sesion_id', $id)->exists();
        if ($usada) {
            $sesion->save();
            $this->reservas->cancelarSesion($sesion, $actor);
        } else {
            $sesion->delete();
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function dias(PlantillaHorarioTenant $serie): array
    {
        $dias = array_values(array_unique(array_map('intval', $serie->dias_semana ?? [])));
        sort($dias);

        return $dias;
    }
}
