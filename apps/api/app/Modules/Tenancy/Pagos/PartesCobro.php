<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

use App\Modules\Tenancy\Application\CajasTenant;
use App\Modules\Tenancy\Models\ParteCobroTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Las partes de un cobro mixto (ADR 0177): varias formas de pago de la caja para un
 * mismo cobro, cada una con su monto. Una lista de `{metodo, monto_minor}`.
 *
 * - Entre 2 y 4 formas, sin repetir, cada una mayor que cero.
 * - Suman exactamente lo que se cobra.
 * - Se reparten en orden entre los tramos de un cobro (servicio, productos, propina):
 *   la primera forma cubre lo primero, y así.
 * - El cambio sale solo de la parte en efectivo.
 *
 * @phpstan-type Parte array{metodo: string, monto_minor: int}
 */
final class PartesCobro
{
    public const MIXTO = 'mixto';

    public const MAXIMO = 4;

    /**
     * Las partes pedidas, normalizadas; null si no hay (un cobro con una sola forma).
     *
     * @param  mixed  $crudas  lo validado en `partes`
     * @return list<Parte>|null
     */
    public static function desde(mixed $crudas, string $campo = 'partes'): ?array
    {
        if (! is_array($crudas) || $crudas === []) {
            return null;
        }
        $partes = [];
        foreach (array_values($crudas) as $n => $cruda) {
            $metodo = is_array($cruda) ? (string) ($cruda['metodo'] ?? '') : '';
            $monto = is_array($cruda) ? (int) ($cruda['monto_minor'] ?? 0) : 0;
            if (! in_array($metodo, CajasTenant::METODOS, true)) {
                throw ValidationException::withMessages(["{$campo}.{$n}.metodo" => ['Elige una forma de pago de la caja.']]);
            }
            if ($monto <= 0) {
                throw ValidationException::withMessages(["{$campo}.{$n}.monto_minor" => ['Cada forma lleva un monto mayor que cero.']]);
            }
            $partes[] = ['metodo' => $metodo, 'monto_minor' => $monto];
        }
        if (count($partes) === 1) {
            return null;
        }
        if (count($partes) > self::MAXIMO) {
            throw ValidationException::withMessages([$campo => ['Se cobra con hasta '.self::MAXIMO.' formas de pago.']]);
        }
        if (count(array_unique(array_column($partes, 'metodo'))) !== count($partes)) {
            throw ValidationException::withMessages([$campo => ['Cada forma de pago va una sola vez.']]);
        }

        return $partes;
    }

    /**
     * Las reglas de validación de `partes` en una petición.
     *
     * @return array<string, list<string>>
     */
    public static function reglas(string $campo = 'partes'): array
    {
        return [
            $campo => ['nullable', 'array', 'max:'.self::MAXIMO],
            "{$campo}.*.metodo" => ['required', 'string', 'in:'.implode(',', CajasTenant::METODOS)],
            "{$campo}.*.monto_minor" => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /**
     * Que sumen exactamente lo que se cobra.
     *
     * @param  list<Parte>  $partes
     */
    public static function exigirTotal(array $partes, int $total, string $campo = 'partes'): void
    {
        if (self::total($partes) !== $total) {
            throw ValidationException::withMessages([$campo => ['Las formas de pago no suman el total: revisa los montos y vuelve a cobrar.']]);
        }
    }

    /** @param  list<Parte>  $partes */
    public static function total(array $partes): int
    {
        return array_sum(array_column($partes, 'monto_minor'));
    }

    /** @param  list<Parte>  $partes */
    public static function efectivo(array $partes): int
    {
        return array_sum(array_map(static fn (array $p): int => $p['metodo'] === 'efectivo' ? $p['monto_minor'] : 0, $partes));
    }

    /**
     * Lo que se guarda como forma de pago: la única, o «mixto».
     *
     * @param  list<Parte>  $partes
     */
    public static function metodo(array $partes): string
    {
        $metodos = array_values(array_unique(array_column($partes, 'metodo')));

        return count($metodos) === 1 ? $metodos[0] : self::MIXTO;
    }

    /**
     * Reparte las partes, en su orden, entre tramos que suman lo mismo (p. ej. el
     * servicio, los productos y la propina): cada tramo recibe lo que le toca de cada
     * forma. Un tramo en cero queda sin partes.
     *
     * @param  list<Parte>  $partes
     * @param  list<int>  $tramos
     * @return list<list<Parte>>
     */
    public static function repartir(array $partes, array $tramos): array
    {
        $restantes = $partes;
        $i = 0;
        $salida = [];
        foreach ($tramos as $tramo) {
            $falta = max(0, $tramo);
            $propias = [];
            while ($falta > 0 && $i < count($restantes)) {
                $toma = min($falta, $restantes[$i]['monto_minor']);
                $propias[] = ['metodo' => $restantes[$i]['metodo'], 'monto_minor' => $toma];
                $restantes[$i]['monto_minor'] -= $toma;
                $falta -= $toma;
                if ($restantes[$i]['monto_minor'] === 0) {
                    $i++;
                }
            }
            $salida[] = $propias;
        }

        return $salida;
    }

    /**
     * Junta partes con la misma forma (p. ej. lo del servicio y lo de los productos de
     * un mismo cobro), en el orden en que aparecen.
     *
     * @param  list<Parte>  $partes
     * @return list<Parte>
     */
    public static function juntar(array $partes): array
    {
        $por = [];
        foreach ($partes as $parte) {
            $por[$parte['metodo']] = ($por[$parte['metodo']] ?? 0) + $parte['monto_minor'];
        }

        return array_map(static fn (string $m, int $monto): array => ['metodo' => $m, 'monto_minor' => $monto], array_keys($por), $por);
    }

    /**
     * Guarda las partes de un pago o de una venta.
     *
     * @param  HasMany<ParteCobroTenant, *>  $relacion
     * @param  list<Parte>  $partes
     */
    public static function guardar(HasMany $relacion, array $partes): void
    {
        foreach ($partes as $n => $parte) {
            $relacion->create(['metodo' => $parte['metodo'], 'monto_minor' => $parte['monto_minor'], 'orden' => $n]);
        }
    }

    /**
     * Las partes guardadas de un pago o de una venta, en su orden.
     *
     * @param  HasMany<ParteCobroTenant, *>  $relacion
     * @return list<Parte>
     */
    public static function leer(HasMany $relacion): array
    {
        return array_values($relacion->orderBy('orden')->orderBy('id')->get()
            ->map(static fn (ParteCobroTenant $p): array => ['metodo' => $p->metodo, 'monto_minor' => $p->monto_minor])
            ->all());
    }
}
