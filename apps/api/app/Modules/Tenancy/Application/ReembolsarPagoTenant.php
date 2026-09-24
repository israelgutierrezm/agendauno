<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\Exceptions\DerechoYaUsado;
use App\Modules\Tenancy\Pagos\Exceptions\PagoNoReembolsable;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Pasarelas\PasarelaReembolsable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Devuelve (refund) un pago tenant-local, total o parcial, con reversión del
 * entitlement.
 *
 * Cómo se devuelve el dinero (`metadata.via` de la devolución):
 * - **pasarela**: pago en línea → se pide la devolución a la pasarela (Stripe). Puede
 *   quedar `aprobada` en el momento, `pendiente` (la confirma después el webhook con
 *   {@see conciliar()}) o `fallida`.
 * - **manual**: pago en línea cuyo dinero el negocio ya devolvió por fuera
 *   (transferencia, efectivo); lo declara explícitamente. Si la pasarela no puede
 *   devolver en línea y no se declara manual, NO se registra nada.
 * - **caja**: pago en efectivo/manual/ventanilla: se devuelve en caja, aprobada.
 *
 * Solo una devolución APROBADA revierte créditos, cambia el estado del pago y emite
 * `pago.reembolsado`. Lo pendiente cuenta para no devolver de más.
 *
 * Política de créditos (R11): **total** (todo el monto, sin devoluciones previas)
 * exige derechos INTACTOS y los revierte a 0; **parcial** revierte una parte
 * proporcional de los derechos intactos, calculada sobre lo ACUMULADO: dos
 * devoluciones del 50 % dejan 0 créditos, no 25 %.
 *
 * Serializa con `lockForUpdate` sobre el pago (y sobre cada derecho al revertir).
 */
class ReembolsarPagoTenant
{
    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly RegistrarEventoTenant $eventos,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    /**
     * @param  int|null  $montoMinor  monto a devolver; null = todo lo pendiente
     * @param  bool  $manual  el dinero de un pago en línea ya se devolvió por fuera
     */
    public function ejecutar(
        PagoTenant $pago,
        ?int $montoMinor,
        string $motivo,
        ?Usuario $actor = null,
        bool $revertirCreditos = true,
        bool $manual = false,
    ): ReembolsoTenant {
        return DB::connection('tenant')->transaction(function () use ($pago, $montoMinor, $motivo, $actor, $revertirCreditos, $manual): ReembolsoTenant {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($bloqueado->estado, [EstadoPago::Aprobado, EstadoPago::ParcialmenteReembolsado], true)) {
                throw new PagoNoReembolsable('Solo se puede reembolsar un pago aprobado.');
            }

            // Lo aprobado y lo que está en curso: no se puede devolver de más.
            $comprometido = (int) $bloqueado->reembolsos()
                ->whereIn('estado', [EstadoReembolso::Aprobado->value, EstadoReembolso::Pendiente->value])
                ->sum('monto_minor');
            $restante = $bloqueado->monto_minor - $comprometido;
            if ($restante <= 0) {
                throw new PagoNoReembolsable('El pago ya fue reembolsado en su totalidad (o tiene una devolución en curso).');
            }

            $monto = $montoMinor ?? $restante;
            if ($monto < 1 || $monto > $restante) {
                throw new PagoNoReembolsable('El monto a reembolsar excede lo disponible.');
            }

            $esTotal = $monto === $bloqueado->monto_minor && $comprometido === 0;
            $orden = $bloqueado->orden;

            // Devolución total: se rechaza si algún derecho de la orden ya tuvo uso
            // (verificación ANTES de tocar la pasarela; no se devuelve dinero si luego
            // no podríamos revocar el entitlement).
            if ($esTotal && $revertirCreditos && $orden !== null) {
                $this->exigirDerechosIntactos($orden);
            }

            [$estado, $referencia, $via] = $this->solicitarDevolucion($bloqueado, $monto, $manual);

            $reembolso = $bloqueado->reembolsos()->create([
                'monto_minor' => $monto,
                'moneda' => $bloqueado->moneda,
                'estado' => $estado === EstadoReembolso::Aprobado ? EstadoReembolso::Pendiente->value : $estado->value,
                'proveedor' => $bloqueado->proveedor,
                'motivo' => $motivo,
                'revirtio_creditos' => false,
                'referencia_externa' => $referencia,
                'actor_id' => $actor?->getKey(),
                'actor_nombre' => $actor?->name,
                'metadata' => [
                    'parcial' => ! $esTotal,
                    'total' => $esTotal,
                    'via' => $via,
                    'revertir_creditos' => $revertirCreditos,
                ],
            ]);

            if ($estado === EstadoReembolso::Aprobado) {
                $this->aplicarAprobado($bloqueado, $reembolso, $actor);
            }

            return $reembolso->refresh();
        });
    }

    /**
     * Resultado de una devolución en línea que quedó pendiente (webhook de la
     * pasarela): la aprueba (y aplica sus efectos) o la marca fallida. Idempotente.
     */
    public function conciliar(string $referencia, bool $exitosa): ?ReembolsoTenant
    {
        if ($referencia === '') {
            return null;
        }

        return DB::connection('tenant')->transaction(function () use ($referencia, $exitosa): ?ReembolsoTenant {
            $reembolso = ReembolsoTenant::query()->where('referencia_externa', $referencia)->lockForUpdate()->first();
            if (! $reembolso instanceof ReembolsoTenant || $reembolso->estado !== EstadoReembolso::Pendiente) {
                return $reembolso;
            }

            if (! $exitosa) {
                $reembolso->update(['estado' => EstadoReembolso::Fallido->value]);

                return $reembolso;
            }

            $pago = PagoTenant::query()->whereKey($reembolso->pago_id)->lockForUpdate()->firstOrFail();
            $this->aplicarAprobado($pago, $reembolso, null);

            return $reembolso->refresh();
        });
    }

    /**
     * Efectos de una devolución aprobada: revierte créditos (si se pidió), deja el
     * estado del pago según lo acumulado, cancela la orden en una total y emite el
     * evento.
     */
    private function aplicarAprobado(PagoTenant $pago, ReembolsoTenant $reembolso, ?Usuario $actor): void
    {
        $reembolso->update(['estado' => EstadoReembolso::Aprobado->value]);

        $metadata = $reembolso->metadata ?? [];
        $revertir = (bool) ($metadata['revertir_creditos'] ?? true);
        $esTotal = (bool) ($metadata['total'] ?? false);
        $orden = $pago->orden;

        if ($revertir && $orden !== null) {
            if ($esTotal) {
                $this->revertirTotal($orden, $pago, $actor);
            } else {
                $this->revertirProporcional($orden, $pago, $actor);
            }
            $reembolso->update(['revirtio_creditos' => true]);
        }

        $acumulado = (int) $pago->reembolsos()->where('estado', EstadoReembolso::Aprobado->value)->sum('monto_minor');
        $pago->update([
            'estado' => $acumulado >= $pago->monto_minor
                ? EstadoPago::Reembolsado->value
                : EstadoPago::ParcialmenteReembolsado->value,
        ]);

        if ($esTotal && $revertir && $orden !== null) {
            $orden->update(['estado' => EstadoOrden::Cancelada->value]);
        }

        // Evento de dominio (outbox, R39): conciliación, aviso al cliente, webhooks.
        $this->eventos->registrar('pago.reembolsado', 'pago', $pago->ulid, [
            'reembolso_id' => $reembolso->ulid,
            'monto_minor' => $reembolso->monto_minor,
            'moneda' => $pago->moneda,
            'total' => $esTotal,
            'revirtio_creditos' => $revertir,
            'via' => $metadata['via'] ?? null,
        ]);
    }

    /**
     * Pide la devolución por la vía que corresponde al pago.
     *
     * @return array{0: EstadoReembolso, 1: string|null, 2: string}
     */
    private function solicitarDevolucion(PagoTenant $pago, int $monto, bool $manual): array
    {
        if (! in_array($pago->proveedor, ProveedorPasarela::enLinea(), true)) {
            return [EstadoReembolso::Aprobado, null, 'caja'];
        }
        if ($manual) {
            return [EstadoReembolso::Aprobado, null, 'manual'];
        }

        $sinVia = 'Esta pasarela no puede devolver el pago en línea. Si ya devolviste el dinero por otro medio, regístralo como devolución manual.';
        try {
            $pasarela = $this->registro->resolver($pago->proveedor);
        } catch (PasarelaNoDisponible) {
            throw new PagoNoReembolsable($sinVia);
        }
        if (! $pasarela instanceof PasarelaReembolsable || $pago->referencia_externa === null || $pago->referencia_externa === '') {
            throw new PagoNoReembolsable($sinVia);
        }

        try {
            $resultado = $pasarela->reembolsar($pago, $monto, $this->registro->llaves($pago->proveedor));
        } catch (PasarelaNoDisponible $e) {
            throw new PagoNoReembolsable($e->getMessage().' Si ya devolviste el dinero por otro medio, regístralo como devolución manual.');
        } catch (Throwable $e) {
            throw new PagoNoReembolsable('La pasarela no aceptó la devolución: '.$e->getMessage());
        }

        $estado = match (true) {
            $resultado->esAprobado() => EstadoReembolso::Aprobado,
            $resultado->esPendiente() => EstadoReembolso::Pendiente,
            default => EstadoReembolso::Fallido,
        };

        return [$estado, $resultado->referencia, 'pasarela'];
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
            // Cancelada ya no se renueva: sin pago automático.
            $this->domiciliaciones->desactivar($acuerdo);
        }
    }

    /**
     * Revierte la parte PROPORCIONAL a lo devuelto HASTA AHORA de cada derecho intacto
     * (los que ya tuvieron uso no se tocan: la devolución es solo monetaria para
     * ellos). Base = saldo actual + lo que ya revirtieron devoluciones previas de este
     * pago; se revierte lo que falte para llegar a base × devuelto / total.
     */
    private function revertirProporcional(OrdenTenant $orden, PagoTenant $pago, ?Usuario $actor): void
    {
        $total = $pago->monto_minor;
        if ($total <= 0) {
            return;
        }
        $devuelto = min($total, (int) $pago->reembolsos()->where('estado', EstadoReembolso::Aprobado->value)->sum('monto_minor'));

        foreach ($this->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();
                if ($this->tuvoUso($bloqueado)) {
                    continue;
                }

                $yaRevertido = $this->revertidoPorPago($bloqueado, $pago);
                $base = $this->libro->saldo($bloqueado) + $yaRevertido;
                $objetivo = intdiv($base * $devuelto, $total);
                $revertir = $objetivo - $yaRevertido;
                if ($revertir > 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Reverso, -$revertir, 'Reembolso parcial del pago', $this->contexto($pago, $actor));
                }
            }
        }
    }

    /**
     * Unidades que devoluciones de este pago ya le revirtieron al derecho.
     */
    private function revertidoPorPago(DerechoTenant $derecho, PagoTenant $pago): int
    {
        return (int) abs((int) MovimientoCreditoTenant::query()
            ->where('derecho_id', $derecho->getKey())
            ->where('tipo', TipoMovimiento::Reverso->value)
            ->where('origen', OrigenMovimiento::Reembolso->value)
            ->where('referencia_tipo', 'pago')
            ->where('referencia_id', $pago->ulid)
            ->sum('unidades'));
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
