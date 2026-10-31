<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use App\Modules\Tenancy\Pasarelas\PasarelaCancelable;
use App\Modules\Tenancy\Pasarelas\PasarelaDomiciliable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\ResultadoPago;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cobra una orden tenant-local con la pasarela del estudio. El cobro en linea es
 * ASINCRONO: crea un pago `pendiente` con la referencia del intento y devuelve los
 * datos de checkout (client_secret/redirect/voucher); el webhook lo confirmara ->
 * fulfillment. El cobro manual/efectivo se aprueba y cumple en el momento.
 * Idempotente por `idempotency_key`. Serializa con lockForUpdate sobre la orden.
 */
class CobrarOrdenTenant
{
    /** Hasta cuándo la misma llave repite el intento abierto en vez de abrir otro. */
    private const MINUTOS_MISMO_INTENTO = 15;

    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly FulfillmentTenant $fulfillment,
        private readonly CajasTenant $cajas,
    ) {}

    /**
     * @param  Usuario|null  $registradoPor  quién registra el cobro (en caja, o el alumno al pagar en línea)
     * @param  int|null  $sucursalCaja  la sede donde se cobra en el mostrador (su caja; ADR 0175); si no, la de la orden
     * @param  list<array{metodo: string, monto_minor: int}>|null  $partes  cobro mixto en caja (ADR 0177): suman el total
     */
    public function ejecutar(OrdenTenant $orden, string $proveedor, ?MetodoPago $metodo = null, ?string $idempotencyKey = null, ?string $retorno = null, ?Usuario $registradoPor = null, ?int $sucursalCaja = null, ?array $partes = null): PagoTenant
    {
        $repetido = function () use ($orden, $proveedor, $idempotencyKey): ?PagoTenant {
            $previo = $idempotencyKey !== null ? PagoTenant::query()->where('idempotency_key', $idempotencyKey)->first() : null;
            // La misma llave es el mismo cobro: de esta orden y por la misma pasarela.
            if ($previo instanceof PagoTenant && ((int) $previo->orden_id !== (int) $orden->getKey() || $previo->proveedor !== $proveedor)) {
                throw new OrdenNoLiquidable('Esa llave ya se usó para otro cobro: vuelve a intentarlo.');
            }

            return $previo;
        };
        $previo = $repetido();
        if ($previo instanceof PagoTenant && $this->sigueSiendoElMismoCobro($previo)) {
            return $this->conSuCheckout($previo);
        }

        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela no está activa en este negocio.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $proveedor, $metodo, $idempotencyKey, $retorno, $registradoPor, $sucursalCaja, $partes, $repetido): PagoTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();
            // Ya bloqueada, otra vez: dos peticiones a la vez con la misma llave.
            $previo = $repetido();
            if ($previo instanceof PagoTenant) {
                if ($this->sigueSiendoElMismoCobro($previo)) {
                    return $this->conSuCheckout($previo);
                }
                // Ese intento ya terminó o se abandonó: la llave queda libre para el nuevo.
                $previo->update(['idempotency_key' => null]);
            }

            if ($bloqueada->estado !== EstadoOrden::Pendiente) {
                throw new OrdenNoLiquidable('La orden no admite cobro.');
            }
            // Una clase de prueba se revalida antes de cobrar: otra orden pudo pagarse antes.
            // La renovación de lo que ya tiene no es una compra nueva.
            if ($bloqueada->renueva_acuerdo_id === null) {
                app(PrimeraVezTenant::class)->exigirEnOrden($bloqueada);
            }
            // En $0 no hay nada que cobrar: ni pasarela ni caja (ADR 0231).
            if ((int) $bloqueada->total_minor === 0 && $partes === null) {
                return $this->cumplirSinCosto($bloqueada, $proveedor, $metodo, $idempotencyKey, $registradoPor);
            }

            // Reintento: el intento anterior aún abierto se anula antes de abrir otro,
            // para que no queden dos formas de pagar lo mismo. Si ya se pagó, se espera
            // su confirmación en vez de cobrar otra vez.
            $this->anularIntentosAbiertos($bloqueada, in_array($proveedor, ['manual', 'efectivo'], true));

            // Con varias formas, solo en caja y sumando el total (ADR 0177).
            if ($partes !== null) {
                if ($proveedor !== 'manual') {
                    throw new OrdenNoLiquidable('El cobro con varias formas de pago es solo en caja.');
                }
                PartesCobro::exigirTotal($partes, (int) $bloqueada->total_minor);
            }
            $pago = PagoTenant::query()->create([
                'orden_id' => $bloqueada->getKey(),
                'proveedor' => $proveedor,
                'metodo' => $partes !== null ? MetodoPago::Mixto->value : $metodo?->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $bloqueada->total_minor,
                'moneda' => $bloqueada->moneda,
                'idempotency_key' => $idempotencyKey,
                'registrado_por' => $registradoPor?->getKey(),
            ]);
            if ($partes !== null) {
                PartesCobro::guardar($pago->partes(), $partes);
            }

            $pago->retorno = $retorno;
            $resultado = $this->registro->resolver($proveedor)->cobrar($pago, $this->registro->llaves($proveedor));
            $this->aplicar($pago, $bloqueada, $resultado, $sucursalCaja, $registradoPor);

            return $pago;
        });
    }

    /** Una orden que quedó en $0 (un cupón del 100 %): se cumple como cortesía. */
    public function sinCosto(OrdenTenant $orden): PagoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($orden): PagoTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            return $this->cumplirSinCosto($bloqueada, 'manual');
        });
    }

    private function cumplirSinCosto(OrdenTenant $orden, string $proveedor, ?MetodoPago $metodo = null, ?string $idempotencyKey = null, ?Usuario $registradoPor = null): PagoTenant
    {
        $this->anularIntentosAbiertos($orden, true);
        $pago = PagoTenant::query()->create([
            'orden_id' => $orden->getKey(),
            'proveedor' => $proveedor,
            'metodo' => $metodo?->value,
            'estado' => EstadoPago::Aprobado->value,
            'monto_minor' => 0,
            'moneda' => $orden->moneda,
            'idempotency_key' => $idempotencyKey,
            'registrado_por' => $registradoPor?->getKey(),
            'aprobado_en' => now(),
        ]);
        $this->fulfillment->cumplir($orden);

        return $pago;
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
            throw new PasarelaNoDisponible('La pasarela no está activa en este negocio.');
        }
        $pasarela = $this->registro->resolver($proveedor);
        if (! $pasarela instanceof PasarelaDomiciliable) {
            throw new PasarelaNoDisponible('La pasarela no admite pagos automáticos.');
        }

        // Ya hay un cargo automático cobrado de esta orden (p. ej. con otro importe, que
        // espera revisión): no se le cobra otra vez, aunque la pasarela no conteste.
        $cobrado = $orden->estado !== EstadoOrden::Pendiente ? null : PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->whereNotNull('domiciliacion_id')
            ->where('estado', EstadoPago::Aprobado->value)
            ->latest('id')
            ->first();
        if ($cobrado instanceof PagoTenant) {
            return $cobrado;
        }

        // Un cargo anterior de esta orden que sí se hizo pero no quedó registrado (la
        // pasarela no respondió y su aviso no llegó; su llave pudo ya caducar): se registra
        // en lugar de cobrar otra vez. Si la pasarela no responde, se sigue.
        try {
            $previo = $pasarela->cargoDeOrden($orden, $this->registro->llaves($proveedor));
        } catch (Throwable $e) {
            ReporteDeErrores::reportarAtrapado($e);
            $previo = null;
        }
        if (($previo['status'] ?? '') === 'succeeded') {
            app(ConfirmarPagoTenant::class)->porCargoDomiciliado($previo['id'], (string) $orden->ulid, (string) $domiciliacion->ulid, $previo['monto_minor'], $previo['moneda']);
            $registrado = PagoTenant::query()->where('referencia_externa', $previo['id'])->first();
            if ($registrado instanceof PagoTenant) {
                return $registrado;
            }
        }
        if (in_array($previo['status'] ?? '', ['processing', 'requires_capture'], true)) {
            throw new CobroNoConcluyente('El cargo anterior de esta renovación sigue en proceso.');
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
    private function aplicar(PagoTenant $pago, OrdenTenant $orden, ResultadoPago $resultado, ?int $sucursalCaja = null, ?Usuario $actor = null): void
    {
        $pago->checkout = $resultado->datos;

        if ($resultado->esAprobado()) {
            // Dinero de mostrador (ADR 0175): a la caja abierta de la sede, bloqueada antes
            // del inventario que mueve el fulfillment.
            $turno = in_array($pago->proveedor, ['manual', 'efectivo'], true)
                ? $this->cajas->turnoParaMovimiento($sucursalCaja ?? $orden->sucursal_id)
                : null;
            $pago->update(['estado' => EstadoPago::Aprobado->value, 'referencia_externa' => $resultado->referencia, 'aprobado_en' => now()]);
            // Una forma o, si fue mixto, un renglón por forma (ADR 0177).
            $this->cajas->asentarPartes($turno, MovimientoCajaTenant::COBRO, CajasTenant::partesDelPago($pago), 1, ['pago_id' => $pago->getKey()], $actor);
            // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
            $this->fulfillment->cumplir($orden);
        } elseif ($resultado->esPendiente()) {
            // Queda pendiente; el webhook confirmara y hara el fulfillment. Sus datos se
            // guardan para responder igual a la misma llave (doble clic, red).
            $pago->update(['referencia_externa' => $resultado->referencia, 'checkout_guardado' => $resultado->datos !== [] ? $resultado->datos : null]);
        } else {
            $pago->motivo = $resultado->motivo;
            $pago->update(['estado' => EstadoPago::Rechazado->value, 'referencia_externa' => $resultado->referencia]);
        }
    }

    /**
     * ¿La misma llave sigue siendo este cobro? Un rechazo o una anulación ya terminaron,
     * y un intento abierto de hace rato se abandonó (su página pudo vencer): se cobra de
     * nuevo. Uno cobrado o recién abierto es el mismo (doble clic, reintento de red).
     */
    private function sigueSiendoElMismoCobro(PagoTenant $previo): bool
    {
        if (in_array($previo->estado, [EstadoPago::Rechazado, EstadoPago::Anulado], true)) {
            return false;
        }

        return $previo->estado !== EstadoPago::Pendiente
            || ($previo->created_at !== null && $previo->created_at->gt(now()->subMinutes(self::MINUTOS_MISMO_INTENTO)));
    }

    private function conSuCheckout(PagoTenant $previo): PagoTenant
    {
        $previo->checkout = $previo->estado === EstadoPago::Pendiente ? (array) $previo->checkout_guardado : [];

        return $previo;
    }

    /**
     * Anula los intentos abiertos antes de abrir otro. Con `$enCaja`, una pasarela que no
     * responde no impide cobrar en el mostrador: el intento queda cerrado sin confirmar y
     * la conciliación revisa si se pagó (un doble cobro raro queda como incidencia). Un
     * nuevo cobro en línea sí espera a poder cancelarlo.
     */
    private function anularIntentosAbiertos(OrdenTenant $orden, bool $enCaja = false): void
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
                $previo->update(['estado' => EstadoPago::Rechazado->value, 'cerrado_sin_confirmar' => true]);

                continue;
            }
            if (! $pasarela instanceof PasarelaCancelable) {
                continue; // p. ej. ventanilla: el comprobante lo aprueba el staff
            }
            try {
                $cancelado = $pasarela->cancelar($previo, $this->registro->llaves($previo->proveedor));
            } catch (Throwable $e) {
                if (! $enCaja) {
                    throw $e;
                }
                ReporteDeErrores::reportarAtrapado($e);
                $cancelado = true;
            }
            if (! $cancelado) {
                throw new OrdenNoLiquidable('Ya hay un pago en proceso para esta compra; espera su confirmación.');
            }
            // Cerrado de este lado: la conciliación confirma con la pasarela que ya no
            // se puede pagar (OpenPay, por ejemplo, no cancela un pago en tienda).
            $previo->update(['estado' => EstadoPago::Rechazado->value, 'cerrado_sin_confirmar' => true]);
        }
    }
}
