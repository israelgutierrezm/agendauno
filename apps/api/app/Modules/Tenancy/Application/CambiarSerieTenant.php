<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Esta y las siguientes" sobre una clase recurrente (fase 2, punto 2.5): cambia la
 * hora, la duración, el profesional o la sala desde una fecha.
 *
 * - El historial no se toca: si la serie ya tuvo fechas antes, se parte en dos (la
 *   anterior termina un día antes y una nueva empieza en la fecha elegida).
 * - Las sesiones futuras desde esa fecha se mueven con las reglas únicas de agenda.
 *   La que choca se queda como estaba y se explica por qué. La que se cambió a mano
 *   (`editada_en`) o ya tiene asistencia se conserva. Sus reservas las siguen y
 *   reciben el aviso del cambio.
 * - No duplica: cada sesión conserva su fecha de serie y pasa a la serie nueva, así
 *   la generación no la vuelve a crear.
 * - La vista previa ejecuta lo mismo dentro de una transacción que se revierte: lo
 *   que muestra es exactamente lo que pasaría.
 */
class CambiarSerieTenant
{
    public function __construct(
        private readonly ReprogramarTenant $reprogramar,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  array{hora_local?: string, duracion_minutos?: int, instructor_id?: int|null, recurso_id?: int|null}  $cambios
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
     * @param  array{hora_local?: string, duracion_minutos?: int, instructor_id?: int|null, recurso_id?: int|null}  $cambios
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

        $nuevos = array_intersect_key($cambios, array_flip(['hora_local', 'duracion_minutos', 'instructor_id', 'recurso_id']));

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

        $inicioDelDia = CarbonImmutable::parse($fecha, $zona)->startOfDay()->utc();
        $sesiones = SesionTenant::query()
            ->where('serie_id', $bloqueada->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            ->where(fn ($q) => $q->whereDate('fecha_serie', '>=', $fecha)
                ->orWhere(fn ($q2) => $q2->whereNull('fecha_serie')->where('inicia_en', '>=', $inicioDelDia)))
            ->orderBy('inicia_en')
            ->lockForUpdate()
            ->get();

        $movidas = 0;
        $conservadas = [];
        foreach ($sesiones as $sesion) {
            $dia = $sesion->fecha_serie?->toDateString()
                ?? CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona)->toDateString();
            // Pertenece a la serie nueva, aunque se conserve como estaba.
            $sesion->serie_id = (int) $serie->getKey();
            $sesion->setAttribute('fecha_serie', $dia);

            $protegida = $sesion->editada_en !== null
                ? 'Se conserva el cambio hecho a esa fecha.'
                : ($sesion->reservas()->whereHas('asistencia')->exists() ? 'Ya tiene asistencia registrada.' : null);
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

        $this->auditoria->registrar($actor, 'plantilla_horario.cambiada', 'plantilla_horario', (string) $serie->ulid, null, [
            'desde' => $fecha,
            'hora_local' => $serie->hora_local,
            'duracion_minutos' => $serie->duracion_minutos,
            'movidas' => $movidas,
            'conservadas' => count($conservadas),
        ]);

        return new CambioDeSerie($movidas, $conservadas, (string) $serie->ulid);
    }
}
