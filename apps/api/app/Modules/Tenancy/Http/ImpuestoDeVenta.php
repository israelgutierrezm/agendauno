<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Facturacion\TratamientoIva;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cómo se factura lo que se vende (ADR 0212): el IVA (`general`, `exento`, `cero`) y la
 * clave de producto o servicio del SAT, si no es la de siempre. Igual en servicios,
 * planes y artículos del mostrador.
 */
final class ImpuestoDeVenta
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function reglas(): array
    {
        return [
            'iva' => ['sometimes', Rule::in(array_map(static fn (TratamientoIva $t): string => $t->value, TratamientoIva::cases()))],
            'clave_sat' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{8}$/'],
        ];
    }

    /**
     * Lo que vino para guardar.
     *
     * @param  array<string, mixed>  $validado
     * @return array{iva?: string, clave_sat?: string|null}
     */
    public static function cambios(Request $request, array $validado): array
    {
        $cambios = [];
        if ($request->has('iva')) {
            $cambios['iva'] = TratamientoIva::de($validado['iva'] ?? null)->value;
        }
        if ($request->has('clave_sat')) {
            $clave = trim((string) ($validado['clave_sat'] ?? ''));
            $cambios['clave_sat'] = $clave !== '' ? $clave : null;
        }

        return $cambios;
    }

    /**
     * @return array{iva: string, clave_sat: string|null}
     */
    public static function presentar(mixed $iva, mixed $claveSat): array
    {
        return [
            'iva' => TratamientoIva::de($iva)->value,
            'clave_sat' => is_string($claveSat) && $claveSat !== '' ? $claveSat : null,
        ];
    }
}
