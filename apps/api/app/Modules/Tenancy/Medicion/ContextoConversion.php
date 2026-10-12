<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;

/**
 * El navegador de quien convirtió (ADR 0143), como se guardó con el registro: sus
 * preferencias de medición y los identificadores que se permitieron. Nada de esto se
 * inventa: lo que no llegó queda en null y el envío que lo necesita se omite.
 */
final readonly class ContextoConversion
{
    public function __construct(
        public ?string $visitante,
        public bool $analitica,
        public bool $publicidad,
        public ?string $gaClientId,
        public ?string $gaSessionId,
        public ?string $fbp,
        public ?string $fbc,
        public ?string $agente,
        public ?string $ip,
        public ?string $url,
        public ?CarbonImmutable $capturadoEn,
    ) {}
}
