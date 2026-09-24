<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Carbon;

/**
 * Alta y cierre de tareas de seguimiento tenant-local (R16). Lo usan tanto el listener
 * de automatización (tareas generadas por reglas) como el staff (tareas manuales).
 */
class GestionarTareasTenant
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): TareaTenant
    {
        return TareaTenant::query()->create(array_merge(
            ['estado' => EstadoTarea::Pendiente->value],
            $datos,
        ));
    }

    public function completar(TareaTenant $tarea, ?Usuario $actor = null): TareaTenant
    {
        $tarea->update([
            'estado' => EstadoTarea::Completada->value,
            'completada_en' => Carbon::now(),
            'completada_por' => $actor?->getKey(),
        ]);

        return $tarea->refresh();
    }

    public function reabrir(TareaTenant $tarea): TareaTenant
    {
        $tarea->update([
            'estado' => EstadoTarea::Pendiente->value,
            'completada_en' => null,
            'completada_por' => null,
        ]);

        return $tarea->refresh();
    }
}
