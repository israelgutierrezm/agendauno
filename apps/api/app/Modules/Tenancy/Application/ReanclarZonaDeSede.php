<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-ancla en la cola las fechas de una sede que cambió de zona, cuando son muchas para
 * hacerlo en la solicitud (ADR 0230). Lleva el negocio: corre en su base. Idempotente
 * ({@see ReanclarZonaDeSedeTenant}): un reintento solo termina lo que faltó.
 */
class ReanclarZonaDeSede implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $estudioId,
        public readonly string $sucursalUlid,
    ) {}

    public function handle(GestorDeConexionTenant $gestor, ReanclarZonaDeSedeTenant $reanclar): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio || ! $gestor->baseDeDatosExiste($estudio)) {
            return;
        }
        $reanclarSede = function () use ($reanclar): void {
            $sucursal = SucursalTenant::query()->where('ulid', $this->sucursalUlid)->first();
            if ($sucursal instanceof SucursalTenant) {
                $reanclar->reanclar($sucursal);
            }
        };
        // Con la cola síncrona corre dentro de la solicitud de ese mismo negocio: sin cambiar
        // de conexión (cambiarla cortaría lo que la solicitud tiene abierto).
        $actual = $gestor->actual();
        if ($actual instanceof Estudio && (int) $actual->getKey() === (int) $estudio->getKey()) {
            $reanclarSede();

            return;
        }
        $gestor->ejecutarEn($estudio, $reanclarSede);
    }
}
