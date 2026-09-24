<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\Exceptions\DerechoYaUsado;
use App\Modules\Tenancy\Pagos\Exceptions\PagoNoReembolsable;
use App\Modules\Tenancy\Pasarelas\PasarelaReembolsable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve (refund) un pago tenant-local, total o parcial, con reversión del
 * entitlement y devolución real por la pasarela del estudio.
 *
 * Política (ver docs/audits/turno-uno-competitive-audit.md, R11):
 * - **Total** (todo el monto, sin devoluciones previas): solo si los derechos de la
 *   orden están INTACTOS (sin consumo ni holds activos; ADR-0012). Revierte cada
 *   derecho a 0, cancela sus acuerdos y la orden.
 * - **Parcial**: devolución monetaria; si `revertirCreditos`, revierte una parte
 *   PROPORCIONAL del saldo de los derechos intactos (los ya usados no se tocan).
 * - Un pago admite varias devoluciones parciales; la suma de las APROBADAS nunca
 *   supera su monto. La reversión y el cambio de estado solo se aplican cuando la
 *   pasarela aprueba la devolución (manual/efectivo se aprueban en el momento; una
 *   devolución en línea puede quedar `pendiente` y reconciliarse después).
 *
 * Serializa con `lockForUpdate` sobre el pago (y sobre cada derecho al revertir).
 * El saldo verdadero sigue derivándose del ledger; la reversión es un asiento
 * `Reverso` con origen `Reembolso` referido al pago.
 */
class ReembolsarPagoTenant
{
    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * @param  int|null  $montoMinor  monto a devolver; null = todo lo pendiente
     */
    public function ejecutar(
        PagoTenant $pago,
        ?int $montoMinor,
        string $motivo,
        ?Usuario $actor = null,
        bool $revertirCreditos = true,
    ): ReembolsoTenant {
        return DB::connection('tenant')->transaction(function () use ($pago, $montoMinor, $motivo, $actor, $revertirCreditos): ReembolsoTenant {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($bloqueado->estado, [EstadoPago::Aprobado, EstadoPago::ParcialmenteReembolsado], true)) {
                throw new PagoNoReembolsable('Solo se puede reembolsar un pago aprobado.');
            }

            $yaReembolsado = (int) $bloqueado->reembolsos()->where('estado', EstadoReembolso::Aprobado->value)->sum('monto_minor');
            $restante = $bloqueado->monto_minor - $yaReembolsado;
            if ($restante <= 0) {
                throw new PagoNoReembolsable('El pago ya fue reembolsado en su totalidad.');
            }

            $monto = $montoMinor ?? $restante;
            if ($monto < 1 || $monto > $restante) {
                throw new PagoNoReembolsable('El monto a reembolsar excede lo disponible.');
            }

            $esTotal = $monto === $bloqueado->monto_minor && $yaReembolsado === 0;
            $orden = $bloqueado->orden;

            // Devolución total: se rechaza si algún derecho de la orden ya tuvo uso
            // (verificación ANTES de tocar la pasarela; no se devuelve dinero si luego
            // no podríamos revocar el entitlement).
            if ($esTotal && $revertirCreditos && $orden !== null) {
                $this->exigirDerechosIntactos($orden);
            }

            [$estadoDevolucion, $referencia] = $this->solicitarDevolucion($bloqueado, $monto);

            if ($estadoDevolucion === EstadoReembolso::Aprobado && $revertirCreditos && $orden !== null) {
                if ($esTotal) {
                    $this->revertirTotal($orden, $bloqueado, $actor);
                } else {
                    $this->revertirProporcional($orden, $bloqueado, $monto, $actor);
                }
            }

            $reembolso = $bloqueado->reembolsos()->create([
                'monto_minor' => $monto,
                'moneda' => $bloqueado->moneda,
                'estado' => $estadoDevolucion->value,
                'proveedor' => $bloqueado->proveedor,
                'motivo' => $motivo,
                'revirtio_creditos' => $revertirCreditos && $estadoDevolucion === EstadoReembolso::Aprobado,
                'referencia_externa' => $referencia,
                'actor_id' => $actor?->getKey(),
                'actor_nombre' => $actor?->name,
                'metadata' => ['parcial' => ! $esTotal],
            ]);

            if ($estadoDevolucion === EstadoReembolso::Aprobado) {
                $acumulado = $yaReembolsado + $monto;
                $bloqueado->update([
                    'estado' => $acumulado >= $bloqueado->monto_minor
                        ? EstadoPago::Reembolsado->value
                        : EstadoPago::ParcialmenteReembolsado->value,
                ]);

                if ($esTotal && $revertirCreditos && $orden !== null) {
                    $orden->update(['estado' => EstadoOrden::Cancelada->value]);
                }

                // Evento de dominio (outbox, R39): dispara conciliacion contable,
                // notificacion al cliente, webhooks salientes, etc. (consumidores P1).
                $this->eventos->registrar('pago.reembolsado', 'pago', $bloqueado->ulid, [
                    'reembolso_id' => $reembolso->ulid,
                    'monto_minor' => $monto,
                    'moneda' => $bloqueado->moneda,
                    'total' => $esTotal,
                    'revirtio_creditos' => $revertirCreditos,
                ]);
            }

            return $reembolso;
        });
    }

    /**
     * Solicita la devolución a la pasarela del estudio cuando es en línea y la soporta;
     * si no (manual/efectivo), el dinero se devuelve en caja y se aprueba en el momento.
     *
     * @return array{0: EstadoReembolso, 1: string|null}
     */
    private function solicitarDevolucion(PagoTenant $pago, int $monto): array
    {
        $pasarela = $this->registro->resolver($pago->proveedor);

        if ($pasarela instanceof PasarelaReembolsable && $pago->referencia_externa !== null && $pago->referencia_externa !== '') {
            $resultado = $pasarela->reembolsar($pago, $monto, $this->registro->llaves($pago->proveedor));

            $estado = match (true) {
                $resultado->esAprobado() => EstadoReembolso::Aprobado,
                $resultado->esPendiente() => EstadoReembolso::Pendiente,
                default => EstadoReembolso::Fallido,
            };

            return [$estado, $resultado->referencia];
        }

        return [EstadoReembolso::Aprobado, null];
    }

    /**
     * Revierte a 0 el saldo de cada derecho intacto de la orden y cancela sus acuerdos.
     */
    private function revertirTotal(OrdenTenant $orden, PagoTenant $pago, ?Usuario $actor): void
    {
        foreach ($this->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();
                $saldo = $this->libro->saldo($bloqueado);
                if ($saldo !== 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Reverso, -$saldo, 'Reembolso total del pago', $this->contexto($pago, $actor));
                }
            }
            $acuerdo->update(['estado' => EstadoAcuerdo::Cancelado->value]);
        }
    }

    /**
     * Revierte una parte PROPORCIONAL del saldo de cada derecho intacto (los que ya
     * tuvieron uso no se tocan: la devolución es solo monetaria para ellos).
     */
    private function revertirProporcional(OrdenTenant $orden, PagoTenant $pago, int $monto, ?Usuario $actor): void
    {
        $total = $pago->monto_minor;
        if ($total <= 0) {
            return;
        }

        foreach ($this->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();
                if ($this->tuvoUso($bloqueado)) {
                    continue;
                }

                $saldo = $this->libro->saldo($bloqueado);
                $revertir = intdiv($saldo * $monto, $total);
                if ($revertir > 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Reverso, -$revertir, 'Reembolso parcial del pago', $this->contexto($pago, $actor));
                }
            }
        }
    }

    private function exigirDerechosIntactos(OrdenTenant $orden): void
    {
        foreach ($this->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                if ($this->tuvoUso($derecho)) {
                    throw new DerechoYaUsado('El derecho ya tuvo uso; no se puede reembolsar el pago completo.');
                }
            }
        }
    }

    /**
     * Acuerdos (con sus derechos) que la orden concedió y siguen activos.
     *
     * @return Collection<int, AcuerdoTenant>
     */
    private function acuerdosDe(OrdenTenant $orden): Collection
    {
        $orden->loadMissing('lineas');
        $lineaIds = $orden->lineas->pluck('id')->all();

        return AcuerdoTenant::query()
            ->whereIn('linea_orden_id', $lineaIds)
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with('derechos')
            ->get();
    }

    private function tuvoUso(DerechoTenant $derecho): bool
    {
        $consumos = $derecho->movimientos()
            ->where('tipo', TipoMovimiento::Consumo->value)
            ->exists();

        $holdsActivos = $derecho->retenciones()
            ->where('estado', EstadoRetencion::Activa->value)
            ->exists();

        return $consumos || $holdsActivos;
    }

    private function contexto(PagoTenant $pago, ?Usuario $actor): ContextoMovimiento
    {
        return ContextoMovimiento::para(OrigenMovimiento::Reembolso, 'pago', $pago->ulid, $actor);
    }
}
