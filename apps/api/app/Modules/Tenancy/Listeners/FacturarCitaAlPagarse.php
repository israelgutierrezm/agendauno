<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\FacturarCitasEnAutomatico;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Throwable;

/**
 * Al pagarse la orden de una cita, su factura en automático si el cliente la pidió
 * ({@see FacturarCitasEnAutomatico}). Si el timbrado falla, la orden queda para el
 * reintento (`agendauno:facturar-citas`); el aviso no se repite.
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class FacturarCitaAlPagarse
{
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
