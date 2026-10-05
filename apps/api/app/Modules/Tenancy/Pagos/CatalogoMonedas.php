<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Las monedas con las que puede trabajar un negocio (ADR 0097). Todas tienen dos
 * decimales: el dinero se guarda en centavos (`*_minor`), así que una moneda sin
 * centavos (p. ej. el peso chileno) necesita otro tratamiento antes de entrar aquí.
 *
 * La moneda del negocio es un parámetro (`negocio.moneda`); como los parámetros se
 * guardan como enteros, se identifica con su número ISO 4217 (484 = MXN).
 */
final class CatalogoMonedas
{
    public const PREDETERMINADA = 'MXN';

    /**
     * Código ISO 4217 => [número ISO, nombre].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const MONEDAS = [
        'MXN' => [484, 'Peso mexicano'],
        'USD' => [840, 'Dólar estadounidense'],
        'EUR' => [978, 'Euro'],
        'CAD' => [124, 'Dólar canadiense'],
        'GTQ' => [320, 'Quetzal guatemalteco'],
        'CRC' => [188, 'Colón costarricense'],
        'DOP' => [214, 'Peso dominicano'],
        'COP' => [170, 'Peso colombiano'],
        'PEN' => [604, 'Sol peruano'],
        'ARS' => [32, 'Peso argentino'],
        'UYU' => [858, 'Peso uruguayo'],
        'BRL' => [986, 'Real brasileño'],
    ];

    /**
     * @return list<string>
     */
    public static function codigos(): array
    {
        return array_keys(self::MONEDAS);
    }

    public static function existe(string $codigo): bool
    {
        return array_key_exists(mb_strtoupper($codigo), self::MONEDAS);
    }

    /**
     * Regla de validación: una moneda del catálogo (sin importar mayúsculas; se guarda
     * en mayúsculas).
     *
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function regla(): \Closure
    {
        return static function (string $atributo, mixed $valor, \Closure $falla): void {
            if (! is_string($valor) || ! self::existe($valor)) {
                $falla('Elige una moneda del catálogo.');
            }
        };
    }

    /**
     * Número ISO de un código (para guardarlo como parámetro).
     */
    public static function numero(string $codigo): int
    {
        return self::MONEDAS[mb_strtoupper($codigo)][0] ?? self::MONEDAS[self::PREDETERMINADA][0];
    }

    /**
     * Código de un número ISO; uno desconocido vuelve a la predeterminada.
     */
    public static function codigoDe(int $numero): string
    {
        foreach (self::MONEDAS as $codigo => [$iso]) {
            if ($iso === $numero) {
                return $codigo;
            }
        }

        return self::PREDETERMINADA;
    }

    /**
     * Para elegir una moneda: código y nombre, en el orden del catálogo.
     *
     * @return list<array{codigo: string, nombre: string}>
     */
    public static function lista(): array
    {
        $lista = [];
        foreach (self::MONEDAS as $codigo => [, $nombre]) {
            $lista[] = ['codigo' => $codigo, 'nombre' => $nombre];
        }

        return $lista;
    }

    /**
     * Para listas: «MXN · Peso mexicano», por número ISO.
     *
     * @return array<int, string>
     */
    public static function etiquetasPorNumero(): array
    {
        $etiquetas = [];
        foreach (self::MONEDAS as $codigo => [$iso, $nombre]) {
            $etiquetas[$iso] = "{$codigo} · {$nombre}";
        }

        return $etiquetas;
    }
}
