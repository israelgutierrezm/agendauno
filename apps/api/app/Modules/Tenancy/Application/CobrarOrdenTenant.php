<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\PasarelaCancelable;
use App\Modules\Tenancy\Pasarelas\PasarelaDomiciliable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\ResultadoPago;
use Illuminate\Support\Facades\DB;

/**
 * Cobra una orden tenant-local con la pasarela del estudio. El cobro en linea es
 * ASINCRONO: crea un pago `pendiente` con la referencia del intento y devuelve los
 * datos de checkout (client_secret/redirect/voucher); el webhook lo confirmara ->
 * fulfillment. El cobro manual/efectivo se aprueba y cumple en el momento.
 * Idempotente por `idempotency_key`. Serializa con lockForUpdate sobre la orden.
 */
class CobrarOrdenTenant
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly FulfillmentTenant $fulfillment,
    ) {}

    /**
     * @param  Usuario|null  $registradoPor  quién registra el cobro (en caja, o el alumno al pagar en línea)
     */
    public function ejecutar(OrdenTenant $orden, string $proveedor, ?MetodoPago $metodo = null, ?string $idempotencyKey = null, ?string $retorno = null, ?Usuario $registradoPor = null): PagoTenant
    {
        if ($idempotencyKey !== null) {
            $previo = PagoTenant::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($previo instanceof PagoTenant) {
                return $previo;
            }
        }

        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela no esta activa en este estudio.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $proveedor, $metodo, $idempotencyKey, $retorno, $registradoPor): PagoTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoOrden::Pendiente) {
                throw new OrdenNoLiquidable('La orden no admite cobro.');
            }

            // Reintento: el intento anterior aún abierto se anula antes de abrir otro,
            // para que no queden dos formas de pagar lo mismo. Si ya se pagó, se espera
            // su confirmación en vez de cobrar otra vez.
            $this->anularIntentosAbiertos($bloqueada);

            $pago = PagoTenant::query()->create([
                'orden_id' => $bloqueada->getKey(),
                'proveedor' => $proveedor,
                'metodo' => $metodo?->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $bloqueada->total_minor,
                'moneda' => $bloqueada->moneda,
                'idempotency_key' => $idempotencyKey,
                'registrado_por' => $registradoPor?->getKey(),
            ]);

            $pago->retorno = $retorno;
            $resultado = $this->registro->resolver($proveedor)->cobrar($pago, $this->registro->llaves($proveedor));
            $this->aplicar($pago, $bloqueada, $resultado);

            return $pago;
        });
    }

    /**
     * Cargo automático del periodo a la tarjeta domiciliada (sin el cliente presente).
     * La llave de idempotencia es la orden + el número de intento ya registrado: si la
     * pasarela no respondió, todo se deshace y el reintento usa la MISMA llave, así la
     * pasarela no cobra dos veces.
     *
     * @throws CobroNoConcluyente si la pasarela no respondió
     * @throws PasarelaNoDisponible si la pasarela no está activa o no admite cargos automáticos
     * @throws OrdenNoLiquidable si la orden ya no se puede cobrar
     */
    public function domiciliado(OrdenTenant $orden, DomiciliacionTenant $domiciliacion): PagoTenant
    {
        $proveedor = $domiciliacion->proveedor;
        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela no esta activa en este estudio.');
        }
        $pasarela = $this->registro->resolver($proveedor);
        if (! $pasarela instanceof PasarelaDomiciliable) {
            throw new PasarelaNoDisponible('La pasarela no admite pagos automáticos.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $domiciliacion, $proveedor, $pasarela): PagoTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoOrden::Pendiente) {
                throw new OrdenNoLiquidable('La orden no admite cobro.');
            }

            $this->anularIntentosAbiertos($bloqueada);
            $intento = PagoTenant::query()->where('orden_id', $bloqueada->getKey())->count() + 1;

            $pago = PagoTenant::query()->create([
                'orden_id' => $bloqueada->getKey(),
                'proveedor' => $proveedor,
                'metodo' => MetodoPago::Tarjeta->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $bloqueada->total_minor,
                'moneda' => $bloqueada->moneda,
                'domiciliacion_id' => $domiciliacion->getKey(),
            ]);

            $resultado = $pasarela->cobrarDomiciliado(
                $pago,
                $domiciliacion,
                'domiciliacion_'.$bloqueada->ulid.'_'.$intento,
                $this->registro->llaves($proveedor),
            );
            $this->aplicar($pago, $bloqueada, $resultado);

            return $pago;
        });
    }

    /**
     * Asienta el desenlace del intento: aprobado (fulfillment), pendiente (el webhook
     * confirmará) o rechazado.
     */
    private function aplicar(PagoTenant $pago, OrdenTenant $orden, ResultadoPago $resultado): void
    {
        $pago->checkout = $resultado->datos;

        if ($resultado->esAprobado()) {
            $pago->update(['estado' => EstadoPago::Aprobado->value, 'referencia_externa' => $resultado->referencia, 'aprobado_en' => now()]);
            // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
            $this->fulfillment->cumplir($orden);
        } elseif ($resultado->esPendiente()) {
            // Queda pendiente; el webhook confirmara y hara el fulfillment.
            $pago->update(['referencia_externa' => $resultado->referencia]);
        } else {
            $pago->motivo = $resultado->motivo;
            $pago->update(['estado' => EstadoPago::Rechazado->value, 'referencia_externa' => $resultado->referencia]);
        }
    }

    private function anularIntentosAbiertos(OrdenTenant $orden): void
    {
        $abiertos = PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('estado', EstadoPago::Pendiente->value)
            ->lockForUpdate()
            ->get();

        foreach ($abiertos as $previo) {
            try {
                $pasarela = $this->registro->resolver($previo->proveedor);
            } catch (PasarelaNoDisponible) {
                $previo->update(['estado' => EstadoPago::Rechazado->value]);

                continue;
            }
            if (! $pasarela instanceof PasarelaCancelable) {
                continue; // p. ej. ventanilla: el comprobante lo aprueba el staff
            }
            if (! $pasarela->cancelar($previo, $this->registro->llaves($previo->proveedor))) {
                throw new OrdenNoLiquidable('Ya hay un pago en proceso para esta compra; espera su confirmación.');
            }
            $previo->update(['estado' => EstadoPago::Rechazado->value]);
        }
    }
}
