<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de agenda de TODAS las formas de crear o cambiar una sesión (recepción,
 * asignar instructor o sustituto, citas y clases recurrentes), fase 1 punto 1.3:
 *
 * - un profesional no puede tener dos cosas a la vez;
 * - un recurso/sala no puede superar su cupo simultáneo, debe estar activo y ser de
 *   la misma sede que la sesión (reusa {@see VerificarRecursoTenant}, R3);
 * - se revalida al GUARDAR, bajo candado: {@see bloquear()} serializa por profesional
 *   y por recurso (siempre en ese orden, para no trabarse), así dos solicitudes
 *   simultáneas desde distintos dispositivos no pueden encimarse.
 *
 * Los choques se miden sobre lo que OCUPA cada sesión: su atención más la
 * preparación y la limpieza del servicio (fase 2, punto 2.3, {@see MargenesServicio}).
 *
 * Los conflictos salen estructurados para (a) avisar en el formulario y (b) bloquear
 * el guardado con un mensaje útil.
 */
class VerificarAgendaTenant
{
    public function __construct(private readonly VerificarRecursoTenant $recursos) {}

    /**
     * Punto de serialización: una escritura sin cambios sobre la fila del profesional
     * y la del recurso (en MySQL toma su candado exclusivo; en SQLite el de escritura).
     * La segunda solicitud espera y, al seguir, ve lo que guardó la primera. Debe
     * llamarse dentro de la transacción que guarda la sesión, ANTES de revisar.
     */
    public function bloquear(?int $instructorId, ?RecursoTenant $recurso): void
    {
        if ($instructorId !== null) {
            DB::connection('tenant')->table('users')->where('id', $instructorId)->update(['id' => DB::raw('id')]);
        }
        if ($recurso instanceof RecursoTenant) {
            DB::connection('tenant')->table('recursos')->where('id', $recurso->getKey())->update(['id' => DB::raw('id')]);
        }
    }

    /**
     * Conflictos de una sesión propuesta (su atención `inicia`–`termina` más sus
     * márgenes). `excluirSesionId` omite la propia sesión (al editarla) y
     * `excluirSerieId` las de su propia serie (al generarla).
     *
     * @return list<array{tipo: string, campo: string, mensaje: string, sesion: string|null}>
     */
    public function conflictos(
        ?int $instructorId,
        ?RecursoTenant $recurso,
        CarbonInterface $inicia,
        CarbonInterface $termina,
        ?int $excluirSesionId = null,
        ?int $sucursalId = null,
        ?int $excluirSerieId = null,
        ?MargenesServicio $margenes = null,
    ): array {
        $margenes ??= new MargenesServicio;
        $desde = $margenes->desde($inicia);
        $hasta = $margenes->hasta($termina);
        $conflictos = [];

        // Profesional: no puede atender dos cosas que se solapan (con sus márgenes).
        if ($instructorId !== null) {
            $choque = SesionTenant::query()
                ->where('instructor_id', $instructorId)
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->where('ocupa_desde', '<', $hasta)
                ->where('ocupa_hasta', '>', $desde)
                ->when($excluirSesionId !== null, fn ($q) => $q->where('id', '!=', $excluirSesionId))
                ->when($excluirSerieId !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('serie_id')->orWhere('serie_id', '!=', $excluirSerieId)))
                ->with('oferta')
                ->orderBy('inicia_en')
                ->first();

            if ($choque instanceof SesionTenant) {
                $cuando = $choque->inicia_en->copy()->setTimezone((string) ($choque->zona_horaria ?: 'UTC'))->format('H:i');
                $servicio = $choque->oferta !== null ? $choque->oferta->nombre : 'otra sesión';
                // Si las atenciones no se tocan, lo que choca es la preparación o limpieza.
                $soloMargenes = $choque->inicia_en->greaterThanOrEqualTo($termina) || $choque->termina_en->lessThanOrEqualTo($inicia);
                $conflictos[] = [
                    'tipo' => 'instructor',
                    'campo' => 'instructor_id',
                    'mensaje' => $soloMargenes
                        ? "Ese horario invade la preparación o limpieza de {$servicio} de las {$cuando}."
                        : "Esa persona ya atiende {$servicio} a las {$cuando}.",
                    'sesion' => $choque->ulid,
                ];
            }
        }

        if ($recurso instanceof RecursoTenant) {
            if (! $recurso->activo) {
                $conflictos[] = ['tipo' => 'recurso', 'campo' => 'recurso_id', 'mensaje' => "{$recurso->nombre} está fuera de servicio.", 'sesion' => null];
            } elseif ($sucursalId !== null && (int) $recurso->sucursal_id !== $sucursalId) {
                $conflictos[] = ['tipo' => 'recurso', 'campo' => 'recurso_id', 'mensaje' => "{$recurso->nombre} es de otra sede.", 'sesion' => null];
            } elseif (! $this->recursos->disponible($recurso, $desde, $hasta, $excluirSerieId, $excluirSesionId)) {
                $conflictos[] = ['tipo' => 'recurso', 'campo' => 'recurso_id', 'mensaje' => "{$recurso->nombre} no está disponible en ese horario.", 'sesion' => null];
            }
        }

        return $conflictos;
    }

    /**
     * Bloquea el guardado si hay conflictos (422 con el mensaje por campo).
     */
    public function exigirSinConflictos(
        ?int $instructorId,
        ?RecursoTenant $recurso,
        CarbonInterface $inicia,
        CarbonInterface $termina,
        ?int $excluirSesionId = null,
        ?int $sucursalId = null,
        ?MargenesServicio $margenes = null,
    ): void {
        $conflictos = $this->conflictos($instructorId, $recurso, $inicia, $termina, $excluirSesionId, $sucursalId, null, $margenes);
        if ($conflictos === []) {
            return;
        }

        $errores = [];
        foreach ($conflictos as $conflicto) {
            $errores[$conflicto['campo']][] = $conflicto['mensaje'];
        }

        throw ValidationException::withMessages($errores);
    }
}
