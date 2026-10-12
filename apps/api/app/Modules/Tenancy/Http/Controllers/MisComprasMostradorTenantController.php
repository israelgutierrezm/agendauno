<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\TicketVentaPosTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que compró en el mostrador del negocio (ADR 0145): sus tickets, de la compra más
 * reciente a la más antigua, por páginas. Sirve a alumnos y al equipo; quien nunca
 * compró a su nombre no tiene ninguno. Las anuladas se ven como anuladas.
 */
class MisComprasMostradorTenantController
{
    public function __construct(
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly TicketVentaPosTenant $tickets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $porPagina = (int) ($filtros['per_page'] ?? 10);

        // La suya o la de una persona a su cargo (B05).
        $persona = $this->cuenta->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => $porPagina, 'ultima_pagina' => 1]]);
        }

        $pagina = VentaPosTenant::query()
            ->where('persona_id', $persona->getKey())
            ->with(['sucursal', 'lineas.articulo'])
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (VentaPosTenant $v): array => $this->tickets->datos($v))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }
}
