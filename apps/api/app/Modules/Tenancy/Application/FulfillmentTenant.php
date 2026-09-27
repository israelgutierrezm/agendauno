<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;

/**
 * Fulfillment de una orden tenant-local: la marca pagada y concede un derecho por
 * cada unidad de cada linea al beneficiario (o comprador). Idempotente: una orden ya
 * pagada no vuelve a conceder. Compartido por la liquidacion manual (ventanilla) y
 * por la confirmacion de un pago con pasarela (webhook). Aquí se asienta
 * `orden.pagada` (recibo, puntos de lealtad), sea cual sea el camino del pago.
 */
class FulfillmentTenant
{
    public function __construct(
        private readonly MembresiasTenant $membresias,
        private readonly RegistrarEventoTenant $eventos,
        private readonly EmitirReservaConfirmadaTenant $confirmada,
        private readonly RenovacionPagadaTenant $renovacion,
    ) {}

    public function cumplir(OrdenTenant $orden): void
    {
        if ($orden->estado === EstadoOrden::Pagada) {
            return;
        }

        $orden->update(['estado' => EstadoOrden::Pagada->value, 'pagada_en' => now()]);
        $this->emitirPagada($orden);

        // Orden de RESERVA (pago-para-reservar, citas): confirma la reserva pendiente
        // ligada (que ya retiene el cupo) en vez de conceder un producto. Si la reserva
        // ya no está pendiente (expiró/canceló), no confirma nada (guard por estado).
        if ($orden->sesion_id !== null) {
            ReservaTenant::query()
                ->where('orden_id', $orden->getKey())
                ->where('estado', EstadoReserva::PendientePago->value)
                ->get()
                ->each(function (ReservaTenant $reserva): void {
                    $reserva->update(['estado' => EstadoReserva::Confirmada->value]);
                    $this->confirmada->emitir($reserva);
                });

            return;
        }

        // Orden de RENOVACIÓN (la deuda del periodo): no crea un acuerdo nuevo (el
        // entitlement lo mantiene el motor de ciclos); pagada, la fecha de renovación
        // pasa al siguiente periodo y se cierra la mora.
        if ($orden->renueva_acuerdo_id !== null) {
            $acuerdo = AcuerdoTenant::query()->whereKey($orden->renueva_acuerdo_id)->lockForUpdate()->first();
            if ($acuerdo instanceof AcuerdoTenant) {
                $this->renovacion->registrar($acuerdo);
            }

            return;
        }

        $orden->loadMissing(['lineas.producto', 'lineas.beneficiario', 'persona']);

        foreach ($orden->lineas as $linea) {
            $beneficiario = $linea->beneficiario ?? $orden->persona;
            $producto = $linea->producto;

            if (! $beneficiario instanceof PersonaTenant || ! $producto instanceof ProductoTenant) {
                continue;
            }

            for ($i = 0; $i < $linea->cantidad; $i++) {
                // Ya pagado: unas clases extra sin paquete vigente valen como paquete aparte.
                $acuerdo = $this->membresias->venderProducto($beneficiario, $producto, extraAunSinPaquete: true);
                $acuerdo->update(['linea_orden_id' => $linea->getKey()]);
            }
        }
    }

    /**
     * Evento de dominio (outbox) de la orden pagada, con los datos del recibo.
     */
    private function emitirPagada(OrdenTenant $orden): void
    {
        $orden->loadMissing('persona');

        $this->eventos->registrar('orden.pagada', 'orden', (string) $orden->ulid, [
            'persona_id' => $orden->persona?->ulid,
            'total_minor' => $orden->total_minor,
            'orden_id' => (string) $orden->ulid,
            ...DatosDeOrden::para($orden),
        ]);
    }
}
