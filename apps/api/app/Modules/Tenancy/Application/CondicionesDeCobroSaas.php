<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\CondicionCobroSaas;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ModoCobroSaas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lo pactado con cada negocio, con fecha de vigencia (decisión del dueño, ADR 0181): el
 * superadmin dice desde qué mes aplica el cobro por uso o una cuota fija, y cada mes se
 * cobra con lo que regía a su cierre. Sin versiones rige lo de `estudios` (como antes).
 *
 * @phpstan-type Condicion array{modo_cobro: ModoCobroSaas, cuota_fija_minor: int, cuota_fija_moneda: string, desde: string|null}
 */
class CondicionesDeCobroSaas
{
    /** Antes de la primera versión pactada rige lo que el negocio ya tenía. */
    private const DESDE_SIEMPRE = '2000-01';

    /** Hasta cuántos meses adelante se puede pactar. */
    private const MESES_ADELANTE = 12;

    /**
     * Lo que rige en un mes (AAAA-MM).
     *
     * @return Condicion
     */
    public function en(Estudio $estudio, string $periodo): array
    {
        $version = CondicionCobroSaas::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('desde_periodo', '<=', $periodo)
            ->orderByDesc('desde_periodo')
            ->first();

        return $version instanceof CondicionCobroSaas
            ? self::deVersion($version)
            : [
                'modo_cobro' => $estudio->modo_cobro,
                'cuota_fija_minor' => (int) $estudio->cuota_fija_minor,
                'cuota_fija_moneda' => (string) ($estudio->cuota_fija_moneda ?: 'MXN'),
                'desde' => null,
            ];
    }

    /**
     * Lo que rige en un momento, en el mes de ese momento en la zona del negocio.
     *
     * @return Condicion
     */
    public function al(Estudio $estudio, ?CarbonInterface $momento = null): array
    {
        return $this->en($estudio, self::periodoDe($estudio, $momento));
    }

    /** ¿En ese momento el negocio tiene cuota fija pactada? */
    public function esFijo(Estudio $estudio, ?CarbonInterface $momento = null): bool
    {
        return $this->al($estudio, $momento)['modo_cobro'] === ModoCobroSaas::Fijo;
    }

    /**
     * Lo ya pactado para un mes posterior al de hoy (el siguiente cambio), o null.
     *
     * @return Condicion|null
     */
    public function proximo(Estudio $estudio): ?array
    {
        $version = CondicionCobroSaas::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('desde_periodo', '>', self::periodoDe($estudio))
            ->orderBy('desde_periodo')
            ->first();

        return $version instanceof CondicionCobroSaas ? self::deVersion($version) : null;
    }

    /**
     * El primer mes que todavía se puede cambiar: el siguiente al último cargo de renta
     * emitido (un cargo emitido no se recalcula), o el del alta.
     */
    public function primerPeriodoAbierto(Estudio $estudio): string
    {
        $ultimo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('clave', 'periodo')
            ->max('periodo');
        if (is_string($ultimo) && $ultimo !== '') {
            return CarbonImmutable::createFromFormat('Y-m-d', $ultimo.'-01')?->addMonthNoOverflow()->format('Y-m') ?? $ultimo;
        }

        return $estudio->created_at !== null
            ? CarbonImmutable::parse($estudio->created_at)->setTimezone(self::zona($estudio))->format('Y-m')
            : self::periodoDe($estudio);
    }

    /**
     * Pacta lo que rige desde un mes (por omisión, el primero que se puede cambiar).
     * Reemplaza lo pactado de ese mes en adelante; lo de antes no cambia. Las columnas
     * de `estudios` quedan con lo que rige hoy.
     */
    public function pactar(Estudio $estudio, ModoCobroSaas $modo, int $cuotaMinor, string $moneda, ?string $desde = null): void
    {
        $abierto = $this->primerPeriodoAbierto($estudio);
        $desde ??= $abierto;
        if ($desde < $abierto) {
            throw new DomainException("Hasta {$abierto} ya se cobró: lo pactado puede regir desde ese mes.");
        }
        $tope = CarbonImmutable::now(self::zona($estudio))->startOfMonth()->addMonthsNoOverflow(self::MESES_ADELANTE)->format('Y-m');
        if ($desde > $tope) {
            throw new DomainException('Lo pactado puede regir a lo más '.self::MESES_ADELANTE.' meses adelante.');
        }

        DB::transaction(function () use ($estudio, $modo, $cuotaMinor, $moneda, $desde): void {
            // Una sola edición a la vez por negocio.
            Estudio::query()->whereKey($estudio->getKey())->lockForUpdate()->first();
            $versiones = CondicionCobroSaas::query()->where('estudio_id', $estudio->getKey());
            if (! $versiones->clone()->exists()) {
                // Lo que ya tenía sigue rigiendo en los meses anteriores.
                CondicionCobroSaas::query()->create([
                    'estudio_id' => $estudio->getKey(),
                    'desde_periodo' => self::DESDE_SIEMPRE,
                    'modo_cobro' => $estudio->modo_cobro->value,
                    'cuota_fija_minor' => (int) $estudio->cuota_fija_minor,
                    'cuota_fija_moneda' => (string) ($estudio->cuota_fija_moneda ?: 'MXN'),
                ]);
            }
            $versiones->clone()->where('desde_periodo', '>=', $desde)->delete();
            CondicionCobroSaas::query()->create([
                'estudio_id' => $estudio->getKey(),
                'desde_periodo' => $desde,
                'modo_cobro' => $modo->value,
                'cuota_fija_minor' => $cuotaMinor,
                'cuota_fija_moneda' => $moneda,
            ]);

            $this->sincronizar($estudio);
        });
    }

    /**
     * Deja en `estudios` lo que rige hoy (lo leen los listados del superadmin). Corre a
     * diario con la renta: un cambio pactado para un mes siguiente aparece al llegar.
     */
    public function sincronizar(Estudio $estudio): void
    {
        $hoy = $this->al($estudio);
        $estudio->fill([
            'modo_cobro' => $hoy['modo_cobro']->value,
            'cuota_fija_minor' => $hoy['cuota_fija_minor'],
            'cuota_fija_moneda' => $hoy['cuota_fija_moneda'],
        ]);
        if ($estudio->isDirty()) {
            $estudio->save();
        }
    }

    /**
     * @return Condicion
     */
    private static function deVersion(CondicionCobroSaas $version): array
    {
        return [
            'modo_cobro' => $version->modo_cobro,
            'cuota_fija_minor' => (int) $version->cuota_fija_minor,
            'cuota_fija_moneda' => (string) $version->cuota_fija_moneda,
            'desde' => $version->desde_periodo === self::DESDE_SIEMPRE ? null : $version->desde_periodo,
        ];
    }

    private static function periodoDe(Estudio $estudio, ?CarbonInterface $momento = null): string
    {
        return CarbonImmutable::instance($momento ?? CarbonImmutable::now())->setTimezone(self::zona($estudio))->format('Y-m');
    }

    private static function zona(Estudio $estudio): string
    {
        return (string) ($estudio->zona_horaria ?: 'UTC');
    }
}
