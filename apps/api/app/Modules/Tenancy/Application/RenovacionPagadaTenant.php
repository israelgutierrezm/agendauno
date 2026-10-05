<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\Aniversario;
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

    public function __construct(
        private readonly GestionarDunningTenant $dunning,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    public function registrar(AcuerdoTenant $acuerdo): void
    {
        // Del periodo que se pagó al siguiente aniversario (sin desbordar febrero); si ya
        // pasaron varios (p. ej. estuvo suspendido), salta al próximo futuro (en el día
        // del negocio) sin acumular cobros atrasados.
        $base = $acuerdo->proxima_cobro_en ?? Carbon::parse($this->fechas->hoy());
        $ancla = (int) ($acuerdo->dia_ancla ?? Aniversario::diaDe($base));
        $meses = self::PERIODO_MESES;
        $siguiente = Aniversario::siguiente($base, $ancla, $meses);
        while ($siguiente->toDateString() <= $this->fechas->hoy()) {
            $meses += self::PERIODO_MESES;
            $siguiente = Aniversario::siguiente($base, $ancla, $meses);
        }

        $acuerdo->update(['proxima_cobro_en' => $siguiente->toDateString()]);
        $this->dunning->registrarPago($acuerdo);
    }
}
