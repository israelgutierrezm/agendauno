<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

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
 * Anticipo de una cita (ADR 0161): si el cliente cancela a tiempo, lo que pagó en línea
 * (el anticipo, o todo si eligió pagarlo, y el saldo si ya lo pagó) se le devuelve solo
 * por la misma pasarela. Si cancela tarde, el negocio lo conserva (la reserva queda con
 * motivo `tardia`); si cancela el negocio, aplica su propia regla
 * ({@see DevolverPagoAlCancelarNegocio}).
 *
 * - Solo citas agendadas con anticipo (`reservas.anticipo_minor`): la regla se congeló
 *   al agendar.
 * - Solo pagos en línea: lo cobrado en caja se devuelve en caja, a mano.
 * - Idempotente: cada devolución lleva la llave de la reserva y del pago (el relay es
 *   at-least-once). Si ya se devolvió o la pasarela no puede, queda para el negocio en
 *   Pagos.
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class DevolverAnticipoAlCancelarATiempo
{
    public function __construct(private readonly ReembolsarPagoTenant $reembolsar) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if ($evento->tipo !== 'reserva.cancelada' || $evento->agregadoId === null) {
            return;
        }

        $reserva = ReservaTenant::query()->where('ulid', $evento->agregadoId)->with('orden')->first();
        if (! $reserva instanceof ReservaTenant
            || $reserva->anticipo_minor === null
            || $reserva->cancelada_por !== QuienCancela::Cliente->value
            || $reserva->motivo_cancelacion === 'tardia'
            || $reserva->orden === null
            || $reserva->orden->estado !== EstadoOrden::Pagada) {
            return;
        }

        $pagos = PagoTenant::query()
            ->whereIn('orden_id', array_values(array_filter([$reserva->orden_id, $reserva->saldo_orden_id])))
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value])
            ->whereIn('proveedor', ProveedorPasarela::enLinea())
            ->get();
        foreach ($pagos as $pago) {
            try {
                $this->reembolsar->ejecutar(
                    $pago,
                    null,
                    'El cliente canceló a tiempo: se devuelve su anticipo.',
                    llave: 'cancelacion-a-tiempo:'.$reserva->ulid.':'.$pago->ulid,
                );
            } catch (PagoNoReembolsable) {
                // Ya devuelto, o la pasarela no devuelve en línea: lo resuelve el negocio.
            }
        }
    }
}
