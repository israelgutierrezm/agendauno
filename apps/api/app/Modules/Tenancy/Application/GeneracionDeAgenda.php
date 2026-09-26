<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Resultado de generar las sesiones de una clase recurrente: cuántas se crearon y
 * qué fechas no se pudieron generar y por qué (p. ej. el instructor ya tenía otra
 * clase o la sala estaba ocupada), para explicarlo en lugar de omitirlas en silencio.
 */
final class GeneracionDeAgenda
{
    /**
     * @param  list<array{fecha: string, motivo: string}>  $omitidas
     */
    public function __construct(
        public readonly int $creadas,
        public readonly array $omitidas,
    ) {}
}
