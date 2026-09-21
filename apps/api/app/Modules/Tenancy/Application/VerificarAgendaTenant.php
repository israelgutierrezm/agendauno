<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;

/**
 * Verifica los conflictos de agenda ANTES de guardar una sesión (rework de Agenda):
 * un instructor no puede dar dos clases a la vez, y un recurso/sala no puede
 * sobre-reservarse (reusa {@see VerificarRecursoTenant}, R3). Devuelve los conflictos
 * de forma estructurada para (a) avisar en el formulario y (b) bloquear el guardado.
 * Opera sobre la BD del tenant resuelto.
 */
class VerificarAgendaTenant
{
    public function __construct(private readonly VerificarRecursoTenant $recursos) {}

    /**
     * Conflictos de una sesión propuesta. `excluirSesionId` omite la propia sesión
     * (al reprogramar/editar).
     *
     * @return list<array{tipo: string, campo: string, mensaje: string, sesion: string|null}>
     */
    public function conflictos(
        ?int $instructorId,
        ?RecursoTenant $recurso,
        CarbonInterface $inicia,
        CarbonInterface $termina,
        ?int $excluirSesionId = null,
    ): array {
        $conflictos = [];

        // Instructor: no puede impartir dos clases que se solapan.
        if ($instructorId !== null) {
            $choque = SesionTenant::query()
                ->where('instructor_id', $instructorId)
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->where('inicia_en', '<', $termina)
                ->where('termina_en', '>', $inicia)
                ->when($excluirSesionId !== null, fn ($q) => $q->where('id', '!=', $excluirSesionId))
                ->with('oferta')
                ->first();

            if ($choque instanceof SesionTenant) {
                $conflictos[] = [
                    'tipo' => 'instructor',
                    'campo' => 'instructor_id',
                    'mensaje' => 'El instructor ya tiene una clase en ese horario'.($choque->oferta !== null ? ' ('.$choque->oferta->nombre.')' : '').'.',
                    'sesion' => $choque->ulid,
                ];
            }
        }

        // Recurso/sala: no puede superar su cupo simultáneo en el intervalo.
        if ($recurso !== null && ! $this->recursos->disponible($recurso, $inicia, $termina, null)) {
            $conflictos[] = [
                'tipo' => 'recurso',
                'campo' => 'recurso_id',
                'mensaje' => 'El recurso o sala ya está ocupado en ese horario.',
                'sesion' => null,
            ];
        }

        return $conflictos;
    }
}
