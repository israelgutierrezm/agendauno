<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * El espacio o equipo (cabina, consultorio, sillón…) que ocupa una cita (fase 2,
 * punto 2.4). Un servicio puede requerir uno de varios recursos; la cita toma el
 * primero de su sede que esté libre en su tramo ocupado (atención más márgenes). Un
 * servicio sin recursos configurados no pide ninguno: la complejidad no aparece en
 * los negocios que no la necesitan.
 */
class ElegirRecursoTenant
{
    public function __construct(private readonly VerificarAgendaTenant $agenda) {}

    public function requiere(?OfertaTenant $oferta): bool
    {
        return $oferta !== null && $oferta->recursos()->exists();
    }

    /**
     * Los que el servicio puede usar en esa sede (activos), en orden estable.
     *
     * @return Collection<int, RecursoTenant>
     */
    public function candidatos(OfertaTenant $oferta, int $sucursalId): Collection
    {
        return $oferta->recursos()
            ->where('recursos.sucursal_id', $sucursalId)
            ->where('recursos.activo', true)
            ->orderBy('recursos.id')
            ->get();
    }

    /**
     * Uno libre en ese horario, o null. Con `bloquear` toma el candado de cada
     * candidato antes de revisarlo (en orden de id): se usa al GUARDAR, dentro de la
     * transacción, para que dos solicitudes no se queden con la misma cabina.
     */
    public function libre(
        OfertaTenant $oferta,
        int $sucursalId,
        CarbonInterface $inicia,
        CarbonInterface $termina,
        MargenesServicio $margenes,
        ?int $excluirSesionId = null,
        bool $bloquear = false,
    ): ?RecursoTenant {
        foreach ($this->candidatos($oferta, $sucursalId) as $recurso) {
            if ($bloquear) {
                $this->agenda->bloquear(null, $recurso);
            }
            if ($this->agenda->conflictos(null, $recurso, $inicia, $termina, $excluirSesionId, $sucursalId, null, $margenes) === []) {
                return $recurso;
            }
        }

        return null;
    }
}
