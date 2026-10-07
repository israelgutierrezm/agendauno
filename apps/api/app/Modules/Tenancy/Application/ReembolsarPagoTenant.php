<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
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
use App\Support\ReporteDeErrores;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Devuelve (refund) un pago tenant-local, total o parcial, con reversión del
 * entitlement.
 *
 * Cómo se devuelve el dinero (`metadata.via` de la devolución):
 * - **pasarela**: pago en línea → se registra `solicitado` y luego se pide a la
 *   pasarela con la llave de la propia devolución (reintentar nunca devuelve dos
 *   veces). Queda `aprobada`, `pendiente` (la confirma el webhook con
 *   {@see conciliar()}), `fallida` (con el motivo) o `incierta` si no respondió: esa
 *   pasa a "por conciliar" y se vuelve a consultar con la misma llave
 *   ({@see ConciliarReembolsosTenant}).
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
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly DerechosDeOrdenTenant $derechos,
    ) {}

    /**
     * Pasarelas que admiten reintentar la misma devolución con la misma llave sin
     * devolver dos veces (Stripe la respeta 24 h; Mercado Pago, con X-Idempotency-Key).
     */
    private const REINTENTO_SEGURO = ['stripe', 'mercadopago'];

    /**
     * @param  int|null  $montoMinor  monto a devolver; null = todo lo pendiente
     * @param  bool  $manual  el dinero de un pago en línea ya se devolvió por fuera
     * @param  string|null  $llave  la de la pantalla por intento: repetir la solicitud (doble clic) devuelve la misma devolución
     */
    public function ejecutar(
        PagoTenant $pago,
        ?int $montoMinor,
        string $motivo,
        ?Usuario $actor = null,
        bool $revertirCreditos = true,
        bool $manual = false,
        ?string $llave = null,
    ): ReembolsoTenant {
        // 1) Se registra la intención (y en caja o manual, se aplica) antes de hablar
        // con la pasarela: si algo falla después, la devolución queda rastreable.
        [$reembolso, $nuevo] = DB::connection('tenant')->transaction(function () use ($pago, $montoMinor, $motivo, $actor, $revertirCreditos, $manual, $llave): array {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();

            if ($llave !== null) {
                $repetido = $bloqueado->reembolsos()->where('llave', $llave)->first();
                if ($repetido instanceof ReembolsoTenant) {
                    return [$repetido, false];
                }
            }

            if (! in_array($bloqueado->estado, [EstadoPago::Aprobado, EstadoPago::ParcialmenteReembolsado], true)) {
                throw new PagoNoReembolsable('Solo se puede reembolsar un pago aprobado.');
            }

            // Lo aprobado y lo que está en curso: no se puede devolver de más.
            $comprometido = (int) $bloqueado->reembolsos()
                ->whereIn('estado', EstadoReembolso::comprometidos())
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
            // (antes de tocar la pasarela: no se devuelve dinero si luego no podríamos
            // revocar el entitlement).
            if ($esTotal && $revertirCreditos && $orden !== null) {
                $this->exigirDerechosIntactos($orden);
            }

            $via = $this->via($bloqueado, $manual);

            $reembolso = $bloqueado->reembolsos()->create([
                'monto_minor' => $monto,
                'moneda' => $bloqueado->moneda,
                'estado' => EstadoReembolso::Solicitado->value,
                'proveedor' => $bloqueado->proveedor,
                'motivo' => $motivo,
                'revirtio_creditos' => false,
                'llave' => $llave,
                'actor_id' => $actor?->getKey(),
                'actor_nombre' => $actor?->name,
                'metadata' => [
                    'parcial' => ! $esTotal,
                    'total' => $esTotal,
                    'via' => $via,
                    'revertir_creditos' => $revertirCreditos,
                ],
            ]);

            // En caja o declarada manual el dinero ya se devolvió: se aplica ahora.
            if ($via !== 'pasarela') {
                $this->aplicarAprobado($bloqueado, $reembolso, $actor);
            }

            return [$reembolso, true];
        });

        // 2) En línea: se pide a la pasarela fuera de la transacción, con la llave de
        // la propia devolución.
        if ($nuevo && ($reembolso->metadata['via'] ?? null) === 'pasarela') {
            $this->pedirAPasarela($reembolso, $actor);
        }

        return $reembolso->refresh();
    }

    /**
     * Pide (o vuelve a pedir, con la MISMA llave) la devolución a la pasarela y aplica
     * el resultado. Si la pasarela dijo que no, queda fallida y se avisa con su motivo;
     * si no respondió, queda incierta (pudo haber devuelto) para confirmarla después.
     */
    public function pedirAPasarela(ReembolsoTenant $reembolso, ?Usuario $actor = null): void
    {
        $pago = PagoTenant::query()->whereKey($reembolso->pago_id)->firstOrFail();
        $reembolso->increment('intentos');

        try {
            $pasarela = $this->pasarelaDe($pago);
            $resultado = $pasarela->reembolsar($pago, $reembolso->monto_minor, $this->registro->llaves($pago->proveedor), 'reembolso_'.$reembolso->ulid);
        } catch (PagoNoReembolsable|PasarelaNoDisponible $e) {
            $this->resolver($reembolso, EstadoReembolso::Fallido, null, $e->getMessage(), $actor);

            throw new PagoNoReembolsable($e->getMessage().' Si ya devolviste el dinero por otro medio, regístralo como devolución manual.');
        } catch (RequestException $e) {
            if ($e->response->status() < 500) {
                // La pasarela respondió que no (p. ej. ya estaba devuelto): es definitivo.
                $motivo = 'La pasarela no aceptó la devolución: '.$e->getMessage();
                $this->resolver($reembolso, EstadoReembolso::Fallido, null, $motivo, $actor);

                throw new PagoNoReembolsable($motivo.' Si ya devolviste el dinero por otro medio, regístralo como devolución manual.');
            }
            $this->marcarIncierto($reembolso, 'La pasarela respondió con error ('.$e->response->status().').');

            return;
        } catch (Throwable $e) {
            // Sin respuesta (tiempo agotado, red): no sabemos si devolvió.
            ReporteDeErrores::reportarAtrapado($e);
            $this->marcarIncierto($reembolso, 'La pasarela no respondió.');

            return;
        }

        $estado = match (true) {
            $resultado->esAprobado() => EstadoReembolso::Aprobado,
            $resultado->esPendiente() => EstadoReembolso::Pendiente,
            default => EstadoReembolso::Fallido,
        };
        $this->resolver($reembolso, $estado, $resultado->referencia, $resultado->motivo, $actor);
    }

    /**
     * Resultado de una devolución en línea que llega después (webhook de la
     * pasarela): la aprueba (y aplica sus efectos) o la marca fallida. Idempotente.
     */
    public function conciliar(string $referencia, bool $exitosa): ?ReembolsoTenant
    {
        if ($referencia === '') {
            return null;
        }

        $reembolso = ReembolsoTenant::query()->where('referencia_externa', $referencia)->first();
        if (! $reembolso instanceof ReembolsoTenant) {
            return null;
        }

        $this->resolver($reembolso, $exitosa ? EstadoReembolso::Aprobado : EstadoReembolso::Fallido, $referencia, null, null);

        return $reembolso->refresh();
    }

    /**
     * ¿Se puede volver a pedir a la pasarela sin riesgo de devolver dos veces?
     */
    public function reintentable(ReembolsoTenant $reembolso): bool
    {
        return in_array($reembolso->proveedor, self::REINTENTO_SEGURO, true);
    }

    /**
     * Deja la devolución en su estado final (o pendiente) y aplica sus efectos UNA
     * sola vez: una devolución ya aprobada o fallida no cambia.
     */
    public function resolver(ReembolsoTenant $reembolso, EstadoReembolso $estado, ?string $referencia, ?string $motivo, ?Usuario $actor): void
    {
        DB::connection('tenant')->transaction(function () use ($reembolso, $estado, $referencia, $motivo, $actor): void {
            $pago = PagoTenant::query()->whereKey($reembolso->pago_id)->lockForUpdate()->firstOrFail();
            $bloqueado = ReembolsoTenant::query()->whereKey($reembolso->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado->esFinal()) {
                return;
            }

            $bloqueado->update(array_filter([
                'referencia_externa' => $referencia,
                'motivo_fallo' => $estado === EstadoReembolso::Fallido ? ($motivo ?? 'La pasarela rechazó la devolución.') : null,
            ], static fn (?string $v): bool => $v !== null));

            match ($estado) {
                EstadoReembolso::Aprobado => $this->aplicarAprobado($pago, $bloqueado, $actor),
                default => $bloqueado->update(['estado' => $estado->value]),
            };

            // Ya no es incierta: lo que estaba por conciliar queda resuelto.
            $this->incidencias->cerrarDeReembolso($bloqueado, match ($estado) {
                EstadoReembolso::Aprobado => 'Se confirmó la devolución.',
                EstadoReembolso::Pendiente => 'La pasarela recibió la devolución; la confirma después.',
                default => 'La devolución no se hizo.',
            }, $actor);
        });
    }

    /**
     * La pasarela no respondió: la devolución queda incierta (no se aplica ni se pide
     * otra) y pasa a "por conciliar" hasta confirmarla.
     */
    private function marcarIncierto(ReembolsoTenant $reembolso, string $motivo): void
    {
        DB::connection('tenant')->transaction(function () use ($reembolso, $motivo): void {
            $bloqueado = ReembolsoTenant::query()->whereKey($reembolso->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado->esFinal()) {
                return;
            }

            $bloqueado->update(['estado' => EstadoReembolso::Incierto->value, 'motivo_fallo' => $motivo]);
            $this->incidencias->porReembolso(
                IncidenciaCobroTenant::REEMBOLSO_INCIERTO,
                $bloqueado,
                $this->reintentable($bloqueado)
                    ? 'La pasarela no confirmó la devolución. La consultamos de nuevo automáticamente; si no se aclara, revísala en su panel.'
                    : 'La pasarela no confirmó la devolución. Revisa en su panel si se hizo y márcala aquí.',
                ['motivo' => $motivo],
            );
        });
    }

    /**
     * Efectos de una devolución aprobada: revierte créditos (si se pidió), deja el
     * estado del pago según lo acumulado, cancela la orden en una total y emite el
     * evento. Se llama con el pago bloqueado.
     */
    private function aplicarAprobado(PagoTenant $pago, ReembolsoTenant $reembolso, ?Usuario $actor): void
    {
        $reembolso->update(['estado' => EstadoReembolso::Aprobado->value, 'aplicado_en' => now()]);

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
            $orden->update([
                'estado' => EstadoOrden::Cancelada->value,
                'cancelada_en' => now(),
                'cancelada_por' => $actor?->getKey(),
            ]);
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
     * Por dónde se devuelve el dinero: `caja` (efectivo/manual/ventanilla),
     * `manual` (en línea, pero ya se devolvió por fuera) o `pasarela`. Si la pasarela
     * no puede devolver en línea, no se registra nada y se pide declararla manual.
     */
    private function via(PagoTenant $pago, bool $manual): string
    {
        if (! in_array($pago->proveedor, ProveedorPasarela::enLinea(), true)) {
            return 'caja';
        }
        if ($manual) {
            return 'manual';
        }

        $this->pasarelaDe($pago);

        return 'pasarela';
    }

    private function pasarelaDe(PagoTenant $pago): PasarelaReembolsable
    {
        $sinVia = 'Esta pasarela no puede devolver el pago en línea. Si ya devolviste el dinero por otro medio, regístralo como devolución manual.';
        try {
            $pasarela = $this->registro->resolver($pago->proveedor);
        } catch (PasarelaNoDisponible) {
            throw new PagoNoReembolsable($sinVia);
        }
        if (! $pasarela instanceof PasarelaReembolsable || $pago->referencia_externa === null || $pago->referencia_externa === '') {
            throw new PagoNoReembolsable($sinVia);
        }

        return $pasarela;
    }

    /**
     * Revierte a 0 el saldo de cada derecho intacto de la orden y cancela sus acuerdos.
     */
    private function revertirTotal(OrdenTenant $orden, PagoTenant $pago, ?Usuario $actor): void
    {
        $this->derechos->retirarTodo($orden, $this->contexto($pago, $actor), 'Reembolso total del pago', $actor);
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

        foreach ($this->derechos->acuerdosDe($orden) as $acuerdo) {
            foreach ($acuerdo->derechos as $derecho) {
                $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();
                if ($this->derechos->tuvoUso($bloqueado)) {
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
        if ($this->derechos->algunoUsado($orden)) {
            throw new DerechoYaUsado('El derecho ya tuvo uso; no se puede reembolsar el pago completo.');
        }
    }

    private function contexto(PagoTenant $pago, ?Usuario $actor): ContextoMovimiento
    {
        return ContextoMovimiento::para(OrigenMovimiento::Reembolso, 'pago', $pago->ulid, $actor);
    }
}
