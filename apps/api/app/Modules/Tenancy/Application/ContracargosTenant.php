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
 *   cancela solo: la incidencia sigue abierta para que el negocio decida.
 *
 * Idempotente: el mismo aviso dos veces no duplica nada.
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
        $abierto = DB::connection('tenant')->transaction(function () use ($pago, $referencia, $montoMinor, $motivo, $venceEn): bool {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            // Ya cerrado (ganado o perdido) o ya registrado: un aviso tardío no lo reabre.
            if ($bloqueado->contracargo_referencia === $referencia && $bloqueado->contracargo_estado !== null) {
                return false;
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

            return true;
        });
        if (! $abierto) {
            return;
        }
        $pago->refresh();

        $monto = self::dinero((int) $pago->contracargo_monto_minor, (string) $pago->moneda);
        $plazo = $pago->contracargo_vence_en !== null ? ' antes del '.$pago->contracargo_vence_en->format('Y-m-d') : '';
        $this->incidencias->porPago(
            IncidenciaCobroTenant::CONTRACARGO,
            $pago,
            "El banco del cliente disputa el pago de {$monto}".($motivo !== null ? " ({$motivo})" : '')
                .". Responde en {$this->pasarela($pago)}{$plazo} con lo que pruebe que se dio el servicio.",
            ['referencia' => $referencia, 'vence_en' => $pago->contracargo_vence_en?->toIso8601String()],
        );
        $this->auditoria->registrar(null, 'pago.contracargo', 'pago', (string) $pago->ulid, null, [
            'estado' => self::ABIERTO,
            'monto_minor' => (int) $pago->contracargo_monto_minor,
            'moneda' => $pago->moneda,
            'motivo' => $motivo,
            'referencia' => $referencia,
        ]);
    }

    /**
     * Se cerró: ganado (el dinero se queda) o perdido (se devolvió al cliente).
     */
    public function cerrado(PagoTenant $pago, string $referencia, bool $ganado, int $montoMinor = 0): void
    {
        $cerrado = DB::connection('tenant')->transaction(function () use ($pago, $referencia, $ganado, $montoMinor): bool {
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->contracargo_referencia === $referencia
                && in_array($bloqueado->contracargo_estado, [self::GANADO, self::PERDIDO], true)) {
                return false;
            }
            $monto = $montoMinor > 0 ? $montoMinor : (int) ($bloqueado->contracargo_monto_minor ?? $bloqueado->monto_minor);
            $bloqueado->update([
                'contracargo_estado' => $ganado ? self::GANADO : self::PERDIDO,
                'contracargo_referencia' => $referencia,
                'contracargo_monto_minor' => $monto,
                'contracargo_en' => $bloqueado->contracargo_en ?? now(),
                'contracargo_cerrado_en' => now(),
            ]);
            if (! $ganado) {
                $this->devolucion($bloqueado, $referencia, $monto);
            }

            return true;
        });
        if (! $cerrado) {
            return;
        }
        $pago->refresh();

        $monto = self::dinero((int) $pago->contracargo_monto_minor, (string) $pago->moneda);
        $abiertas = IncidenciaCobroTenant::query()
            ->where('tipo', IncidenciaCobroTenant::CONTRACARGO)
            ->where('pago_id', $pago->getKey())
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->get();
        if ($ganado) {
            $abiertas->each(fn (IncidenciaCobroTenant $i) => $this->incidencias->cerrar($i, "Se ganó el contracargo: el pago de {$monto} se queda."));
        } else {
            $detalle = "Se perdió el contracargo: {$this->pasarela($pago)} devolvió {$monto} al cliente. Decide si cancelas lo que compró.";
            $abiertas->isEmpty()
                ? $this->incidencias->porPago(IncidenciaCobroTenant::CONTRACARGO, $pago, $detalle, ['referencia' => $referencia])
                : $abiertas->each(fn (IncidenciaCobroTenant $i) => $i->update(['detalle' => $detalle]));
        }
        $this->auditoria->registrar(null, 'pago.contracargo', 'pago', (string) $pago->ulid, ['estado' => self::ABIERTO], [
            'estado' => $ganado ? self::GANADO : self::PERDIDO,
            'monto_minor' => (int) $pago->contracargo_monto_minor,
            'moneda' => $pago->moneda,
            'referencia' => $referencia,
        ]);
    }

    /**
     * Lo perdido sale como devolución (sin revertir créditos: lo decide el negocio) y
     * el pago queda devuelto, total o parcialmente. Una sola por disputa.
     */
    private function devolucion(PagoTenant $pago, string $referencia, int $montoMinor): void
    {
        $existe = ReembolsoTenant::query()->where('pago_id', $pago->getKey())->where('referencia_externa', $referencia)->exists();
        if (! $existe) {
            ReembolsoTenant::query()->create([
                'pago_id' => $pago->getKey(),
                'monto_minor' => $montoMinor,
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
