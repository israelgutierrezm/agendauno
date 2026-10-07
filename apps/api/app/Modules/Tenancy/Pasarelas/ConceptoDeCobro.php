<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Support\Str;

/**
 * Lo que ve el cliente en la página de pago de la pasarela: los productos de la
 * orden, o la cita o la clase que paga.
 */
final class ConceptoDeCobro
{
    public static function de(?OrdenTenant $orden): string
    {
        $nombres = $orden?->lineas
            ->map(static fn (LineaOrdenTenant $l): ?string => $l->producto?->nombre)
            ->filter()
            ->unique()
            ->implode(', ');

        if (is_string($nombres) && $nombres !== '') {
            return Str::limit($nombres, 120);
        }

        return self::deLaSesion($orden) ?? 'Compra';
    }

    /**
     * Una orden por una sesión se nombra por su tipo: «Cita» solo si es una cita; la
     * clase de pago suelto es una «Clase» (ADR 0104). Null si no es por una sesión.
     */
    public static function deLaSesion(?OrdenTenant $orden): ?string
    {
        if ($orden?->sesion_id === null) {
            return null;
        }

        return $orden->sesion?->esCita() === true ? 'Cita' : 'Clase';
    }
}
