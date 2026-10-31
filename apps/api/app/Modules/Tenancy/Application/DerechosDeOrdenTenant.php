<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\Exceptions\DerechoYaUsado;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Illuminate\Support\Collection;

/**
 * Lo que una orden concedió (sus acuerdos y derechos, por `linea_orden_id`) y cómo se
 * retira todo cuando el pago se devuelve completo o se anula (ADR 0087).
 *
 * Retirar no borra nada: lleva cada derecho a 0 con un movimiento `reverso` en el
 * ledger, cancela el acuerdo (sin pago automático ni renovación por cobrar). Antes,
 * {@see exigirIntactos()} asegura que ningún derecho tuvo uso.
 */
class DerechosDeOrdenTenant
{
    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly DeudaDeRenovacionTenant $deudas,
    ) {}

    /**
     * Acuerdos (con sus derechos) que la orden concedió y siguen activos.
     *
     * @return Collection<int, AcuerdoTenant>
     */
    public function acuerdosDe(OrdenTenant $orden): Collection
    {
        $orden->loadMissing('lineas');
        $lineaIds = $orden->lineas->pluck('id')->all();

        return AcuerdoTenant::query()
            ->whereIn('linea_orden_id', $lineaIds)
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with('derechos')
            ->get();
    }

    /** ¿Algún derecho de la orden ya se usó (consumo o retención activa)? */
    public function algunoUsado(OrdenTenant $orden): bool
    {
        foreach ($this->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                if ($this->tuvoUso($derecho)) {
                    return true;
                }
            }
        }

        return $this->prepagoUsado($orden);
    }

    /** Cuántas clases extra, con saldo, cuelgan de los derechos que la orden concedió. */
    public function extrasConSaldo(OrdenTenant $orden): int
    {
        $ids = $this->acuerdosDe($orden)->flatMap(fn (AcuerdoTenant $a) => $a->derechos->modelKeys())->all();

        return DerechoTenant::query()
            ->whereIn('extra_de_id', $ids)
            ->get()
            ->filter(fn (DerechoTenant $d): bool => $this->libro->disponible($d) > 0)
            ->count();
    }

    /**
     * ¿Alguna vez de un servicio pagado por adelantado ya se usó (ADR 0218)? Esas citas se
     * ligan a la línea, sin acuerdo ni derecho.
     */
    public function prepagoUsado(OrdenTenant $orden): bool
    {
        $lineas = $orden->lineas()->whereNotNull('oferta_id')->pluck('id');

        return $lineas->isNotEmpty()
            && app(ServiciosPrepagadosTenant::class)->queUsan(ReservaTenant::query()->whereIn('linea_orden_id', $lineas))->exists();
    }

    /**
     * Consumo, retención activa o, en un plan ilimitado (que no crea ninguna de las dos),
     * una reserva viva o ya atendida.
     */
    public function tuvoUso(DerechoTenant $derecho): bool
    {
        $consumos = $derecho->movimientos()
            ->where('tipo', TipoMovimiento::Consumo->value)
            ->exists();

        $holdsActivos = $derecho->retenciones()
            ->where('estado', EstadoRetencion::Activa->value)
            ->exists();

        return $consumos || $holdsActivos || ReservaTenant::query()
            ->where('derecho_id', $derecho->getKey())
            ->where(static fn ($q) => $q
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value, EstadoReserva::Ofrecida->value])
                ->orWhereHas('asistencia'))
            ->exists();
    }

    /**
     * Las reservas por venir de esos derechos se cancelan, como en una baja: ya no hay
     * venta que las respalde. Antes de bloquear los derechos (el orden único de candados
     * pone la reserva antes que el derecho). Cuántas se cancelaron.
     *
     * @param  list<int>  $derechoIds
     */
    private function cancelarReservasFuturas(array $derechoIds, ?Usuario $actor): int
    {
        $reservas = ReservaTenant::query()
            ->whereIn('derecho_id', $derechoIds)
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
            ->whereHas('sesion', static fn ($q) => $q->where('inicia_en', '>', now()))
            ->orderBy('id')
            ->get();
        foreach ($reservas as $reserva) {
            app(ReservasTenant::class)->cancelar($reserva, QuienCancela::Negocio, $actor);
        }

        return $reservas->count();
    }

    /**
     * Retira lo que queda de cada derecho de la orden (reverso en el ledger, bajo
     * candado) y cancela sus acuerdos. Lo apartado para una clase que ya se reservó no
     * se retira (el saldo nunca queda en negativo).
     *
     * Con `$exigirSinUso` (anular un cobro), primero bloquea los derechos y, si alguno
     * ya se usó (uno que se reservó mientras tanto, p. ej.), no retira nada: lanza
     * {@see DerechoYaUsado} para que la operación se deshaga.
     *
     * Devuelve si alguno ya se había usado (lo revisa con los derechos bloqueados).
     */
    public function retirarTodo(OrdenTenant $orden, ContextoMovimiento $contexto, string $descripcion, ?Usuario $actor, bool $exigirSinUso = false): bool
    {
        $acuerdos = $this->acuerdosDe($orden);
        $ids = $acuerdos->flatMap(fn (AcuerdoTenant $a) => $a->derechos->modelKeys())->sort()->values()->all();
        // Sin esa exigencia (el dinero ya salió), lo reservado a futuro no sigue en pie.
        $canceladas = $exigirSinUso ? 0 : $this->cancelarReservasFuturas($ids, $actor);
        // Bajo candado y en orden de id, y ya bloqueados se revisa el uso.
        $bloqueados = DerechoTenant::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $huboUso = $canceladas > 0 || $bloqueados->contains(fn (DerechoTenant $d): bool => $this->tuvoUso($d));
        if ($exigirSinUso && $huboUso) {
            throw new DerechoYaUsado('Los créditos o la membresía de esta venta ya se usaron; usa la devolución.');
        }
        foreach ($acuerdos as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                $bloqueado = $bloqueados->get($derecho->getKey());
                if (! $bloqueado instanceof DerechoTenant) {
                    continue;
                }
                $retirable = max(0, $this->libro->disponible($bloqueado));
                if ($retirable !== 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Reverso, -$retirable, $descripcion, $contexto);
                }
            }
            $acuerdo->update(['estado' => EstadoAcuerdo::Cancelado->value]);
            // Cancelada ya no se renueva: sin pago automático ni renovación por cobrar.
            $this->domiciliaciones->desactivar($acuerdo);
            $this->deudas->anular([$acuerdo->getKey()], $actor);
        }

        return $huboUso;
    }
}
