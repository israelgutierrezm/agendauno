<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tiempo de preparación (antes) y de limpieza (después) de un servicio (fase 2,
 * punto 2.3). Al cliente se le comunica la atención (p. ej. 60 min); la agenda
 * ocupa además los márgenes (p. ej. 75 min con 15 de limpieza). Sin márgenes, lo
 * ocupado es la atención misma.
 */
final readonly class MargenesServicio
{
    public function __construct(
        public int $antes = 0,
        public int $despues = 0,
    ) {}

    public static function de(?OfertaTenant $oferta): self
    {
        return new self(
            max(0, (int) ($oferta->preparacion_min ?? 0)),
            max(0, (int) ($oferta->limpieza_min ?? 0)),
        );
    }

    /**
     * Los que congeló una sesión al crearse (reasignarla no cambia su servicio).
     */
    public static function deSesion(SesionTenant $sesion): self
    {
        return new self((int) $sesion->margen_antes_min, (int) $sesion->margen_despues_min);
    }

    /**
     * Desde cuándo ocupa la agenda una atención que empieza a esa hora.
     */
    public function desde(CarbonInterface $inicia): CarbonImmutable
    {
        return CarbonImmutable::instance($inicia)->subMinutes($this->antes);
    }

    /**
     * Hasta cuándo ocupa la agenda una atención que termina a esa hora.
     */
    public function hasta(CarbonInterface $termina): CarbonImmutable
    {
        return CarbonImmutable::instance($termina)->addMinutes($this->despues);
    }

    public function total(): int
    {
        return $this->antes + $this->despues;
    }
}
