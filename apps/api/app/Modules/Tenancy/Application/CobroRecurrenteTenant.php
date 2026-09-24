<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Cobro recurrente (Etapa 2) tenant-local: al llegar `proxima_cobro_en` de una
 * membresía recurrente se intenta cobrar el periodo con la pasarela del estudio.
 *
 * Se separan tres cosas:
 * - **la deuda del periodo**: una orden de renovación pendiente (se reutiliza en
 *   cada reintento; no se abre una por intento);
 * - **el intento de pago**: cada cobro (aprobado, pendiente o rechazado);
 * - **la fecha de renovación**: solo avanza cuando la deuda se PAGA
 *   ({@see RenovacionPagadaTenant}, desde el fulfillment: cobro al momento, webhook
 *   o pago en caja).
 *
 * Si el intento queda pendiente (el cliente debe completar el pago en la página de
 * la pasarela) o se rechaza, se abre/avanza la mora: el cliente recibe el aviso con
 * el enlace para pagar desde su cuenta, se reintenta con backoff y, si la gracia
 * vence sin pago, se suspende; al pagar se regulariza. La orden de renovación NO
 * concede un acuerdo nuevo (el entitlement lo mantiene el motor de ciclos).
 *
 * Con pago automático (domiciliación) el periodo se cobra a la tarjeta autorizada,
 * sin el cliente presente; si el banco lo rechaza, entra la mora con el motivo y el
 * enlace para pagar a mano, y los reintentos vuelven a intentar la tarjeta.
 *
 * @phpstan-type Resumen array{cobrados: int, pendientes: int, fallidos: int}
 */
class CobroRecurrenteTenant
{
    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly GestionarDunningTenant $dunning,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    /**
     * Intenta el cobro de renovación de un acuerdo con la pasarela dada.
     * Devuelve 'cobrado' | 'pendiente' | 'fallido' | 'omitido'.
     */
    public function renovar(AcuerdoTenant $acuerdo, string $proveedor): string
    {
        $acuerdo->loadMissing(['persona', 'producto']);
        $persona = $acuerdo->persona;
        $producto = $acuerdo->producto;

        if (! $persona instanceof PersonaTenant || ! $producto instanceof ProductoTenant
            || in_array($acuerdo->estado, [EstadoAcuerdo::Cancelado, EstadoAcuerdo::Pausado], true)) {
            return 'omitido';
        }

        $orden = $this->deudaDelPeriodo($acuerdo, $persona, $producto);

        $domiciliacion = $acuerdo->domiciliacion()->first();
        if ($domiciliacion instanceof DomiciliacionTenant) {
            return $this->cargoAutomatico($acuerdo, $orden, $domiciliacion);
        }

        try {
            $pago = $this->cobrar->ejecutar($orden, $proveedor);
        } catch (PasarelaNoDisponible|OrdenNoLiquidable $e) {
            $this->dunning->registrarFallo($acuerdo, $e->getMessage());

            return 'fallido';
        }

        if ($pago->estado === EstadoPago::Aprobado) {
            // El fulfillment de la orden ya avanzó la fecha y cerró la mora.
            return 'cobrado';
        }

        if ($pago->estado === EstadoPago::Pendiente) {
            // Falta que el cliente complete el pago: la fecha NO avanza, la deuda queda
            // y la mora da seguimiento (aviso, reintentos, suspensión si no paga).
            $this->dunning->registrarFallo($acuerdo, 'Falta completar el pago en línea.');

            return 'pendiente';
        }

        $this->dunning->registrarFallo($acuerdo, 'El cobro fue rechazado.');

        return 'fallido';
    }

    /**
     * Cobra el periodo a la tarjeta domiciliada. Un rechazo del banco abre/avanza la
     * mora (con el motivo, para que el cliente sepa qué pasó); si la pasarela no
     * respondió, no es culpa del cliente: se reintenta en la siguiente corrida.
     */
    private function cargoAutomatico(AcuerdoTenant $acuerdo, OrdenTenant $orden, DomiciliacionTenant $domiciliacion): string
    {
        // El banco aún procesa el cargo anterior: se espera su resultado (webhook).
        $enProceso = PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->whereNotNull('domiciliacion_id')
            ->where('estado', EstadoPago::Pendiente->value)
            ->exists();
        if ($enProceso) {
            return 'pendiente';
        }

        try {
            $pago = $this->cobrar->domiciliado($orden, $domiciliacion);
        } catch (CobroNoConcluyente) {
            return 'fallido';
        } catch (PasarelaNoDisponible|OrdenNoLiquidable $e) {
            $this->dunning->registrarFallo($acuerdo, $e->getMessage());

            return 'fallido';
        }

        if ($pago->estado === EstadoPago::Aprobado) {
            $this->domiciliaciones->registrarCargo($domiciliacion, null);

            return 'cobrado';
        }
        if ($pago->estado === EstadoPago::Pendiente) {
            return 'pendiente';
        }

        $motivo = $pago->motivo ?? 'El banco rechazó el cargo.';
        $this->domiciliaciones->registrarCargo($domiciliacion, $motivo);
        $tarjeta = trim(ucfirst((string) $domiciliacion->marca).' terminación '.$domiciliacion->ultimos4);
        $this->dunning->registrarFallo($acuerdo, "No pudimos cobrar tu pago automático a la tarjeta {$tarjeta}: {$motivo}");

        return 'fallido';
    }

    /**
     * La orden de renovación pendiente del acuerdo (la deuda del periodo), o una
     * nueva si no hay.
     */
    private function deudaDelPeriodo(AcuerdoTenant $acuerdo, PersonaTenant $persona, ProductoTenant $producto): OrdenTenant
    {
        $pendiente = OrdenTenant::query()
            ->where('renueva_acuerdo_id', $acuerdo->getKey())
            ->where('estado', EstadoOrden::Pendiente->value)
            ->latest('id')
            ->first();
        if ($pendiente instanceof OrdenTenant) {
            return $pendiente;
        }

        $orden = $this->ordenes->crear($persona, [['producto' => $producto, 'cantidad' => 1, 'beneficiario' => null]]);
        $orden->update(['renueva_acuerdo_id' => $acuerdo->getKey()]);

        return $orden;
    }

    /**
     * Cobra las renovaciones vencidas (acuerdos activos, sin dunning abierto).
     *
     * @return Resumen
     */
    public function procesarVencidas(string $proveedor): array
    {
        $conDunning = ProcesoDunningTenant::query()
            ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
            ->pluck('acuerdo_id')->all();

        $acuerdos = AcuerdoTenant::query()
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('proxima_cobro_en')
            ->whereDate('proxima_cobro_en', '<=', Carbon::now()->toDateString())
            ->when($conDunning !== [], fn ($q) => $q->whereNotIn('id', $conDunning))
            ->with(['persona', 'producto'])
            ->get();

        return $this->procesar($acuerdos, $proveedor);
    }

    /**
     * Reintenta a los morosos (EN MORA) cuyo próximo intento ya venció.
     *
     * @return Resumen
     */
    public function reintentarMorosos(string $proveedor): array
    {
        $acuerdoIds = ProcesoDunningTenant::query()
            ->where('estado', EstadoDunning::EnMora->value)
            ->whereNotNull('proximo_intento_en')
            ->where('proximo_intento_en', '<=', Carbon::now())
            ->pluck('acuerdo_id')->all();

        if ($acuerdoIds === []) {
            return ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];
        }

        $acuerdos = AcuerdoTenant::query()
            ->whereIn('id', $acuerdoIds)
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->with(['persona', 'producto'])
            ->get();

        return $this->procesar($acuerdos, $proveedor);
    }

    /**
     * @param  Collection<int, AcuerdoTenant>  $acuerdos
     * @return Resumen
     */
    private function procesar($acuerdos, string $proveedor): array
    {
        $resumen = ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];

        foreach ($acuerdos as $acuerdo) {
            $resultado = $this->renovar($acuerdo, $proveedor);
            match ($resultado) {
                'cobrado' => $resumen['cobrados']++,
                'pendiente' => $resumen['pendientes']++,
                'fallido' => $resumen['fallidos']++,
                default => null,
            };
        }

        return $resumen;
    }
}
