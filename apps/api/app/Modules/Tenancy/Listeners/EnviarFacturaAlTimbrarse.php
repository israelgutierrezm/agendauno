<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Facturacion\EnviarFacturaPorCorreo;
use App\Modules\Tenancy\Models\Estudio;

/**
 * Al timbrarse una factura, su CFDI va por correo a quien se le facturó (ADR 0212), en
 * cola ({@see EnviarFacturaPorCorreo}).
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class EnviarFacturaAlTimbrarse
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        $estudio = $this->gestor->actual();
        if ($evento->tipo !== 'factura.timbrada' || $evento->agregadoId === null || ! $estudio instanceof Estudio) {
            return;
        }
        EnviarFacturaPorCorreo::dispatch((int) $estudio->getKey(), $evento->agregadoId);
    }
}
