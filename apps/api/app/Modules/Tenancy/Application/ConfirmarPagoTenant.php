<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pasarelas\PasarelaCancelable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirma un pago pendiente tenant-local a partir de la referencia del intento (la
 * que envia el webhook de la pasarela) y hace el fulfillment de su orden.
 * Idempotente: un pago que no esta pendiente no se reprocesa.
 */
class ConfirmarPagoTenant
{
    public function __construct(
        private readonly FulfillmentTenant $fulfillment,
        private readonly RegistroDePasarelasTenant $registro,
    ) {}

    /**
     * La pasarela confirma que ESTE pago se cobró (con la referencia de su cobro).
     * Si el intento ya se había cerrado (el cliente reintentó) pero la compra sigue
     * pendiente, se confirma igual y se cierran los demás intentos abiertos, para
     * que no se pague dos veces. Si la compra ya estaba pagada, se reporta el cobro
     * doble para devolverlo. Idempotente.
     */
    public function aprobar(PagoTenant $pago, string $referencia): void
    {
        $abiertos = DB::connection('tenant')->transaction(function () use ($pago, $referencia): array {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->first();
            if (! $bloqueado instanceof PagoTenant || $bloqueado->estado === EstadoPago::Aprobado) {
                return [];
            }
            $orden = OrdenTenant::query()->whereKey($bloqueado->orden_id)->lockForUpdate()->first();
            if ($bloqueado->estado !== EstadoPago::Pendiente
                && (! $orden instanceof OrdenTenant || $orden->estado !== EstadoOrden::Pendiente)) {
                Log::warning('Se cobró un intento ya cerrado de una compra resuelta: hay que devolverlo.', [
                    'pago' => $bloqueado->ulid,
                    'referencia' => $referencia,
                ]);

                return [];
            }

            $bloqueado->update([
                'estado' => EstadoPago::Aprobado->value,
                'referencia_externa' => $referencia !== '' ? $referencia : $bloqueado->referencia_externa,
            ]);
            if ($orden instanceof OrdenTenant) {
                // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
                $this->fulfillment->cumplir($orden);
            }

            return PagoTenant::query()
                ->where('orden_id', $bloqueado->orden_id)
                ->whereKeyNot($bloqueado->getKey())
                ->where('estado', EstadoPago::Pendiente->value)
                ->get()
                ->all();
        });

        foreach ($abiertos as $abierto) {
            $this->cerrarIntento($abierto);
        }
    }

    public function porReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($referencia): void {
            $pago = PagoTenant::query()
                ->where('referencia_externa', $referencia)
                ->lockForUpdate()
                ->first();

            if (! $pago instanceof PagoTenant || $pago->estado !== EstadoPago::Pendiente) {
                return;
            }

            $pago->update(['estado' => EstadoPago::Aprobado->value]);

            $orden = $pago->orden;
            if ($orden !== null) {
                // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
                $this->fulfillment->cumplir($orden);
            }
        });
    }

    /**
     * El intento ya no se puede pagar (la sesión venció o el pago en tienda no se
     * completó): queda rechazado. La orden sigue pendiente y se puede reintentar.
     * Idempotente.
     */
    public function rechazarPorReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        PagoTenant::query()
            ->where('referencia_externa', $referencia)
            ->where('estado', EstadoPago::Pendiente->value)
            ->update(['estado' => EstadoPago::Rechazado->value]);
    }

    /**
     * Cierra un intento que quedó abierto al confirmarse otro de la misma compra. Si
     * la pasarela dice que también se cobró, se reporta para devolverlo.
     */
    private function cerrarIntento(PagoTenant $pago): void
    {
        try {
            $pasarela = $this->registro->resolver($pago->proveedor);
            if ($pasarela instanceof PasarelaCancelable
                && ! $pasarela->cancelar($pago, $this->registro->llaves($pago->proveedor))) {
                Log::warning('Otro intento de la misma compra también se cobró: hay que devolverlo.', ['pago' => $pago->ulid]);

                return;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $pago->update(['estado' => EstadoPago::Rechazado->value]);
    }
}
