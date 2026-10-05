<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La política única de fechas del negocio: las fechas sin hora (vigencia de un plan,
 * fin de un ciclo, una pausa, el próximo cobro) son DÍAS DEL NEGOCIO, en su zona
 * horaria, no en la del servidor (UTC). Un plan válido hasta el 30 cubre todo el 30
 * local (hasta las 23:59:59) y deja de cubrir el 1 a las 00:00 local; uno válido desde
 * el 1 empieza a las 00:00 del 1 local.
 *
 * Todo lo que compara una de esas fechas con «hoy» o con un instante (reservar, el
 * estado de un plan, renovar ciclos, avisos de vencimiento, pausas, cobros
 * automáticos) pasa por aquí, para que nada dependa de la hora del día.
 */
final class FechasNegocioTenant
{
    private const ZONA_POR_OMISION = 'America/Mexico_City';

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /** Zona horaria del negocio en curso. */
    public function zona(): string
    {
        return (string) ($this->gestor->actual()?->zona_horaria ?: self::ZONA_POR_OMISION);
    }

    /** El día (AAAA-MM-DD) que es en el negocio en ese instante (por omisión, ahora). */
    public function fecha(?CarbonInterface $momento = null): string
    {
        return CarbonImmutable::instance($momento ?? CarbonImmutable::now())->setTimezone($this->zona())->toDateString();
    }

    /** Hoy en el negocio (AAAA-MM-DD). */
    public function hoy(): string
    {
        return $this->fecha();
    }

    /**
     * Hoy en el negocio como fecha (a las 00:00, igual que las columnas de fecha), para
     * compararla o sumarle días junto a ellas.
     */
    public function dia(?CarbonInterface $momento = null): CarbonImmutable
    {
        return CarbonImmutable::parse($this->fecha($momento));
    }

    /**
     * ¿Un rango de días (inclusivo; null = sin límite) cubre el instante? Se compara el
     * día del negocio de ese instante con las fechas, sin horas de por medio.
     */
    public function cubre(?CarbonInterface $desde, ?CarbonInterface $hasta, CarbonInterface $momento): bool
    {
        $dia = $this->fecha($momento);

        return ($desde === null || $desde->toDateString() <= $dia)
            && ($hasta === null || $hasta->toDateString() >= $dia);
    }

    /** Instante (UTC) en que empieza ese día en el negocio. */
    public function inicioDelDia(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, $this->zona())->startOfDay()->utc();
    }

    /** Instante (UTC) en que termina ese día en el negocio. */
    public function finDelDia(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, $this->zona())->endOfDay()->utc();
    }
}
