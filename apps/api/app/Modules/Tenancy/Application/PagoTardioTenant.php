<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;

/**
 * Llegó el pago de una orden que ya estaba cancelada (fase 1, punto 1.2): p. ej. el
 * cliente empezó a pagar su cita, venció su apartado y la confirmación llegó después.
 * "Pagado" no equivale a "confirmado":
 *
 * - si la reserva venció por falta de pago y su horario sigue libre (y aún no
 *   empieza), se reconfirma con ese pago, revalidando bajo candado
 *   ({@see ReservasTenant::reconfirmarPagoTardio()});
 * - si no, el dinero queda identificado (pago aprobado sobre una orden cancelada, que
 *   NO pasa a pagada), la incidencia queda "por conciliar" para devolverlo o reagendar,
 *   y se avisa al cliente y al equipo (`pago.tardio`).
 *
 * Nunca desplaza a otro cliente. Se llama dentro de la transacción de la confirmación.
 */
class PagoTardioTenant
{
    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly FulfillmentTenant $fulfillment,
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * Devuelve si la reserva se reconfirmó con el pago.
     */
    public function atender(PagoTenant $pago, OrdenTenant $orden): bool
    {
        $reserva = $orden->sesion_id !== null
            ? ReservaTenant::query()->where('orden_id', $orden->getKey())->latest('id')->first()
            : null;

        if ($reserva instanceof ReservaTenant && $this->reservas->reconfirmarPagoTardio($reserva)) {
            // Ya revalidado: el fulfillment la confirma y deja la orden pagada.
            $this->fulfillment->cumplir($orden->refresh());

            return true;
        }

        $orden->loadMissing('persona');
        $monto = DatosDeOrden::dinero((int) $pago->monto_minor, (string) ($pago->moneda ?: 'MXN'));
        $sesion = $reserva instanceof ReservaTenant ? SesionTenant::query()->find($reserva->sesion_id) : null;

        $this->incidencias->porPago(
            IncidenciaCobroTenant::PAGO_TARDIO,
            $pago,
            $sesion instanceof SesionTenant
                ? "Llegó el pago ({$monto}) cuando su apartado ya había vencido y el horario ya no estaba disponible. Devuélvelo o reagenda con el cliente."
                : "Llegó el pago ({$monto}) de una compra que ya estaba cancelada. Devuélvelo o resuélvelo con el cliente.",
        );

        // Aviso al cliente (qué pasó y qué sigue) y al equipo que ve la facturación.
        if ($sesion instanceof SesionTenant) {
            $this->eventos->registrar('pago.tardio', 'pago', (string) $pago->ulid, [
                'persona_id' => $orden->persona?->ulid,
                ...DatosDeSesion::para($sesion),
                'monto' => $monto,
                'orden_id' => (string) $orden->ulid,
            ]);
        }

        return false;
    }
}
