<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pasarelas\PasarelaCancelable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cierra en la pasarela los intentos de pago que ya no deben poder pagarse (p. ej.
 * venció el apartado de una cita, o se confirmó otro intento de la misma compra), para
 * que no llegue un pago tarde. Si la pasarela dice que ese intento YA se cobró, se
 * deja pendiente: su confirmación llegará y se atenderá como pago tardío o cobro doble.
 * Lo cerrado queda marcado para que la conciliación le pregunte a la pasarela si de
 * verdad ya no se puede pagar ({@see ConciliarPagosTenant}): OpenPay no cancela, y la
 * pasarela pudo no responder.
 */
class CerrarIntentosPagoTenant
{
    public function __construct(private readonly RegistroDePasarelasTenant $registro) {}

    /**
     * Cierra los intentos pendientes de una orden.
     */
    public function deOrden(int $ordenId): void
    {
        PagoTenant::query()
            ->where('orden_id', $ordenId)
            ->where('estado', EstadoPago::Pendiente->value)
            ->get()
            ->each(fn (PagoTenant $pago) => $this->cerrar($pago));
    }

    public function cerrar(PagoTenant $pago): void
    {
        try {
            $pasarela = $this->registro->resolver($pago->proveedor);
            if ($pasarela instanceof PasarelaCancelable
                && ! $pasarela->cancelar($pago, $this->registro->llaves($pago->proveedor))) {
                Log::warning('El intento que se quería cerrar ya se cobró: su confirmación se atenderá al llegar.', ['pago' => $pago->ulid]);

                return;
            }
        } catch (Throwable $e) {
            // La pasarela no respondió: si llega a cobrarse, se atiende como pago tardío.
            report($e);
        }

        PagoTenant::query()->whereKey($pago->getKey())
            ->where('estado', EstadoPago::Pendiente->value)
            ->update(['estado' => EstadoPago::Rechazado->value, 'cerrado_sin_confirmar' => true]);
    }
}
