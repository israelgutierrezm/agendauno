<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Cobro recurrente (Etapa 2) tenant-local: renueva las membresías recurrentes al
 * vencer su `proxima_cobro_en`, cobrando con la pasarela del estudio. Éxito → avanza
 * la fecha de cobro y cierra el dunning; rechazo/pasarela caída → abre/avanza el
 * dunning (reintentos con backoff, suspensión al vencer la gracia). La orden de
 * renovación NO concede un acuerdo nuevo (el entitlement lo mantiene el motor de
 * ciclos). El cobro real contra la pasarela es idéntico al del checkout: aquí solo
 * se orquesta; la pasarela se inyecta y es stubbeable en pruebas (sin llaves reales).
 *
 * @phpstan-type Resumen array{cobrados: int, pendientes: int, fallidos: int}
 */
class CobroRecurrenteTenant
{
    // Cadencia de cobro (meses) entre renovaciones.
    private const PERIODO_MESES = 1;

    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly GestionarDunningTenant $dunning,
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

        // Orden de renovación (factura del periodo), ligada al acuerdo para que el
        // fulfillment NO cree un acuerdo nuevo.
        $orden = $this->ordenes->crear($persona, [['producto' => $producto, 'cantidad' => 1, 'beneficiario' => null]]);
        $orden->update(['renueva_acuerdo_id' => $acuerdo->getKey()]);

        try {
            $pago = $this->cobrar->ejecutar($orden, $proveedor);
        } catch (PasarelaNoDisponible|OrdenNoLiquidable $e) {
            $this->dunning->registrarFallo($acuerdo, $e->getMessage());

            return 'fallido';
        }

        if ($pago->estado === EstadoPago::Aprobado) {
            $this->avanzarCobro($acuerdo);
            $this->dunning->registrarPago($acuerdo); // cierra el dunning si lo había

            return 'cobrado';
        }

        if ($pago->estado === EstadoPago::Pendiente) {
            // Cobro asíncrono (checkout/webhook): avanza la fecha para no recobrar; el
            // webhook confirmará el pago.
            $this->avanzarCobro($acuerdo);

            return 'pendiente';
        }

        $this->dunning->registrarFallo($acuerdo, 'Cobro recurrente rechazado');

        return 'fallido';
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

    /**
     * Ancla `proxima_cobro_en` al siguiente periodo futuro (si el scheduler estuvo
     * caído, no acumula cobros atrasados: cobra una vez y salta al próximo periodo).
     */
    private function avanzarCobro(AcuerdoTenant $acuerdo): void
    {
        $siguiente = Carbon::parse($acuerdo->proxima_cobro_en ?? Carbon::now())->addMonths(self::PERIODO_MESES);
        while ($siguiente->isPast()) {
            $siguiente = $siguiente->addMonths(self::PERIODO_MESES);
        }

        $acuerdo->update(['proxima_cobro_en' => $siguiente->toDateString()]);
    }
}
