<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TokenAccesoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Al darse de baja un negocio (ADR 0213), en su base: se quitan los pagos automáticos
 * de sus clientes (una suscripción se cancela en la pasarela) y se cierran las sesiones
 * de todos menos sus dueños, que aún entran a descargar sus datos o revertir la baja.
 * En cola: la pasarela puede tardar o fallar, y se reintenta.
 */
class CerrarNegocioDadoDeBaja implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public readonly int $estudioId) {}

    public function handle(GestorDeConexionTenant $gestor, DomiciliacionesTenant $domiciliaciones): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio || ! $estudio->enPlazoDeBaja() || ! $gestor->baseDeDatosExiste($estudio)) {
            return;
        }
        $cerrar = static function () use ($domiciliaciones): void {
            $domiciliaciones->desactivarTodas();
            $duenos = Usuario::query()->get()
                ->filter(static fn (Usuario $u): bool => $u->tieneRol('propietario'))
                ->modelKeys();
            TokenAccesoTenant::query()
                ->where('tokenable_type', Usuario::class)
                ->whereNotIn('tokenable_id', $duenos)
                ->delete();
        };
        // Con la cola síncrona corre dentro de la petición de ese mismo negocio: sin
        // cambiar de conexión.
        $actual = $gestor->actual();
        if ($actual instanceof Estudio && (int) $actual->getKey() === (int) $estudio->getKey()) {
            $cerrar();

            return;
        }
        $gestor->ejecutarEn($estudio, $cerrar);
    }
}
