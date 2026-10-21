<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\BeneficioRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Referidos entre negocios (ADR 0188): un negocio invita a otro con su código; cuando
 * el invitado paga su primer mes, los dos ganan un mes gratis (sin dinero en efectivo).
 *
 * - Con cobro mes vencido (clases), el mes gratis es el siguiente cargo de renta: se
 *   emite sin cargo y dice por qué.
 * - Con plan por adelantado (citas), se suma un mes a lo cubierto.
 *
 * Cada par se premia una sola vez; el código se crea la primera vez que se pide.
 */
class ReferidosSaas
{
    public const MES_GRATIS = 'mes_gratis';

    /** El código del negocio para invitar (se crea la primera vez). */
    public function codigo(Estudio $estudio): string
    {
        if ($estudio->codigo_referido !== null && $estudio->codigo_referido !== '') {
            return (string) $estudio->codigo_referido;
        }
        do {
            $codigo = Str::upper(Str::random(8));
        } while (Estudio::query()->where('codigo_referido', $codigo)->exists());
        $estudio->forceFill(['codigo_referido' => $codigo])->save();

        return $codigo;
    }

    /** Al registrarse con un código: queda quién lo invitó (no se autoinvita). */
    public function vincular(Estudio $nuevo, ?string $codigo): void
    {
        $codigo = Str::upper(trim((string) $codigo));
        if ($codigo === '' || $nuevo->referido_por_id !== null) {
            return;
        }
        $quien = Estudio::query()->where('codigo_referido', $codigo)->first();
        if ($quien instanceof Estudio && ! $quien->is($nuevo)) {
            $nuevo->forceFill(['referido_por_id' => $quien->getKey()])->save();
        }
    }

    /**
     * Un cargo de renta o plan se pagó: si es el primero del invitado, los dos ganan
     * su mes gratis.
     */
    public function alPagar(?CargoRenta $cargo): void
    {
        if (! $cargo instanceof CargoRenta || ! in_array($cargo->concepto, [null, 'renta', 'plan'], true) || $cargo->estado !== EstadoCargoRenta::Pagado) {
            return;
        }
        $invitado = $cargo->estudio;
        if (! $invitado instanceof Estudio || $invitado->referido_por_id === null) {
            return;
        }
        // Su primer pago de renta o de plan, lo avise quien lo avise (la pasarela, la
        // domiciliación o la conciliación) y aunque llegue después de otro: otorgar es
        // idempotente por anfitrión e invitado.
        $primero = CargoRenta::query()->where('estudio_id', $invitado->getKey())
            ->where('estado', EstadoCargoRenta::Pagado->value)
            ->where(fn ($q) => $q->whereNull('concepto')->orWhereIn('concepto', ['renta', 'plan']))
            ->orderBy('pagado_en')
            ->orderBy('id')
            ->first();
        if (! $primero instanceof CargoRenta || (int) $primero->getKey() !== (int) $cargo->getKey()) {
            return;
        }
        $anfitrion = Estudio::query()->find($invitado->referido_por_id);
        if (! $anfitrion instanceof Estudio) {
            return;
        }

        DB::transaction(function () use ($invitado, $anfitrion): void {
            $this->otorgar($invitado, $anfitrion, "Mes gratis por llegar invitado por {$anfitrion->nombre}");
            $this->otorgar($anfitrion, $invitado, "Mes gratis por invitar a {$invitado->nombre}");
        });
    }

    /**
     * Un cargo de renta recién emitido: si el negocio tiene un mes gratis pendiente, el
     * cargo queda sin cargo y dice por qué.
     */
    public function aplicarAlCargo(CargoRenta $cargo): void
    {
        if ($cargo->estado !== EstadoCargoRenta::Pendiente || ! in_array($cargo->concepto, [null, 'renta'], true)) {
            return;
        }
        DB::transaction(function () use ($cargo): void {
            $beneficio = BeneficioRenta::query()
                ->where('estudio_id', $cargo->estudio_id)
                ->where('tipo', self::MES_GRATIS)
                ->whereNull('aplicado_en')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $beneficio instanceof BeneficioRenta) {
                return;
            }
            $desglose = is_array($cargo->desglose) ? $cargo->desglose : [];
            $desglose['beneficio'] = ['tipo' => self::MES_GRATIS, 'motivo' => $beneficio->motivo];
            $cargo->update(['estado' => EstadoCargoRenta::SinCargo->value, 'desglose' => $desglose]);
            $beneficio->update(['aplicado_en' => now(), 'cargo_id' => $cargo->getKey()]);
        });
    }

    /**
     * @return list<array{motivo: string, aplicado: bool, fecha: string|null}>
     */
    public function beneficios(Estudio $estudio): array
    {
        return BeneficioRenta::query()->where('estudio_id', $estudio->getKey())->orderByDesc('id')->limit(20)->get()
            ->map(static fn (BeneficioRenta $b): array => [
                'motivo' => $b->motivo,
                'aplicado' => $b->aplicado_en !== null,
                'fecha' => $b->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function otorgar(Estudio $estudio, Estudio $origen, string $motivo): void
    {
        $beneficio = BeneficioRenta::query()->firstOrCreate(
            ['estudio_id' => $estudio->getKey(), 'tipo' => self::MES_GRATIS, 'origen_estudio_id' => $origen->getKey()],
            ['motivo' => mb_substr($motivo, 0, 200)],
        );
        if (! $beneficio->wasRecentlyCreated) {
            return;
        }
        // Con plan por adelantado (citas): un mes más cubierto, ya.
        if ($estudio->plan_cubierto_hasta !== null) {
            self::unMesMas($estudio);
            $beneficio->update(['aplicado_en' => now()]);
        }
    }

    /**
     * Un plan por adelantado recién cobrado (citas): si tenía un mes gratis pendiente
     * (se otorgó cuando aún no tenía periodo cubierto, p. ej. en la prueba), se le
     * suma ahora.
     */
    public function aplicarAlPlan(Estudio $estudio): void
    {
        if ($estudio->plan_cubierto_hasta === null) {
            return;
        }
        DB::transaction(function () use ($estudio): void {
            $beneficio = BeneficioRenta::query()
                ->where('estudio_id', $estudio->getKey())
                ->where('tipo', self::MES_GRATIS)
                ->whereNull('aplicado_en')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $beneficio instanceof BeneficioRenta) {
                return;
            }
            self::unMesMas($estudio);
            $beneficio->update(['aplicado_en' => now()]);
        });
    }

    /**
     * Un mes más de plan cubierto: en mensual hasta el fin del mes siguiente (cubre ese
     * mes completo, sin un cargo de uno o tres días); en anual, al mismo día un mes después.
     */
    private static function unMesMas(Estudio $estudio): void
    {
        $hasta = CarbonImmutable::parse((string) $estudio->plan_cubierto_hasta?->toDateString());
        $nuevo = $estudio->plan_periodicidad === 'anual'
            ? $hasta->addMonthNoOverflow()
            : $hasta->startOfMonth()->addMonthNoOverflow()->endOfMonth()->startOfDay();
        $estudio->forceFill(['plan_cubierto_hasta' => $nuevo->toDateString()])->save();
    }
}
