<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones del ledger de creditos tenant-local con seguridad de concurrencia.
 * Cada mutacion bloquea el derecho/retencion (lockForUpdate) dentro de una
 * transaccion sobre la conexion del tenant, para evitar sobre-consumo concurrente
 * del ultimo cupo disponible (ver docs/BOOKING_ENGINE.md).
 */
class CreditosTenant
{
    public function __construct(private readonly LibroMayorTenant $libro) {}

    /**
     * Consume creditos asentando un movimiento negativo en el ledger.
     */
    public function consumir(DerechoTenant $derecho, int $unidades, ?string $descripcion = null, ?ContextoMovimiento $contexto = null): MovimientoCreditoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($derecho, $unidades, $descripcion, $contexto): MovimientoCreditoTenant {
            $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();

            if (! $bloqueado->ilimitado && $this->libro->disponible($bloqueado) < $unidades) {
                throw new SaldoInsuficiente('Saldo insuficiente para el consumo.');
            }

            return $this->libro->registrar($bloqueado, TipoMovimiento::Consumo, -$unidades, $descripcion, $contexto);
        });
    }

    /**
     * Retiene (hold) unidades sin consumirlas aun; reduce el disponible.
     */
    public function retener(DerechoTenant $derecho, int $unidades, ?string $descripcion = null): RetencionCreditoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($derecho, $unidades, $descripcion): RetencionCreditoTenant {
            $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();

            if (! $bloqueado->ilimitado && $this->libro->disponible($bloqueado) < $unidades) {
                throw new SaldoInsuficiente('Saldo insuficiente para la retencion.');
            }

            return $bloqueado->retenciones()->create([
                'unidades' => $unidades,
                'estado' => EstadoRetencion::Activa,
                'descripcion' => $descripcion,
            ]);
        });
    }

    /**
     * Confirma una retencion: asienta el consumo en el ledger y la marca consumida.
     */
    public function confirmar(RetencionCreditoTenant $retencion, ?ContextoMovimiento $contexto = null): void
    {
        DB::connection('tenant')->transaction(function () use ($retencion, $contexto): void {
            $bloqueada = RetencionCreditoTenant::query()->whereKey($retencion->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoRetencion::Activa) {
                return;
            }

            $this->libro->registrar($bloqueada->derecho, TipoMovimiento::Consumo, -$bloqueada->unidades, 'Confirmacion de retencion', $contexto);
            $bloqueada->update(['estado' => EstadoRetencion::Consumida]);
        });
    }

    /**
     * Libera una retencion activa: las unidades vuelven a estar disponibles. No toca
     * el ledger (nunca se consumieron).
     */
    public function liberar(RetencionCreditoTenant $retencion): void
    {
        if ($retencion->estado === EstadoRetencion::Activa) {
            $retencion->update(['estado' => EstadoRetencion::Liberada]);
        }
    }

    /**
     * Devuelve unidades ya consumidas (p. ej. al corregir una asistencia): asienta un
     * reverso positivo en el ledger, trazable a su origen.
     */
    public function devolver(DerechoTenant $derecho, int $unidades, ?string $descripcion = null, ?ContextoMovimiento $contexto = null): MovimientoCreditoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($derecho, $unidades, $descripcion, $contexto): MovimientoCreditoTenant {
            $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();

            return $this->libro->registrar($bloqueado, TipoMovimiento::Reverso, $unidades, $descripcion, $contexto);
        });
    }

    /**
     * Pierde (forfeit) una retencion: las unidades se consumen sin servicio (p. ej.
     * cancelacion tardia). Asienta el consumo y la marca perdida.
     */
    public function perder(RetencionCreditoTenant $retencion, ?ContextoMovimiento $contexto = null): void
    {
        DB::connection('tenant')->transaction(function () use ($retencion, $contexto): void {
            $bloqueada = RetencionCreditoTenant::query()->whereKey($retencion->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoRetencion::Activa) {
                return;
            }

            $this->libro->registrar($bloqueada->derecho, TipoMovimiento::Consumo, -$bloqueada->unidades, 'Retencion perdida (forfeit)', $contexto);
            $bloqueada->update(['estado' => EstadoRetencion::Perdida]);
        });
    }
}
