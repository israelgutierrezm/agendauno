<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Automatizacion\ResultadoSeguimiento;
use App\Modules\Tenancy\Models\PersonaTenant;
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

    /**
     * Registra con qué terminó un seguimiento (M09) y lo cierra. «No desea contacto» lo
     * saca de las promociones (y de los seguimientos comerciales), con constancia en la
     * bitácora.
     */
    public function registrarResultado(TareaTenant $tarea, ResultadoSeguimiento $resultado, ?string $nota, ?Usuario $actor = null): TareaTenant
    {
        $detalle = $nota !== null && trim($nota) !== ''
            ? trim((string) $tarea->detalle."\n\nResultado: ".trim($nota))
            : $tarea->detalle;
        $tarea->update([
            'resultado' => $resultado->value,
            'contactado_en' => Carbon::now(),
            'detalle' => $detalle,
            'estado' => EstadoTarea::Completada->value,
            'completada_en' => Carbon::now(),
            'completada_por' => $actor?->getKey(),
        ]);

        $persona = $tarea->persona_id === null ? null : PersonaTenant::query()->find($tarea->persona_id);
        if ($resultado === ResultadoSeguimiento::NoDeseaContacto && $persona instanceof PersonaTenant && $persona->recibe_promociones) {
            $persona->update(['recibe_promociones' => false]);
            app(RegistrarAuditoria::class)->registrar($actor, 'persona.sin_promociones', 'persona', (string) $persona->ulid,
                ['recibe_promociones' => true], ['recibe_promociones' => false], 'No desea contacto (seguimiento)');
        }

        return $tarea->refresh();
    }

    public function reabrir(TareaTenant $tarea): TareaTenant
    {
        // Reabierta, el seguimiento aún no tiene resultado (la fecha del contacto queda).
        $tarea->update([
            'estado' => EstadoTarea::Pendiente->value,
            'completada_en' => null,
            'completada_por' => null,
            'resultado' => null,
        ]);

        return $tarea->refresh();
    }
}
