<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CargoRentaNoPagable;
use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El superadmin perdona un cargo de renta pendiente: queda `cancelado` (ya no se
 * cobra, ni a la tarjeta ni en línea, ni cuenta para suspender) y, si el negocio
 * estaba suspendido por renta y ya no debe otra vencida, se reactiva (ADR 0073). Si
 * había un pago en línea en curso, se cancela antes; si ya se pagó, no se condona.
 */
class CondonarCargoRenta
{
    public function __construct(
        private readonly CobrarCargoRenta $cobrar,
        private readonly SuspensionPorRenta $suspension,
    ) {}

    /**
     * @throws CargoRentaNoPagable si el cargo ya no está pendiente o su pago está en curso
     */
    public function ejecutar(CargoRenta $cargo, string $motivo): CargoRenta
    {
        $condonado = DB::transaction(function () use ($cargo): CargoRenta {
            $bloqueado = CargoRenta::query()->whereKey($cargo->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoCargoRenta::Pendiente) {
                throw new CargoRentaNoPagable('Solo se condona un cargo pendiente.');
            }
            // El intento abierto de cualquier pasarela (Stripe, Mercado Pago u OpenPay) se
            // cierra; si ya se cobró o está en curso, no se condona.
            $referencia = (string) $bloqueado->referencia_pago;
            if ($referencia !== '' && ! $this->cobrar->cerrarAnterior($bloqueado, $referencia)) {
                throw new CargoRentaNoPagable('Este cargo tiene un pago en curso; espera su confirmación.');
            }

            $bloqueado->update([
                'estado' => EstadoCargoRenta::Cancelado->value,
                'referencia_pago' => null,
                'proximo_intento_en' => null,
                // OpenPay no deja cerrar su página de pago: el intento se vigila por si
                // se paga después (la conciliación avisa del cobro doble).
                'referencia_condonada' => $referencia !== '' && $bloqueado->metodo_pago === 'openpay' ? $referencia : null,
            ]);

            return $bloqueado;
        });

        Log::info('plataforma.cargo.condonar', [
            'cargo' => $condonado->ulid,
            'estudio' => $condonado->estudio?->slug,
            'monto_minor' => $condonado->monto_minor,
            'moneda' => $condonado->moneda,
            'motivo' => $motivo,
        ]);
        $this->suspension->reactivarSiPago($condonado->estudio);

        return $condonado;
    }
}
