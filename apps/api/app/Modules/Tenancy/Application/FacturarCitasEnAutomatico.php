<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\TratamientoIva;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesPersonaTenant;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Throwable;

/**
 * Las facturas de las citas sin capturarlas a mano: a los clientes que guardaron sus
 * datos fiscales y pidieron factura automática, en negocios de citas que la
 * encendieron (`facturacion.automatica_citas`) y facturan con la plataforma.
 *
 * - **Una cita pagada completa**: su factura, con lo que trae el borrador de la orden.
 * - **Con anticipo (ADR 0161)**: al pagar el anticipo, su CFDI de anticipo (ADR 0163);
 *   al pagar el resto, la factura de la operación completa relacionada con el anticipo
 *   (07) y el CFDI de egreso que lo aplica (forma de pago 30). Si el anticipo no se
 *   facturó, el resto se factura como cualquier venta.
 * - Cada venta se factura una vez ({@see FacturasDeVentasTenant}); lo que falló (un
 *   timbrado, un egreso) se reintenta con {@see pendientes()}.
 */
class FacturarCitasEnAutomatico
{
    /** Intentos con error por orden antes de dejarla para el negocio. */
    private const MAX_ERRORES = 3;

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly FacturasDeVentasTenant $ventas,
        private readonly EmitirFacturaTenant $emisor,
        private readonly RegionNegocioTenant $region,
        private readonly FuncionesPlan $plan,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /** ¿El negocio factura en automático? */
    public function activa(): bool
    {
        return $this->parametros->siNo('facturacion.automatica_citas')
            && $this->gestor->actual()?->modalidad() === ModalidadServicio::Citas
            && ConfiguracionPlataforma::facturacionDisponible()
            && $this->region->factura()
            && $this->plan->tieneElNegocioActual('facturacion')
            && DatosFiscalesTenant::query()->exists();
    }

    /** Factura una orden pagada de una cita si su cliente lo pidió; null si no toca. */
    public function alPagarse(OrdenTenant $orden): ?FacturaTenant
    {
        if (! $this->activa() || $orden->estado !== EstadoOrden::Pagada) {
            return null;
        }
        $reserva = ReservaTenant::query()
            ->where(fn ($q) => $q->where('orden_id', $orden->getKey())->orWhere('saldo_orden_id', $orden->getKey()))
            ->first();
        $fiscales = DatosFiscalesPersonaTenant::query()->where('persona_id', $orden->persona_id)->where('factura_automatica', true)->first();
        if (! $reserva instanceof ReservaTenant || ! $fiscales instanceof DatosFiscalesPersonaTenant || $this->yaFacturada($orden)) {
            return null;
        }
        $emisor = DatosFiscalesTenant::query()->firstOrFail();
        $borrador = $this->ventas->borradorDeOrden($orden);
        $receptor = $fiscales->receptor();
        // El IVA y la clave del SAT del servicio de la cita (ADR 0212).
        $servicio = $reserva->sesion?->oferta;
        $iva = TratamientoIva::de($servicio?->iva);
        $clave = (string) ($servicio->clave_sat ?? '');
        $clave = $clave !== '' ? $clave : '86121600';

        // El anticipo de una cita con anticipo: su CFDI de anticipo.
        if ((int) $reserva->orden_id === (int) $orden->getKey() && $reserva->saldo_orden_id !== null) {
            return $this->emisor->emitir(
                $emisor, $receptor,
                [['descripcion' => 'Anticipo', 'cantidad' => 1, 'precio_unitario_minor' => $this->sinIva($borrador['total_minor'], $iva), 'clave_prod_serv' => '84111506', 'clave_unidad' => 'ACT', 'iva' => $iva->value]],
                $fiscales->uso_cfdi, $borrador['forma_pago'], $borrador['moneda'],
                anticipo: true, ordenId: (int) $orden->getKey(),
            );
        }

        // El resto, con su anticipo facturado: la operación completa y el egreso.
        $anticipo = (int) $reserva->saldo_orden_id === (int) $orden->getKey() ? $this->anticipoSinAplicar($reserva) : null;
        if ($anticipo instanceof FacturaTenant) {
            $descripcion = $reserva->sesion?->oferta->nombre ?? $borrador['etiqueta'];
            $total = (int) $anticipo->total_minor + (int) $borrador['total_minor'];
            $operacion = $this->emisor->emitir(
                $emisor, $receptor,
                [['descripcion' => (string) $descripcion, 'cantidad' => 1, 'precio_unitario_minor' => $this->sinIva($total, $iva), 'clave_prod_serv' => $clave, 'clave_unidad' => 'E48', 'iva' => $iva->value]],
                $fiscales->uso_cfdi, $borrador['forma_pago'], $borrador['moneda'],
                anticipos: [(string) $anticipo->ulid], ordenId: (int) $orden->getKey(), conEgreso: true,
            );
            if ($operacion->estado === EstadoFactura::Timbrada) {
                $this->emisor->aplicarAnticipo($emisor, $operacion, $anticipo);
            }

            return $operacion;
        }

        // Una cita pagada completa (o el resto sin anticipo facturado).
        return $this->emisor->emitir(
            $emisor, $receptor, $borrador['conceptos'], $fiscales->uso_cfdi, $borrador['forma_pago'], $borrador['moneda'],
            ordenId: (int) $orden->getKey(),
        );
    }

    /**
     * Lo que quedó pendiente: anticipos sin aplicar cuyo resto ya se facturó (su egreso
     * falló) y citas pagadas en los últimos 7 días sin factura (su timbrado falló).
     */
    public function pendientes(): int
    {
        if (! $this->activa()) {
            return 0;
        }
        $hechos = 0;
        $emisor = DatosFiscalesTenant::query()->firstOrFail();
        FacturaTenant::query()
            ->where('anticipo', true)->where('estado', EstadoFactura::Timbrada->value)->whereNull('anticipo_aplicado_en')->whereNotNull('orden_id')
            ->get()
            ->each(function (FacturaTenant $anticipo) use ($emisor, &$hechos): void {
                $reserva = ReservaTenant::query()->where('orden_id', $anticipo->orden_id)->whereNotNull('saldo_orden_id')->first();
                $operacion = $reserva instanceof ReservaTenant ? FacturasDeVentasTenant::deOrden((int) $reserva->saldo_orden_id) : null;
                if (! $operacion instanceof FacturaTenant) {
                    return;
                }
                try {
                    $hechos += $this->emisor->aplicarAnticipo($emisor, $operacion, $anticipo)->estado === EstadoFactura::Timbrada ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $conAutomatica = DatosFiscalesPersonaTenant::query()->where('factura_automatica', true)->pluck('persona_id');
        OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereIn('persona_id', $conAutomatica)
            ->where('pagada_en', '>=', now()->subDays(7))
            ->get()
            ->each(function (OrdenTenant $orden) use (&$hechos): void {
                if (FacturaTenant::query()->where('orden_id', $orden->getKey())->where('estado', EstadoFactura::Error->value)->count() >= self::MAX_ERRORES) {
                    return;
                }
                try {
                    $hechos += $this->alPagarse($orden)?->estado === EstadoFactura::Timbrada ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $hechos;
    }

    /** Ya tiene su factura (o su CFDI de anticipo) timbrada. */
    private function yaFacturada(OrdenTenant $orden): bool
    {
        return FacturaTenant::query()->where('orden_id', $orden->getKey())->where('tipo', FacturaTenant::INGRESO)
            ->where('estado', EstadoFactura::Timbrada->value)->exists();
    }

    /** El CFDI del anticipo de la cita, timbrado y aún sin aplicar. */
    private function anticipoSinAplicar(ReservaTenant $reserva): ?FacturaTenant
    {
        return FacturaTenant::query()->where('orden_id', $reserva->orden_id)->where('anticipo', true)
            ->where('estado', EstadoFactura::Timbrada->value)->whereNull('anticipo_aplicado_en')->latest('id')->first();
    }

    /** El importe sin IVA de un precio con IVA (el CFDI se lo suma). */
    private function sinIva(int $conIva, TratamientoIva $tratamiento): int
    {
        $iva = intdiv($tratamiento->bps($this->parametros->entero('facturacion.iva_porcentaje') * 100), 100);

        return max(1, (int) round($conIva * 100 / (100 + $iva)));
    }
}
