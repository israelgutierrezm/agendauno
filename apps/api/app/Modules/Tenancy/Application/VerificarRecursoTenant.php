<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;

/**
 * Motor de disponibilidad de recursos (R3): impide sobre-reservar un recurso. Cuenta
 * las sesiones PROGRAMADAS cuyo tramo ocupado (atención más preparación y limpieza,
 * 2.3) solapa el intervalo y usan el mismo recurso (sueltas, citas o de una serie);
 * si ya se alcanzó su cupo simultáneo (UNIDAD=1, POOL=capacidad) el recurso no está
 * disponible. El intervalo que se pasa es también el que ocuparía la nueva sesión.
 * Opera sobre la BD del tenant.
 */
class VerificarRecursoTenant
{
    /**
     * @param  int|null  $excluirSerieId  no cuenta las de esa serie (al generarla)
     * @param  int|null  $excluirSesionId  no cuenta esa sesión (al editarla)
     */
    public function disponible(RecursoTenant $recurso, CarbonInterface $inicia, CarbonInterface $termina, ?int $excluirSerieId = null, ?int $excluirSesionId = null): bool
    {
        $solapadas = SesionTenant::query()
            ->where('recurso_id', $recurso->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('ocupa_desde', '<', $termina)
            ->where('ocupa_hasta', '>', $inicia)
            // Ojo: `serie_id != X` sola dejaría fuera las sesiones sin serie (NULL).
            ->when($excluirSerieId !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('serie_id')->orWhere('serie_id', '!=', $excluirSerieId)))
            ->when($excluirSesionId !== null, fn ($q) => $q->where('id', '!=', $excluirSesionId))
            ->count();

        return $solapadas < $recurso->cupoSimultaneo();
    }
}
