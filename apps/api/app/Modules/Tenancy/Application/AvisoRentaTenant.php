<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;

/**
 * El aviso de la renta en el panel de quien ve la facturación (ADR 0073 y 0152): el
 * pago pendiente más antiguo, si ya venció y cuándo se suspendería el negocio. Los
 * correos ya avisan (ADR 0071); esto lo deja a la vista mientras se trabaja, sin
 * esperar a que el negocio ya esté suspendido.
 *
 * Niveles, del más urgente al más leve:
 * - `por_suspender`: vencido y dentro de los días de aviso antes de la suspensión
 *   (`renta.dias_aviso_suspension`).
 * - `vencido`: pasó la fecha para pagar.
 * - `rechazado`: aún no vence, pero el banco rechazó el cobro a la tarjeta.
 * - `por_vencer`: vence en los próximos días (`renta.dias_recordatorio_pago`) y no hay
 *   tarjeta que lo pague sola.
 *
 * Sin nada de eso, no hay aviso. Una compra de timbres no vence (se paga al comprarla),
 * así que nunca lo dispara.
 */
class AvisoRentaTenant
{
    public function __construct(
        private readonly SuspensionPorRenta $suspension,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @return array{nivel: string, cargo_id: string, periodo: string, concepto: string, monto_minor: int, moneda: string, vence_en: string, suspende_en: ?string, dias_para_suspension: ?int, rechazado: bool, proximo_intento_en: ?string, con_tarjeta: bool}|null
     */
    public function de(Estudio $estudio): ?array
    {
        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->whereNotNull('vence_en')
            ->orderBy('vence_en')
            ->orderBy('id')
            ->first();
        if (! $cargo instanceof CargoRenta || $cargo->vence_en === null) {
            return null;
        }

        // El mismo «hoy» con que se suspende (SuspensionPorRenta).
        $hoy = CarbonImmutable::today();
        $vence = CarbonImmutable::instance($cargo->vence_en)->startOfDay();
        $suspende = $this->suspension->fechaDeSuspension($cargo);
        $conTarjeta = DomiciliacionRenta::tarjeta($estudio) !== null;
        $rechazado = (string) $cargo->error_cobro !== '';
        $recordatorio = $this->parametros->entero('renta.dias_recordatorio_pago');

        $nivel = match (true) {
            $hoy->gt($vence) && $suspende !== null
                && $hoy->gte($suspende->subDays($this->parametros->entero('renta.dias_aviso_suspension'))) => 'por_suspender',
            $hoy->gt($vence) => 'vencido',
            $rechazado => 'rechazado',
            ! $conTarjeta && $recordatorio > 0 && $hoy->gte($vence->subDays($recordatorio)) => 'por_vencer',
            default => null,
        };
        if ($nivel === null) {
            return null;
        }

        return [
            'nivel' => $nivel,
            'cargo_id' => (string) $cargo->ulid,
            'periodo' => (string) $cargo->periodo,
            'concepto' => (string) ($cargo->concepto ?? 'renta'),
            'monto_minor' => $cargo->monto_minor,
            'moneda' => (string) $cargo->moneda,
            'vence_en' => $vence->toDateString(),
            'suspende_en' => $suspende?->toDateString(),
            'dias_para_suspension' => $suspende !== null ? max(0, (int) $hoy->diffInDays($suspende)) : null,
            'rechazado' => $rechazado,
            'proximo_intento_en' => $cargo->proximo_intento_en?->toIso8601String(),
            'con_tarjeta' => $conTarjeta,
        ];
    }
}
