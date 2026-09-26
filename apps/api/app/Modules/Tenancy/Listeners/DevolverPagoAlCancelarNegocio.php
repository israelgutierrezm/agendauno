<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\Exceptions\PagoNoReembolsable;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Reservas\QuienCancela;

/**
 * Si el negocio lo decide (`cancelacion.devolver_pago_si_cancela_negocio`, ADR 0046),
 * cuando cancela una cita o clase que el cliente ya pagó en línea, el pago se devuelve
 * solo por la misma pasarela.
 *
 * - Solo si canceló el negocio (la reserva o la sesión completa): si cancela el
 *   cliente aplica la política de cancelación.
 * - Solo pagos en línea: el efectivo se devuelve en caja, a mano.
 * - Idempotente: la devolución lleva la llave de la reserva (el relay es
 *   at-least-once). Si ya se devolvió o la pasarela no puede, no hace nada más: queda
 *   para el negocio en Pagos.
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class DevolverPagoAlCancelarNegocio
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly ReembolsarPagoTenant $reembolsar,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if (! in_array($evento->tipo, ['reserva.cancelada', 'reserva.sesion_cancelada'], true)
            || $evento->agregadoId === null
            || ! $this->parametros->siNo('cancelacion.devolver_pago_si_cancela_negocio')) {
            return;
        }

        $reserva = ReservaTenant::query()->where('ulid', $evento->agregadoId)->with('orden')->first();
        if (! $reserva instanceof ReservaTenant
            || $reserva->cancelada_por !== QuienCancela::Negocio->value
            || $reserva->orden === null
            || $reserva->orden->estado !== EstadoOrden::Pagada) {
            return;
        }

        $pagos = PagoTenant::query()
            ->where('orden_id', $reserva->orden->getKey())
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value])
            ->whereIn('proveedor', ProveedorPasarela::enLinea())
            ->get();
        foreach ($pagos as $pago) {
            try {
                $this->reembolsar->ejecutar(
                    $pago,
                    null,
                    'El negocio canceló la reserva.',
                    llave: 'cancelacion-negocio:'.$reserva->ulid,
                );
            } catch (PagoNoReembolsable) {
                // Ya devuelto, o la pasarela no devuelve en línea: lo resuelve el negocio.
            }
        }
    }
}
