<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\FacturarCitasEnAutomatico;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Al pagarse la orden de una cita, su factura en automático si el cliente la pidió
 * ({@see FacturarCitasEnAutomatico}). Si el timbrado falla, la orden queda para el
 * reintento (`agendauno:facturar-citas`); el aviso no se repite.
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider. Corre en la
 * cola con el negocio del evento: timbrar llama a FacturAPI.
 */
class FacturarCitaAlPagarse implements ShouldQueue
{
    /** En la cola (ADR 0224): un proveedor lento no detiene el outbox de todos. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** Solo se encola lo que le toca: los demás eventos no hacen un trabajo. */
    public function shouldQueue(EventoDeDominioTenant $evento): bool
    {
        return $evento->tipo === 'orden.pagada' && $evento->agregadoId !== null;
    }

    public function __construct(private readonly FacturarCitasEnAutomatico $facturar) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if ($evento->tipo !== 'orden.pagada' || $evento->agregadoId === null) {
            return;
        }
        $orden = OrdenTenant::query()->where('ulid', $evento->agregadoId)->first();
        if (! $orden instanceof OrdenTenant) {
            return;
        }
        try {
            $this->facturar->alPagarse($orden);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
