<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\ResumenClientesTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /miembros/resumen`: los números de arriba del directorio de clientes (total,
 * con plan, nuevos del mes, por vencer y con adeudo). El staff acotado a sedes solo
 * cuenta a los de sus sucursales (R19).
 */
class ResumenClientesTenantController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly ResumenClientesTenant $resumen,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('usuario_tenant');
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        return response()->json(['data' => $this->resumen->calcular($permitidas)]);
    }
}
