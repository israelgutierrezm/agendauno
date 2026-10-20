<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\CondicionesDePlan;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\CondicionesNoAceptadas;
use App\Modules\Tenancy\Ordenes\Exceptions\ExtraSinPaquete;
use App\Modules\Tenancy\Ordenes\Exceptions\MonedaMixta;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Ordenes\Exceptions\PrimeraVezNoDisponible;
use App\Modules\Tenancy\Ordenes\Exceptions\ProductoArchivado;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use Illuminate\Support\Facades\DB;

/**
 * Ordenes tenant-local: crea una orden pendiente con sus lineas (precio congelado)
 * y la liquida manualmente (ventanilla/efectivo/transferencia), haciendo el
 * fulfillment: concede un derecho por cada unidad de cada linea al beneficiario (o
 * comprador). El cobro con pasarela real es un modulo posterior. Comprador !=
 * participante. Todo sobre la BD del tenant resuelto.
 */
class OrdenesTenant
{
    public function __construct(
        private readonly GestionarPromocionesTenant $promociones,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly MembresiasTenant $membresias,
        private readonly CuotasTenant $cuotas,
        private readonly MembresiasPorModalidadTenant $modalidades,
    ) {}

    /**
     * Un producto archivado no se vende; `$renovacion` es la excepción: cobrar la
     * renovación de lo que la persona ya tiene (su acuerdo sigue aunque el producto ya
     * no se ofrezca).
     *
     * `$exencionInscripcion`: vender sin la inscripción que tocaría (B04); quien la
     * exenta (con `cuotas.exentar`, lo revisa quien llama) y por qué quedan en la bitácora.
     *
     * `$aceptacion`: un plan con permanencia (B04) se compra aceptando sus condiciones
     * (`via`: `cliente` en su cuenta, `negocio` si lo registra el equipo); se guardan en la
     * línea con el texto, su versión, cuándo y quién.
     *
     * `precio_minor` en un item: solo en una renovación, el precio que el cliente
     * autorizó en su pago automático (su precio contratado, ADR 0134) en lugar del de
     * hoy.
     *
     * @param  list<array{producto: ProductoTenant, cantidad: int, beneficiario?: PersonaTenant|null, precio_minor?: int}>  $items
     * @param  array{actor: Usuario|null, motivo: string}|null  $exencionInscripcion
     * @param  array{via: string, usuario: Usuario|null, ip: string|null}|null  $aceptacion
     */
    public function crear(PersonaTenant $comprador, array $items, ?string $codigoPromo = null, ?int $sucursalId = null, bool $renovacion = false, ?array $exencionInscripcion = null, ?array $aceptacion = null, ?string $clave = null): OrdenTenant
    {
        // La misma compra repetida (sin respuesta, doble toque) es la misma orden.
        $repetida = $clave !== null ? OrdenTenant::query()->where('persona_id', $comprador->getKey())->where('clave', $clave)->first() : null;
        if ($repetida instanceof OrdenTenant) {
            return $repetida;
        }

        $moneda = $items[0]['producto']->moneda; // Una sola moneda por orden.
        // Clases de prueba por persona en esta orden (B03): una sola, aunque vengan en
        // varias líneas (ADR 0137).
        $pruebas = [];

        foreach ($items as $item) {
            if (! $renovacion && $item['producto']->archivado) {
                throw new ProductoArchivado("«{$item['producto']->nombre}» ya no está a la venta.");
            }
            if (! $renovacion) {
                $this->modalidades->exigirQueSeVenda($item['producto']);
            }
            // Clase de prueba (B03): una sola, para quien nunca vino.
            if (! $renovacion && $item['producto']->primera_vez) {
                $quien = $item['beneficiario'] ?? $comprador;
                $clave = (int) $quien->getKey();
                if (max(1, $item['cantidad']) > 1 || isset($pruebas[$clave]) || ! app(PrimeraVezTenant::class)->elegible($quien)) {
                    throw new PrimeraVezNoDisponible(PrimeraVezTenant::MENSAJE);
                }
                $pruebas[$clave] = true;
            }
            if ($item['producto']->moneda !== $moneda) {
                throw new MonedaMixta('Una orden no puede mezclar monedas.');
            }
            // Permanencia (B04): sin aceptar sus condiciones no se compra.
            if (! $renovacion && $aceptacion === null && CondicionesDePlan::aplica($item['producto'])) {
                throw new CondicionesNoAceptadas("«{$item['producto']->nombre}» tiene permanencia: acepta sus condiciones para comprarlo.");
            }
            // Clases extra: quien las recibe debe tener un paquete vigente al cual sumarlas.
            $recibe = $item['beneficiario'] ?? $comprador;
            if ($item['producto']->tipo === TipoProducto::AddOn && $this->membresias->paqueteParaExtras($recibe) === null) {
                throw new ExtraSinPaquete('Las clases extra se suman a un paquete vigente, y esta persona no tiene uno.');
            }
        }

        return DB::connection('tenant')->transaction(function () use ($comprador, $items, $moneda, $codigoPromo, $sucursalId, $renovacion, $exencionInscripcion, $aceptacion, $clave): OrdenTenant {
            if ($clave !== null) {
                // Dos a la vez con la misma clave: la segunda espera y devuelve la primera.
                PersonaTenant::query()->whereKey($comprador->getKey())->lockForUpdate()->first();
                $repetida = OrdenTenant::query()->where('persona_id', $comprador->getKey())->where('clave', $clave)->first();
                if ($repetida instanceof OrdenTenant) {
                    return $repetida;
                }
            }
            $subtotal = 0;

            $orden = OrdenTenant::query()->create([
                'persona_id' => $comprador->getKey(),
                // Sucursal (R19): sede del vendedor acotado o, si no, la de casa del comprador.
                'sucursal_id' => $sucursalId,
                'estado' => EstadoOrden::Pendiente->value,
                'total_minor' => 0,
                'moneda' => $moneda,
                'clave' => $clave,
            ]);

            foreach ($items as $item) {
                $producto = $item['producto'];
                $cantidad = max(1, $item['cantidad']);
                $precio = $renovacion && isset($item['precio_minor']) ? (int) $item['precio_minor'] : (int) $producto->precio_minor;
                $lineaSubtotal = $precio * $cantidad;
                $subtotal += $lineaSubtotal;

                $orden->lineas()->create([
                    'producto_comercial_id' => $producto->getKey(),
                    'beneficiario_id' => ($item['beneficiario'] ?? null)?->getKey(),
                    'cantidad' => $cantidad,
                    'precio_unitario_minor' => $precio,
                    'subtotal_minor' => $lineaSubtotal,
                    // Las condiciones que aceptó (plan con permanencia; B04).
                    'condiciones' => $aceptacion === null ? null
                        : CondicionesDePlan::aceptadas($producto, $aceptacion['via'], $aceptacion['usuario'], $aceptacion['ip']),
                    // El plan como se vende hoy: si se paga después, se concede esto.
                    'plan' => $renovacion ? null : $producto->condicionesDeVenta(),
                ]);
            }

            // Promocion / cupon (R22): valida, consume un uso y descuenta del total.
            $descuento = 0;
            $promocionId = null;
            if ($codigoPromo !== null && $codigoPromo !== '') {
                ['promocion' => $promocion, 'descuento' => $descuento] = $this->promociones->aplicarEnOrden($codigoPromo, $subtotal);
                $promocionId = $promocion->getKey();
            }

            // Inscripción (B04): al vender un plan que la cobra, si a quien lo recibe le
            // toca (primera alta o reingreso). Se bloquea a la persona para que dos
            // ventas a la vez no la cobren dos veces. No la toca la promoción.
            $cuotas = $renovacion ? 0 : $this->sumarInscripciones($orden, $comprador, $items, $exencionInscripcion);

            $orden->update([
                'total_minor' => $subtotal - $descuento + $cuotas,
                'descuento_minor' => $descuento,
                'promocion_id' => $promocionId,
            ]);

            return $orden;
        });
    }

    /**
     * Suma a la orden la inscripción de cada persona que la deba, si alguno de sus
     * productos la cobra. Devuelve lo sumado.
     *
     * @param  list<array{producto: ProductoTenant, cantidad: int, beneficiario?: PersonaTenant|null}>  $items
     * @param  array{actor: Usuario|null, motivo: string}|null  $exencion
     */
    private function sumarInscripciones(OrdenTenant $orden, PersonaTenant $comprador, array $items, ?array $exencion): int
    {
        $personas = [];
        foreach ($items as $item) {
            if ($item['producto']->cobra_inscripcion) {
                $quien = $item['beneficiario'] ?? $comprador;
                $personas[(int) $quien->getKey()] = $quien;
            }
        }

        $total = 0;
        foreach ($personas as $id => $persona) {
            PersonaTenant::query()->whereKey($id)->lockForUpdate()->first();
            if (! $this->cuotas->debeInscripcion($persona)) {
                continue;
            }
            $linea = $this->cuotas->lineaDeInscripcion($persona);
            if ($exencion !== null) {
                app(RegistrarAuditoria::class)->registrar($exencion['actor'], 'cuota.exentada', 'orden', (string) $orden->ulid, [
                    'concepto' => $linea['concepto'],
                    'monto_minor' => $linea['subtotal_minor'],
                    'persona_id' => $persona->ulid,
                ], null, $exencion['motivo'] !== '' ? $exencion['motivo'] : null);

                continue;
            }
            $orden->lineas()->create($linea);
            $total += $linea['subtotal_minor'];
        }

        return $total;
    }

    /**
     * Crea una orden PENDIENTE por una SESIÓN (pago-para-reservar, citas): sin líneas de
     * producto. Al pagarla, el fulfillment CONFIRMA la reserva ligada (no concede un
     * derecho). La reserva se crea aparte (ver {@see ReservasTenant::reservarConPago()}).
     */
    public function crearPorSesion(PersonaTenant $comprador, SesionTenant $sesion, int $montoMinor, string $moneda, ?int $sucursalId = null): OrdenTenant
    {
        return OrdenTenant::query()->create([
            'persona_id' => $comprador->getKey(),
            'sucursal_id' => $sucursalId,
            'sesion_id' => $sesion->getKey(),
            'estado' => EstadoOrden::Pendiente->value,
            'total_minor' => $montoMinor,
            'moneda' => $moneda,
        ]);
    }

    /**
     * Liquida en caja una orden pendiente (efectivo, transferencia, ventanilla…):
     * registra su PAGO (con quién lo cobró, para el corte de caja) y hace el
     * fulfillment. Si había un pago en línea abierto, se cierra primero (si ya se
     * pagó, no se cobra dos veces). Idempotente: una orden ya pagada no se vuelve a
     * cobrar (devuelve null). La orden cancelada no admite liquidación.
     */
    /**
     * @param  list<array{metodo: string, monto_minor: int}>|null  $partes  con varias formas (ADR 0177): suman el total
     */
    public function liquidar(OrdenTenant $orden, string $metodo, ?string $referencia = null, ?Usuario $actor = null, ?int $sucursalCaja = null, ?array $partes = null, ?string $idempotencyKey = null): ?PagoTenant
    {
        $orden->refresh();
        if ($orden->estado === EstadoOrden::Pagada) {
            return null;
        }
        if ($orden->estado === EstadoOrden::Cancelada) {
            throw new OrdenNoLiquidable('La orden no admite liquidacion.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $metodo, $referencia, $actor, $sucursalCaja, $partes, $idempotencyKey): PagoTenant {
            $pago = $this->cobrar->ejecutar($orden, 'manual', $partes !== null ? MetodoPago::Mixto : self::metodoDeCaja($metodo), $idempotencyKey, null, $actor, $sucursalCaja, $partes);
            $orden->update(['metodo_pago' => $partes !== null ? PartesCobro::MIXTO : $metodo, 'referencia_pago' => $referencia]);

            return $pago;
        });
    }

    private static function metodoDeCaja(string $metodo): ?MetodoPago
    {
        // La misma de la corrección del cobro: no se separan nunca.
        return CorregirMetodoPagoTenant::metodoDeCaja($metodo);
    }
}
