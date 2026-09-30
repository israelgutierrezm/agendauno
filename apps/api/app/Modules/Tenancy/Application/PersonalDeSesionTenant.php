<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\RolSesionTenant;
use Illuminate\Support\Collection;

/**
 * Quién imparte una clase o cita y quién cobra por ella (ADR 0081). Lo usan la
 * nómina, la rentabilidad y la agenda del equipo, para que cuadren entre sí.
 *
 * - El profesional de la sesión (`instructor_id`, el de la agenda) la imparte aunque
 *   nadie lo asigne aparte como personal.
 * - El personal asignado (R17) cobra con su rol: instructor, asistente o sustituto.
 * - Un sustituto reemplaza a quien indica (`sustituye_a`) o, sin indicarlo, al
 *   profesional de la sesión: el reemplazado no la imparte ni cobra.
 */
final class PersonalDeSesionTenant
{
    /**
     * Quienes cobran la sesión, con su rol.
     *
     * @param  Collection<int, AsignacionSesionTenant>  $asignaciones  las de esta sesión
     * @return array<int, RolSesionTenant> por usuario_id
     */
    public static function participantes(SesionTenant $sesion, Collection $asignaciones): array
    {
        $participantes = [];
        if ($sesion->instructor_id !== null) {
            $participantes[(int) $sesion->instructor_id] = RolSesionTenant::Instructor;
        }
        foreach ($asignaciones as $asignacion) {
            $participantes[(int) $asignacion->usuario_id] = $asignacion->rol;
        }
        foreach (self::reemplazados($sesion, $asignaciones) as $usuarioId) {
            unset($participantes[$usuarioId]);
        }

        return $participantes;
    }

    /**
     * Quién la imparte: el sustituto si lo hay; si no, el profesional de la sesión o
     * el instructor asignado.
     *
     * @param  Collection<int, AsignacionSesionTenant>  $asignaciones  las de esta sesión
     */
    public static function imparte(SesionTenant $sesion, Collection $asignaciones): ?int
    {
        $participantes = self::participantes($sesion, $asignaciones);
        foreach ([RolSesionTenant::Sustituto, RolSesionTenant::Instructor] as $rol) {
            $usuarioId = array_search($rol, $participantes, true);
            if ($usuarioId !== false) {
                return $usuarioId;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, AsignacionSesionTenant>  $asignaciones
     * @return list<int>
     */
    private static function reemplazados(SesionTenant $sesion, Collection $asignaciones): array
    {
        $reemplazados = [];
        foreach ($asignaciones as $asignacion) {
            if ($asignacion->rol !== RolSesionTenant::Sustituto) {
                continue;
            }
            $reemplazado = $asignacion->sustituye_a ?? $sesion->instructor_id;
            if ($reemplazado !== null && (int) $reemplazado !== (int) $asignacion->usuario_id) {
                $reemplazados[] = (int) $reemplazado;
            }
        }

        return $reemplazados;
    }
}
