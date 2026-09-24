<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AcuerdoTenant;
use Illuminate\Support\Carbon;

/**
 * La deuda de un periodo de membresía (su orden de renovación) quedó pagada, por
 * cualquier camino: cobro al momento, webhook de la pasarela o pago en caja. Solo
 * entonces la fecha de renovación pasa al siguiente periodo y se cierra la mora
 * (reactiva el acuerdo si estaba suspendido). Lo llama el fulfillment.
 */
class RenovacionPagadaTenant
{
    // Cadencia de cobro (meses) entre renovaciones.
    public const PERIODO_MESES = 1;

    public function __construct(private readonly GestionarDunningTenant $dunning) {}

    public function registrar(AcuerdoTenant $acuerdo): void
    {
        // Del periodo que se pagó al siguiente; si ya pasaron varios (p. ej. estuvo
        // suspendido), salta al próximo futuro sin acumular cobros atrasados.
        $siguiente = Carbon::parse($acuerdo->proxima_cobro_en ?? Carbon::now())->addMonths(self::PERIODO_MESES);
        while ($siguiente->isPast()) {
            $siguiente = $siguiente->addMonths(self::PERIODO_MESES);
        }

        $acuerdo->update(['proxima_cobro_en' => $siguiente->toDateString()]);
        $this->dunning->registrarPago($acuerdo);
    }
}
