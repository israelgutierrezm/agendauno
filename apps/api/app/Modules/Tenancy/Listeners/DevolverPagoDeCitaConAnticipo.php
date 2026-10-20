<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\AnticipoDeCitaTenant;
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
 * Lo pagado en línea de una cita con anticipo (ADR 0161, 0181) cuando no se atiende:
 *
 * - El cliente cancela a tiempo: se le devuelve todo lo que pagó (el anticipo, o todo
 *   si eligió pagarlo, y el resto si ya lo pagó).
 * - El cliente cancela tarde o no llega: el negocio conserva solo el anticipo; lo demás
 *   que pagó se le devuelve.
 * - Si cancela el negocio, aplica su propia regla ({@see DevolverPagoAlCancelarNegocio}).
 *
 * Solo citas agendadas con anticipo (la regla se congeló al agendar) y solo pagos en
 * línea: lo cobrado en caja se devuelve en caja, a mano. Idempotente: cada devolución
 * lleva la llave de la reserva y del pago (el relay es at-least-once). Si ya se devolvió
 * o la pasarela no puede, queda para el negocio en Pagos.
 *
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class DevolverPagoDeCitaConAnticipo
{
    public function __construct(
        private readonly ReembolsarPagoTenant $reembolsar,
        private readonly AnticipoDeCitaTenant $anticipos,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if ($evento->tipo === 'asistencia.marcada') {
            $this->noLlego($evento);

            return;
        }
        if ($evento->tipo !== 'reserva.cancelada' || $evento->agregadoId === null) {
            return;
        }

        $reserva = ReservaTenant::query()->where('ulid', $evento->agregadoId)->with('orden')->first();
        if (! $reserva instanceof ReservaTenant
            || $reserva->anticipo_minor === null
            || $reserva->cancelada_por !== QuienCancela::Cliente->value
            || $reserva->orden === null
            || $reserva->orden->estado !== EstadoOrden::Pagada) {
            return;
        }

        if ($reserva->motivo_cancelacion === 'tardia') {
            $this->anticipos->devolverLoDeMas($reserva, 'El cliente canceló tarde: el negocio conserva el anticipo y le devuelve lo demás.', 'cancelacion-tardia:'.$reserva->ulid);

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

    /** No llegó: el negocio conserva el anticipo y le devuelve lo demás que pagó en línea. */
    private function noLlego(EventoDeDominioTenant $evento): void
    {
        $payload = $evento->payload;
        $reservaUlid = $payload['reserva_id'] ?? null;
        if (($payload['estado'] ?? null) !== 'ausente' || ! is_string($reservaUlid) || $reservaUlid === '') {
            return;
        }
        $reserva = ReservaTenant::query()->where('ulid', $reservaUlid)->first();
        if (! $reserva instanceof ReservaTenant || $reserva->anticipo_minor === null) {
            return;
        }

        $this->anticipos->devolverLoDeMas($reserva, 'El cliente no llegó: el negocio conserva el anticipo y le devuelve lo demás.', 'inasistencia:'.$reserva->ulid);
    }
}
