<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ResumenDelDiaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /inicio/hoy: el día de hoy para el Inicio del negocio (agenda, cobros y
 * renovaciones, cada bloque según los permisos de quien pregunta), con lo que importa
 * según trabaje con citas o con clases (ADR 0091). `fecha` es el día LOCAL que ve la
 * pantalla; sin ella, hoy en la zona de la primera sede.
 */
class InicioHoyTenantController
{
    public function __invoke(Request $request, ResumenDelDiaTenant $resumen): JsonResponse
    {
        $validado = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);

        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 403);

        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $dia = isset($validado['fecha']) ? (string) $validado['fecha'] : CarbonImmutable::now($zona)->toDateString();

        $estudio = $request->attributes->get('estudio');
        $modalidad = $estudio instanceof Estudio ? $estudio->modalidad() : ModalidadServicio::Clases;

        return response()->json(['data' => $resumen->para($usuario, CarbonImmutable::parse($dia, 'UTC'), $modalidad)]);
    }
}
