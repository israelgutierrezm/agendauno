<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contracargos (ADR 0168): el banco del cliente disputa un pago en línea. Lo avisa la
 * pasarela por su webhook (Stripe, Mercado Pago u OpenPay); aquí se registra en el pago
 * y se lleva a «por conciliar» para que el negocio responda en la pasarela a tiempo.
 *
 * - **Abierto:** el pago lo dice y se abre su incidencia (el superadmin también se
 *   entera). Nada cambia todavía en el dinero.
 * - **Ganado:** el dinero se queda; la incidencia se cierra sola.
 * - **Perdido:** la pasarela devolvió el dinero al cliente: cuenta como una devolución
 *   (corte, movimientos y reportes) y el pago queda devuelto. Lo que compró no se
 *   cancela solo: la incidencia sigue abierta para que el negocio decida. Si el pago ya
 *   estaba devuelto por completo, no se registra otra devolución (el dinero no salió
 *   dos veces de los libros): la incidencia dice que el cliente lo recibió dos veces.
 *
 * Idempotente: el mismo aviso dos veces no duplica nada.
 *
 * El estado del pago, su incidencia y la bitácora se guardan en una sola transacción:
 * quedan juntos o no queda nada. Si algo falla a medias, el aviso de la pasarela sigue
 * pendiente y su reintento (ADR 0173) hace todo de nuevo; nunca queda el estado guardado
 * sin su incidencia, que un reintento ya no crearía. El aviso al superadmin sale cuando
 * ya quedó guardado y, si no sale, se reintenta ({@see IncidenciasCobroTenant}).
 */
class ContracargosTenant
{
    public const ABIERTO = 'abierto';

    public const GANADO = 'ganado';

    public const PERDIDO = 'perdido';

    public function __construct(
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function abierto(PagoTenant $pago, string $referencia, int $montoMinor, ?string $motivo = null, ?CarbonImmutable $venceEn = null): void
    {
        DB::connection('tenant')->transaction(function () use ($pago, $referencia, $montoMinor, $motivo, $venceEn): void {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            // Ya cerrado (ganado o perdido) o ya registrado: un aviso tardío no lo reabre.
            if ($bloqueado->contracargo_referencia === $referencia && $bloqueado->contracargo_estado !== null) {
                return;
            }
            $bloqueado->update([
                'contracargo_estado' => self::ABIERTO,
                'contracargo_referencia' => $referencia,
                'contracargo_monto_minor' => $montoMinor > 0 ? $montoMinor : $bloqueado->monto_minor,
                'contracargo_motivo' => $motivo !== null ? mb_substr($motivo, 0, 120) : null,
                'contracargo_vence_en' => $venceEn,
                'contracargo_en' => now(),
                'contracargo_cerrado_en' => null,
            ]);

            $monto = self::dinero((int) $bloqueado->contracargo_monto_minor, (string) $bloqueado->moneda);
            $plazo = $bloqueado->contracargo_vence_en !== null ? ' antes del '.$bloqueado->contracargo_vence_en->format('Y-m-d') : '';
            $this->incidencias->porPago(
                IncidenciaCobroTenant::CONTRACARGO,
                $bloqueado,
                "El banco del cliente disputa el pago de {$monto}".($motivo !== null ? " ({$motivo})" : '')
                    .". Responde en {$this->pasarela($bloqueado)}{$plazo} con lo que pruebe que se dio el servicio.",
                ['referencia' => $referencia, 'vence_en' => $bloqueado->contracargo_vence_en?->toIso8601String()],
            );
            $this->auditoria->registrar(null, 'pago.contracargo', 'pago', (string) $bloqueado->ulid, null, [
                'estado' => self::ABIERTO,
                'monto_minor' => (int) $bloqueado->contracargo_monto_minor,
                'moneda' => $bloqueado->moneda,
                'motivo' => $motivo,
                'referencia' => $referencia,
            ]);
        });
        $pago->refresh();
    }

    /**
     * Se cerró: ganado (el dinero se queda) o perdido (se devolvió al cliente).
     */
    public function cerrado(PagoTenant $pago, string $referencia, bool $ganado, int $montoMinor = 0): void
    {
        DB::connection('tenant')->transaction(function () use ($pago, $referencia, $ganado, $montoMinor): void {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->contracargo_referencia === $referencia
                && in_array($bloqueado->contracargo_estado, [self::GANADO, self::PERDIDO], true)) {
                return;
            }
            $antes = $bloqueado->contracargo_estado;
            $monto = $montoMinor > 0 ? $montoMinor : (int) ($bloqueado->contracargo_monto_minor ?? $bloqueado->monto_minor);
            $bloqueado->update([
                'contracargo_estado' => $ganado ? self::GANADO : self::PERDIDO,
                'contracargo_referencia' => $referencia,
                'contracargo_monto_minor' => $monto,
                'contracargo_en' => $bloqueado->contracargo_en ?? now(),
                'contracargo_cerrado_en' => now(),
            ]);
            $yaDevuelto = ! $ganado && ! $this->devolucion($bloqueado, $referencia, $monto);

            $dinero = self::dinero($monto, (string) $bloqueado->moneda);
            $abiertas = IncidenciaCobroTenant::query()
                ->where('tipo', IncidenciaCobroTenant::CONTRACARGO)
                ->where('pago_id', $bloqueado->getKey())
                ->where('estado', IncidenciaCobroTenant::ABIERTA)
                ->get();
            if ($ganado) {
                $abiertas->each(fn (IncidenciaCobroTenant $i) => $this->incidencias->cerrar($i, "Se ganó el contracargo: el pago de {$dinero} se queda."));
            } else {
                // Perdido sin aviso de apertura (llegó desordenado o no llegó): se abre aquí.
                // Sobre un pago ya devuelto por completo, el cliente recibió el dinero dos
                // veces: no se registra otra devolución, se le dice al negocio.
                $detalle = $yaDevuelto
                    ? "Se perdió el contracargo de {$dinero}, pero el pago ya se había devuelto por completo: el cliente recibió su dinero dos veces. Recupéralo con el cliente o reclámalo en {$this->pasarela($bloqueado)}."
                    : "Se perdió el contracargo: {$this->pasarela($bloqueado)} devolvió {$dinero} al cliente. Decide si cancelas lo que compró.";
                $abiertas->isEmpty()
                    ? $this->incidencias->porPago(IncidenciaCobroTenant::CONTRACARGO, $bloqueado, $detalle, ['referencia' => $referencia])
                    : $abiertas->each(fn (IncidenciaCobroTenant $i) => $i->update(['detalle' => $detalle]));
            }
            $this->auditoria->registrar(null, 'pago.contracargo', 'pago', (string) $bloqueado->ulid, ['estado' => $antes], [
                'estado' => $ganado ? self::GANADO : self::PERDIDO,
                'monto_minor' => $monto,
                'moneda' => $bloqueado->moneda,
                'referencia' => $referencia,
            ]);
        });
        $pago->refresh();
    }

    /**
     * Lo perdido sale como devolución (sin revertir créditos: lo decide el negocio) y
     * el pago queda devuelto, total o parcialmente. Una sola por disputa, y nunca por
     * más de lo que quedaba sin devolver: si el pago ya estaba devuelto por completo, no
     * se registra ninguna (devuelve false; el negocio lo ve en su incidencia).
     */
    private function devolucion(PagoTenant $pago, string $referencia, int $montoMinor): bool
    {
        $existe = ReembolsoTenant::query()->where('pago_id', $pago->getKey())->where('referencia_externa', $referencia)->exists();
        $devueltoAntes = (int) $pago->reembolsos()->whereIn('estado', EstadoReembolso::comprometidos())->sum('monto_minor');
        $restante = (int) $pago->monto_minor - $devueltoAntes;
        if (! $existe && $restante <= 0) {
            return false;
        }
        if (! $existe) {
            ReembolsoTenant::query()->create([
                'pago_id' => $pago->getKey(),
                'monto_minor' => min($montoMinor, $restante),
                'moneda' => $pago->moneda,
                'estado' => EstadoReembolso::Aprobado->value,
                'proveedor' => $pago->proveedor,
                'motivo' => 'Contracargo perdido: el banco devolvió el pago al cliente.',
                'revirtio_creditos' => false,
                'referencia_externa' => $referencia,
                'actor_nombre' => 'Contracargo',
                'metadata' => ['contracargo' => true],
                'aplicado_en' => now(),
            ]);
        }
        $devuelto = (int) $pago->reembolsos()->where('estado', EstadoReembolso::Aprobado->value)->sum('monto_minor');
        $pago->update([
            'estado' => $devuelto >= $pago->monto_minor ? EstadoPago::Reembolsado->value : EstadoPago::ParcialmenteReembolsado->value,
        ]);

        return true;
    }

    private function pasarela(PagoTenant $pago): string
    {
        return match ((string) $pago->proveedor) {
            'stripe' => 'Stripe',
            'mercadopago' => 'Mercado Pago',
            'openpay' => 'OpenPay',
            default => 'la pasarela',
        };
    }

    private static function dinero(int $minor, string $moneda): string
    {
        return '$'.number_format($minor / 100, 2, '.', ',').' '.strtoupper($moneda);
    }
}
