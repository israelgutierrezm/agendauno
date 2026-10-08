<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Throwable;

/**
 * Una compra de timbres pagada (cargo `timbres` del control plane) se suma al saldo
 * de timbres del negocio, en su base (ADR 0107). Una sola vez por cargo. Si la base
 * del negocio no responde, se repone la siguiente vez que se consultan sus timbres.
 */
class AcreditarTimbresPagados
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly TimbresTenant $timbres,
    ) {}

    public function aplicar(?CargoRenta $cargo): void
    {
        if (! $cargo instanceof CargoRenta || $cargo->concepto !== 'timbres' || $cargo->estado !== EstadoCargoRenta::Pagado) {
            return;
        }
        $estudio = $cargo->estudio;
        if (! $estudio instanceof Estudio) {
            return;
        }

        $cantidad = (int) $cargo->alumnos_activos;
        $acreditar = fn () => $this->timbres->acreditar($cantidad, 'cargo:'.$cargo->ulid, "Paquete de {$cantidad} timbres");
        try {
            $this->gestor->actual()?->is($estudio) === true ? $acreditar() : $this->gestor->ejecutarEn($estudio, $acreditar);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Repone las compras pagadas de un negocio que no se sumaron. */
    public function sincronizar(Estudio $estudio): void
    {
        CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('concepto', 'timbres')
            ->where('estado', EstadoCargoRenta::Pagado->value)
            ->get()
            ->each(fn (CargoRenta $cargo) => $this->aplicar($cargo->setRelation('estudio', $estudio)));
    }
}
