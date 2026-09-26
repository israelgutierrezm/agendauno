<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Resultado de cambiar una clase recurrente desde una fecha (2.5): cuántas sesiones se
 * movieron, cuáles se conservaron y por qué, y la serie que queda vigente.
 */
final readonly class CambioDeSerie
{
    /**
     * @param  list<array{fecha: string, motivo: string}>  $conservadas
     */
    public function __construct(
        public int $movidas,
        public array $conservadas,
        public string $serie,
    ) {}
}
