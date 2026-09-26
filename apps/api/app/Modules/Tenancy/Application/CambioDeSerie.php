<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Resultado de cambiar una clase recurrente desde una fecha (2.5): cuántas sesiones se
 * movieron, cuáles se conservaron y por qué, y la serie que queda vigente. Al cambiar
 * los días: cuántas fechas salieron de la serie, cuántas se crearon en los días nuevos
 * y cuáles no se pudieron crear (con su motivo).
 */
final readonly class CambioDeSerie
{
    /**
     * @param  list<array{fecha: string, motivo: string}>  $conservadas
     * @param  list<array{fecha: string, motivo: string}>  $omitidas
     */
    public function __construct(
        public int $movidas,
        public array $conservadas,
        public string $serie,
        public int $quitadas = 0,
        public int $creadas = 0,
        public array $omitidas = [],
    ) {}
}
