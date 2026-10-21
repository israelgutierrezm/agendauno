<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\NivelTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\Exceptions\NivelRequerido;
use Illuminate\Support\Facades\DB;

/**
 * El nivel de cada cliente por actividad (ADR 0187). El negocio (o su coach) le asigna
 * un nivel; una clase que pide un nivel solo la reserva el cliente que tiene ese nivel o
 * uno más alto (por el orden de los niveles de la actividad). Lo valida el servidor al
 * reservar desde la cuenta o la app; el equipo puede reservarle de todos modos.
 */
class NivelesPersonaTenant
{
    /** El nivel que tiene en esa actividad, o null. */
    public function nivelDe(PersonaTenant $persona, int $actividadId): ?NivelTenant
    {
        $id = DB::connection('tenant')->table('personas_niveles')
            ->where('persona_id', $persona->getKey())
            ->where('actividad_id', $actividadId)
            ->value('nivel_id');

        return $id === null ? null : NivelTenant::query()->find((int) $id);
    }

    /** Por qué no puede reservar esa clase por su nivel, o null si puede. */
    public function motivo(PersonaTenant $persona, SesionTenant $sesion): ?string
    {
        $sesion->loadMissing('oferta');
        $requerido = $sesion->oferta?->nivel_id !== null ? NivelTenant::query()->find((int) $sesion->oferta->nivel_id) : null;
        if (! $requerido instanceof NivelTenant) {
            return null;
        }
        $suyo = $this->nivelDe($persona, (int) $requerido->actividad_id);
        if ($suyo !== null && $suyo->orden >= $requerido->orden) {
            return null;
        }

        return "Esta clase es para {$requerido->nombre} o más. Pide a tu coach que revise tu nivel.";
    }

    /** @throws NivelRequerido */
    public function exigir(PersonaTenant $persona, SesionTenant $sesion): void
    {
        $motivo = $this->motivo($persona, $sesion);
        if ($motivo !== null) {
            throw new NivelRequerido($motivo);
        }
    }

    /** Asigna (o quita, con null) el nivel de una persona en una actividad. */
    public function asignar(PersonaTenant $persona, int $actividadId, ?NivelTenant $nivel, ?Usuario $actor = null): void
    {
        $tabla = DB::connection('tenant')->table('personas_niveles');
        if ($nivel === null) {
            $tabla->where('persona_id', $persona->getKey())->where('actividad_id', $actividadId)->delete();

            return;
        }
        $tabla->updateOrInsert(
            ['persona_id' => $persona->getKey(), 'actividad_id' => $actividadId],
            ['nivel_id' => $nivel->getKey(), 'asignado_por' => $actor?->getKey(), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
