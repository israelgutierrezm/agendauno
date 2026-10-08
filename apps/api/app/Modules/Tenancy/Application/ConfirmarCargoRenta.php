<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Confirma un cargo de renta pendiente a partir de la referencia del intento (la que
 * envia el webhook de la pasarela de la plataforma) y lo marca `pagado`.
 * Idempotente: un cargo que no esta pendiente no se reprocesa. Si el negocio estaba
 * suspendido por renta y ya no debe, se reactiva al momento (ADR 0073).
 */
class ConfirmarCargoRenta
{
    public function __construct(
        private readonly SuspensionPorRenta $suspension,
        private readonly AcreditarTimbresPagados $timbres,
    ) {}

    public function porReferencia(string $referencia, string $proveedor): void
    {
        if ($referencia === '') {
            return;
        }

        $pagado = DB::transaction(function () use ($referencia, $proveedor): ?CargoRenta {
            $cargo = CargoRenta::query()
                ->where('referencia_pago', $referencia)
                ->lockForUpdate()
                ->first();

            if (! $cargo instanceof CargoRenta || $cargo->estado !== EstadoCargoRenta::Pendiente) {
                return null;
            }

            $cargo->update([
                'estado' => EstadoCargoRenta::Pagado->value,
                'pagado_en' => Carbon::now(),
                'metodo_pago' => $proveedor,
            ]);

            return $cargo;
        });

        $this->suspension->reactivarSiPago($pagado?->estudio);
        // Una compra de timbres: se suman al saldo del negocio.
        $this->timbres->aplicar($pagado);
    }

    /**
     * El intento de pago de la renta ya no se puede pagar (sesión vencida, pago en
     * tienda no completado): el cargo sigue pendiente, sin intento en curso. Una
     * compra de timbres, en cambio, se cancela (se compra otra cuando haga falta).
     */
    public function intentoTerminado(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        CargoRenta::query()
            ->where('referencia_pago', $referencia)
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->where('concepto', 'timbres')
            ->update(['estado' => EstadoCargoRenta::Cancelado->value]);
        CargoRenta::query()
            ->where('referencia_pago', $referencia)
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->update(['referencia_pago' => null]);
    }
}
