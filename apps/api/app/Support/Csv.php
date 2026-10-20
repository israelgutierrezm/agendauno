<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Las celdas de todas las exportaciones CSV (ADR 0173). Un texto que empieza con `=`,
 * `+`, `-`, `@`, un tabulador o un retorno, una hoja de cálculo lo toma por fórmula:
 * un nombre como `=HYPERLINK(…)` se ejecutaría al abrir el archivo. Se le antepone un
 * apóstrofo, salvo que sea un número (un monto negativo sigue siendo número). Y se
 * entrecomilla si trae coma, comillas o un salto de línea.
 */
final class Csv
{
    private const INICIOS_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    public static function celda(string|int|float|null $valor): string
    {
        $texto = (string) $valor;
        if ($texto !== '' && in_array($texto[0], self::INICIOS_DE_FORMULA, true) && ! is_numeric($texto)) {
            $texto = "'".$texto;
        }

        return str_contains($texto, ',') || str_contains($texto, '"') || str_contains($texto, "\n") || str_contains($texto, "\r")
            ? '"'.str_replace('"', '""', $texto).'"'
            : $texto;
    }

    /**
     * Una línea con sus celdas separadas por coma.
     *
     * @param  list<string|int|float|null>  $valores
     */
    public static function linea(array $valores): string
    {
        return implode(',', array_map(self::celda(...), $valores));
    }
}
