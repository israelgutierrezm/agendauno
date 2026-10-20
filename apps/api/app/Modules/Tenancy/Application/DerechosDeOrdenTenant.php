<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\Exceptions\DerechoYaUsado;
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

        return false;
    }

    public function tuvoUso(DerechoTenant $derecho): bool
    {
        $consumos = $derecho->movimientos()
            ->where('tipo', TipoMovimiento::Consumo->value)
            ->exists();

        $holdsActivos = $derecho->retenciones()
            ->where('estado', EstadoRetencion::Activa->value)
            ->exists();

        return $consumos || $holdsActivos;
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
        // Bajo candado y en orden de id, y ya bloqueados se revisa el uso.
        $bloqueados = DerechoTenant::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $huboUso = $bloqueados->contains(fn (DerechoTenant $d): bool => $this->tuvoUso($d));
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
