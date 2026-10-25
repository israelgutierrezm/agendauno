<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\Exceptions\DerechoYaUsado;
use App\Modules\Tenancy\Pagos\Exceptions\PagoNoReembolsable;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Pasarelas\PasarelaReembolsable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
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
 * Serializa con `lockForUpdate` sobre su orden y el pago, en ese orden (y sobre cada
 * derecho al revertir).
 */
class ReembolsarPagoTenant
{
    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly RegistrarEventoTenant $eventos,
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly DerechosDeOrdenTenant $derechos,
        private readonly InventarioTenant $inventario,
        private readonly CajasTenant $cajas,
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
        ?string $metodo = null,
    ): ReembolsoTenant {
        // 1) Se registra la intención (y en caja o manual, se aplica) antes de hablar
        // con la pasarela: si algo falla después, la devolución queda rastreable.
        [$reembolso, $nuevo] = DB::connection('tenant')->transaction(function () use ($pago, $montoMinor, $motivo, $actor, $revertirCreditos, $manual, $llave, $metodo): array {
            $bloqueado = $this->bloquearOrdenYPago((int) $pago->getKey());

            if ($llave !== null) {
                $repetido = $bloqueado->reembolsos()->where('llave', $llave)->first();
                if ($repetido instanceof ReembolsoTenant) {
                    // La misma llave es la misma devolución: si lo pedido es otra cosa
                    // (monto, «ya lo devolví», forma, revertir) o la anterior falló, no se
                    // responde como hecha: se pide otra vez, con otra llave.
                    $igual = ($montoMinor === null || $montoMinor === (int) $repetido->monto_minor)
                        && $manual === (($repetido->metadata['via'] ?? null) === 'manual')
                        && $revertirCreditos === (bool) ($repetido->metadata['revertir_pedido'] ?? $repetido->metadata['revertir_creditos'] ?? true)
                        && ($metodo === null || $repetido->metodo === null || $metodo === $repetido->metodo);
                    if (! $igual) {
                        throw new PagoNoReembolsable('Esa llave ya se usó para otra devolución: vuelve a intentarlo.');
                    }
                    if ($repetido->estado === EstadoReembolso::Fallido) {
                        throw new PagoNoReembolsable('Esa devolución falló'.($repetido->motivo_fallo ? ': '.$repetido->motivo_fallo : '').'. Vuelve a intentarlo.');
                    }

                    return [$repetido, false];
                }
            }

            if (! in_array($bloqueado->estado, [EstadoPago::Aprobado, EstadoPago::ParcialmenteReembolsado], true)) {
                throw new PagoNoReembolsable('Solo se puede reembolsar un pago aprobado.');
            }
            // Con un contracargo abierto, si se pierde el banco ya le devuelve el dinero al
            // cliente: devolverlo también sería pagarlo dos veces.
            if ($bloqueado->contracargo_estado === ContracargosTenant::ABIERTO) {
                throw new PagoNoReembolsable('Este pago tiene un contracargo abierto: espera a que se resuelva. Si se pierde, el banco le devuelve el dinero al cliente.');
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

            // Un cobro mixto (ADR 0177) se devuelve por una de sus formas, sin pasar de lo
            // que entró por ella.
            $salePor = $this->formaDeLaDevolucion($bloqueado, $monto, $metodo);

            $esTotal = $monto === $bloqueado->monto_minor && $comprometido === 0;
            $orden = $bloqueado->orden;
            $pedido = $revertirCreditos;
            // Solo dinero: en un cobro doble la venta la pagó otro cobro (la venta, sus
            // derechos y su inventario siguen); en una renovación, el periodo ya corre y
            // no se quita solo (para eso se pausa o se da de baja la membresía).
            if ($orden !== null && ($orden->renueva_acuerdo_id !== null
                || PagoTenant::query()->where('orden_id', $orden->getKey())->whereKeyNot($bloqueado->getKey())
                    ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value])
                    ->exists())) {
                $revertirCreditos = false;
            }

            // Devolución total: se rechaza si algún derecho de la orden ya tuvo uso
            // (antes de tocar la pasarela: no se devuelve dinero si luego no podríamos
            // revocar el entitlement).
            if ($esTotal && $revertirCreditos && $orden !== null) {
                $this->exigirDerechosIntactos($orden);
            }
            // La que deja el pago devuelto por completo cancela la venta: si es de una cita
            // (o de una clase de pago suelto) que sigue en pie, primero se cancela la cita.
            if ($monto === $restante && $revertirCreditos && $orden !== null) {
                $this->exigirCitaCancelada($orden);
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
                'metodo' => $bloqueado->metodo === MetodoPago::Mixto ? $salePor : null,
                'actor_id' => $actor?->getKey(),
                'actor_nombre' => $actor?->name,
                'metadata' => [
                    'parcial' => ! $esTotal,
                    'total' => $esTotal,
                    'via' => $via,
                    'revertir_creditos' => $revertirCreditos,
                    'revertir_pedido' => $pedido,
                ],
            ]);

            // Queda en la bitácora del negocio quién devolvió cuánto y por qué (M07).
            app(RegistrarAuditoria::class)->registrar($actor, 'pago.reembolso_solicitado', 'pago', (string) $bloqueado->ulid, null, [
                'reembolso' => $reembolso->ulid, 'monto_minor' => $monto, 'moneda' => $bloqueado->moneda,
                'via' => $via, 'total' => $esTotal, 'revertir_creditos' => $revertirCreditos,
            ], $motivo);

            // En caja, el dinero sale de la caja abierta de la sede del cobro (ADR 0175),
            // bloqueada antes del inventario que regresa una devolución total.
            $turno = $via === 'caja' ? $this->cajas->turnoParaMovimiento($this->cajas->sucursalDelCobro($bloqueado)) : null;

            // En caja o declarada manual el dinero ya se devolvió: se aplica ahora.
            if ($via !== 'pasarela') {
                $this->aplicarAprobado($bloqueado, $reembolso, $actor);
            }
            $this->cajas->asentar($turno, MovimientoCajaTenant::DEVOLUCION, $salePor, -$monto,
                ['pago_id' => $bloqueado->getKey(), 'reembolso_id' => $reembolso->getKey()], $actor, $motivo);

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
     * Por qué forma de la caja sale el dinero. Un pago normal, por la suya. Uno mixto,
     * por la forma elegida, que debe ser una de sus partes y alcanzar con lo que entró
     * por ella menos lo ya devuelto por ella.
     */
    private function formaDeLaDevolucion(PagoTenant $pago, int $monto, ?string $metodo): string
    {
        if ($pago->metodo !== MetodoPago::Mixto) {
            return CajasTenant::metodoDelPago($pago);
        }
        $partes = CajasTenant::partesDelPago($pago);
        $parte = collect($partes)->firstWhere('metodo', $metodo);
        if ($metodo === null || $parte === null) {
            throw new PagoNoReembolsable('Se cobró con varias formas de pago: elige por cuál de ellas sale la devolución.');
        }
        $devuelto = (int) $pago->reembolsos()
            ->whereIn('estado', EstadoReembolso::comprometidos())
            ->where('metodo', $metodo)
            ->sum('monto_minor');
        if ($monto > $parte['monto_minor'] - $devuelto) {
            throw new PagoNoReembolsable('Por esa forma de pago no entró tanto: devuelve hasta lo que se cobró con ella.');
        }

        return $metodo;
    }

    /**
     * Bloquea la orden del pago y luego el pago, en ese orden, como cobrar, confirmar,
     * anular y corregir (ADR 0181). Una devolución total termina cancelando la orden: si
     * bloqueara primero el pago, se trabaría con una anulación del mismo cobro (que tiene
     * la orden y espera el pago) y MySQL cortaría una de las dos.
     */
    private function bloquearOrdenYPago(int $pagoId): PagoTenant
    {
        $ordenId = PagoTenant::query()->whereKey($pagoId)->value('orden_id');
        if ($ordenId !== null) {
            OrdenTenant::query()->whereKey($ordenId)->lockForUpdate()->first();
        }

        return PagoTenant::query()->whereKey($pagoId)->lockForUpdate()->firstOrFail();
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
                // Su respuesta técnica va al registro de errores, no a la pantalla (M07).
                ReporteDeErrores::reportarAtrapado($e);
                $motivo = 'La pasarela no aceptó la devolución ('.$e->response->status().').';
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
    public function conciliar(string $referencia, bool $exitosa, ?string $llave = null): ?ReembolsoTenant
    {
        if ($referencia === '') {
            return null;
        }

        // Por su referencia o, si el aviso llegó antes de guardarla, por la llave con que
        // se pidió (Stripe la devuelve en la metadata: `reembolso_<ulid>`).
        $reembolso = ReembolsoTenant::query()->where('referencia_externa', $referencia)->first()
            ?? ($llave !== null && str_starts_with($llave, 'reembolso_')
                ? ReembolsoTenant::query()->where('ulid', substr($llave, strlen('reembolso_')))->first()
                : null);
        if (! $reembolso instanceof ReembolsoTenant) {
            return null;
        }

        // Falló después de confirmarse (Stripe lo permite): no se deshace nada solo, pero
        // el cliente no recibió su dinero y alguien tiene que resolverlo.
        if (! $exitosa && $reembolso->estado === EstadoReembolso::Aprobado) {
            $this->incidencias->porReembolso(
                IncidenciaCobroTenant::REEMBOLSO_INCIERTO,
                $reembolso,
                'La pasarela avisó que la devolución falló después de confirmarla: el cliente no recibió su dinero. Devuélvelo por otro medio y anota aquí qué hiciste.',
            );

            return $reembolso;
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
            $pago = $this->bloquearOrdenYPago((int) $reembolso->pago_id);
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
        $acumulado = (int) $pago->reembolsos()->where('estado', EstadoReembolso::Aprobado->value)->sum('monto_minor');

        // Devuelto todo en partes, y con lo que se compró sin usar: es una devolución
        // total (se retira el plan y se cancela la venta), no un saldo que nunca se acaba.
        if (! $esTotal && $revertir && $orden !== null && $acumulado >= $pago->monto_minor && ! $this->derechos->algunoUsado($orden)) {
            $esTotal = true;
        }

        if ($revertir && $orden !== null) {
            if ($esTotal) {
                $this->revertirTotal($orden, $pago, $reembolso, $actor);
            } else {
                $this->revertirProporcional($orden, $pago, $actor);
            }
            $reembolso->update(['revirtio_creditos' => true]);
        }

        $pago->update([
            'estado' => $acumulado >= $pago->monto_minor
                ? EstadoPago::Reembolsado->value
                : EstadoPago::ParcialmenteReembolsado->value,
        ]);

        if ($esTotal && $revertir && $orden !== null) {
            // Los artículos que salieron con el cobro vuelven a su sede (ADR 0166).
            $this->inventario->devolverDeOrden($orden, 'Devolución '.$orden->ulid, $actor);
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
    private function revertirTotal(OrdenTenant $orden, PagoTenant $pago, ReembolsoTenant $reembolso, ?Usuario $actor): void
    {
        $huboUso = $this->derechos->retirarTodo($orden, $this->contexto($pago, $actor), 'Reembolso total del pago', $actor);

        // Una devolución en línea se confirma después: si mientras tanto el plan se usó,
        // el dinero ya salió. Se retira lo libre, lo apartado se respeta y alguien lo
        // revisa con el cliente.
        if ($huboUso) {
            $this->incidencias->porReembolso(
                IncidenciaCobroTenant::REEMBOLSO_INCIERTO,
                $reembolso,
                'La devolución se confirmó cuando el plan ya se había usado: se retiró lo que quedaba libre y se respetó lo ya reservado. Revisa con el cliente lo que usó.',
            );
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
        // Solo cuentan las devoluciones que revirtieron: una compensación (solo dinero) o
        // un contracargo no hacen que la siguiente revierta de más.
        $devuelto = min($total, (int) $pago->reembolsos()
            ->where('estado', EstadoReembolso::Aprobado->value)
            ->get()
            ->filter(fn (ReembolsoTenant $r): bool => (bool) ($r->metadata['revertir_creditos'] ?? false))
            ->sum('monto_minor'));

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

    /**
     * Devolver todo lo cobrado de una cita cancela su venta: si la cita sigue confirmada
     * (o apartada) y no ha empezado, quedaría ocupando el horario sin cobro y al llegar
     * no se podría cobrar. Se cancela primero la cita (con su política, que decide qué se
     * devuelve) y después el pago. Una cita que ya pasó o ya se canceló no lo impide.
     */
    private function exigirCitaCancelada(OrdenTenant $orden): void
    {
        $vigente = ReservaTenant::query()
            ->where(fn ($q) => $q->where('orden_id', $orden->getKey())->orWhere('saldo_orden_id', $orden->getKey()))
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now()))
            ->exists();
        if ($vigente) {
            throw new PagoNoReembolsable(app(ModalidadNegocioTenant::class)->esCitas()
                ? 'Cancela la cita primero; después devuelve el pago.'
                : 'Cancela la reserva de la clase primero; después devuelve el pago.');
        }
    }

    private function contexto(PagoTenant $pago, ?Usuario $actor): ContextoMovimiento
    {
        return ContextoMovimiento::para(OrigenMovimiento::Reembolso, 'pago', $pago->ulid, $actor);
    }
}
