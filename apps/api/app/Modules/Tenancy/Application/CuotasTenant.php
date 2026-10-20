<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Cuotas\ConceptoCuota;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ConfiguracionCuotasTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las cuotas del negocio (plan de mejoras B04, decisiones del dueño del 2026-10-10):
 *
 * - **Inscripción:** se cobra en la primera alta y al regresar tras X meses sin plan
 *   (X configurable), al vender un plan que la cobra. Quien ya tiene un plan en vigor no
 *   la paga. Si ya hay una por cobrar, no se suma otra.
 * - **Anualidad:** ver {@see AnualidadesTenant}.
 *
 * Una cuota no da clases ni crea un acuerdo: es una línea de orden sin producto.
 */
class CuotasTenant
{
    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    /**
     * ¿Le toca pagar la inscripción si contrata hoy? (Con el monto en 0, nunca.)
     */
    public function debeInscripcion(PersonaTenant $persona): bool
    {
        $config = ConfiguracionCuotasTenant::actual();
        if ($config->inscripcion_minor <= 0 || $this->hayPorCobrar($persona, ConceptoCuota::Inscripcion)) {
            return false;
        }
        $hoy = CarbonImmutable::parse($this->fechas->hoy());
        $ultimo = $this->ultimoDiaConPlan($persona, $hoy);

        // Primera alta, o regresa tras más de X meses sin plan.
        return $ultimo === null || $ultimo->lessThan($hoy->subMonthsNoOverflow(max(0, $config->reingreso_meses)));
    }

    /**
     * La línea de la inscripción para una orden (monto vigente de la configuración).
     *
     * @return array{concepto: string, cantidad: int, precio_unitario_minor: int, subtotal_minor: int, beneficiario_id: int}
     */
    public function lineaDeInscripcion(PersonaTenant $persona): array
    {
        $monto = ConfiguracionCuotasTenant::actual()->inscripcion_minor;

        return [
            'concepto' => ConceptoCuota::Inscripcion->value,
            'cantidad' => 1,
            'precio_unitario_minor' => $monto,
            'subtotal_minor' => $monto,
            'beneficiario_id' => (int) $persona->getKey(),
        ];
    }

    /**
     * ¿Hay una cuota de ese concepto por cobrar para esta persona (orden pendiente)?
     */
    public function hayPorCobrar(PersonaTenant $persona, ConceptoCuota $concepto): bool
    {
        return LineaOrdenTenant::query()
            ->where('concepto', $concepto->value)
            ->whereHas('orden', fn (Builder $q) => $q->where('estado', EstadoOrden::Pendiente->value))
            ->where(fn (Builder $q) => $q->where('beneficiario_id', $persona->getKey())
                ->orWhere(fn (Builder $q) => $q->whereNull('beneficiario_id')
                    ->whereHas('orden', fn (Builder $o) => $o->where('persona_id', $persona->getKey()))))
            ->exists();
    }

    /**
     * El último día en que tuvo un plan: hoy si tiene uno en vigor; si no, el día en
     * que venció o se canceló el más reciente; null si nunca tuvo uno.
     */
    public function ultimoDiaConPlan(PersonaTenant $persona, CarbonImmutable $hoy): ?CarbonImmutable
    {
        $acuerdos = AcuerdoTenant::query()
            ->where('persona_id', $persona->getKey())
            // Un plan deshecho (su cobro se anuló o se devolvió completo) nunca contó: su
            // venta no quedó pagada.
            ->where(fn (Builder $q) => $q->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
                ->orWhereNull('linea_orden_id')
                ->orWhereHas('lineaOrden.orden', fn (Builder $o) => $o->where('estado', EstadoOrden::Pagada->value)))
            ->with('derechos')
            ->get();
        if ($acuerdos->isEmpty()) {
            return null;
        }

        $ultimo = null;
        foreach ($acuerdos as $acuerdo) {
            $vigente = $acuerdo->estado !== EstadoAcuerdo::Cancelado;
            foreach ($acuerdo->derechos as $derecho) {
                /** @var DerechoTenant $derecho */
                $hasta = $derecho->valido_hasta === null ? null : CarbonImmutable::parse($derecho->valido_hasta->toDateString());
                if ($vigente && ($hasta === null || $hasta->greaterThanOrEqualTo($hoy))) {
                    return $hoy;
                }
                $fin = $hasta ?? CarbonImmutable::parse($acuerdo->updated_at?->toDateString() ?? $hoy->toDateString());
                $ultimo = $ultimo === null || $fin->greaterThan($ultimo) ? $fin : $ultimo;
            }
            if ($acuerdo->derechos->isEmpty()) {
                $fin = CarbonImmutable::parse($acuerdo->updated_at?->toDateString() ?? $hoy->toDateString());
                $ultimo = $ultimo === null || $fin->greaterThan($ultimo) ? $fin : $ultimo;
            }
        }

        return $ultimo;
    }
}
