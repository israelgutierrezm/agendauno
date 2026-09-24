<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Support\Str;

/**
 * Lo que ve el cliente en la página de pago de la pasarela: los productos de la
 * orden, o que es una cita.
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

        return $orden?->sesion_id !== null ? 'Cita' : 'Compra';
    }
}
