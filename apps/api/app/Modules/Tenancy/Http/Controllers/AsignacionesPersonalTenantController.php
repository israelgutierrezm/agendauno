<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asignaciones de personal a sucursales con rol (RBAC con scope por sucursal, R19).
 * Amplia el `rol` tenant-wide del usuario con un rol EN una sucursal concreta. Opera
 * SIEMPRE sobre la BD del estudio resuelto.
 */
class AsignacionesPersonalTenantController
{
    public function index(): JsonResponse
    {
        $asignaciones = AsignacionPersonalTenant::query()->with(['usuario', 'sucursal'])->orderByDesc('id')->get();

        return response()->json([
            'data' => $asignaciones->map(fn (AsignacionPersonalTenant $a): array => $this->presentar($a))->all(),
        ]);
    }

    /**
     * Asigna (o reasigna) el rol de un usuario en una sucursal. Idempotente por
     * (usuario, sucursal).
     */
    public function guardar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $validado = $request->validate([
            'usuario_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'rol' => ['required', 'string', Rule::in(CatalogoDePermisosTenant::rolesAsignables())],
        ]);

        $usuario = Usuario::query()->where('ulid', $validado['usuario_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $asignacion = AsignacionPersonalTenant::withTrashed()->updateOrCreate(
            ['usuario_id' => $usuario->getKey(), 'sucursal_id' => $sucursal->getKey()],
            ['rol' => $validado['rol']],
        );
        // Esa clave estaba eliminada: se restaura con los datos nuevos.
        if ($asignacion->trashed()) {
            $asignacion->restore();
            $eliminaciones->restaurado($asignacion, 'asignacion', $asignacion->only($asignacion->getFillable()));
        }

        return response()->json(['data' => $this->presentar($asignacion->fresh(['usuario', 'sucursal']))], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $asignacion = AsignacionPersonalTenant::query()->where('ulid', (string) $request->route('asignacion'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($asignacion, 'asignacion', $asignacion->only(['usuario_id', 'sucursal_id', 'rol']));

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(AsignacionPersonalTenant $asignacion): array
    {
        return [
            'id' => $asignacion->ulid,
            'usuario_id' => $asignacion->usuario?->ulid,
            'usuario' => $asignacion->usuario?->name,
            'sucursal_id' => $asignacion->sucursal?->ulid,
            'sucursal' => $asignacion->sucursal?->nombre,
            'rol' => $asignacion->rol,
        ];
    }
}
