<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $this->exigirAlcance($request, $usuario, (int) $sucursal->getKey(), (string) $validado['rol']);

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
        $usuario = Usuario::query()->findOrFail($asignacion->usuario_id);
        $this->exigirAlcance($request, $usuario, (int) $asignacion->sucursal_id, (string) $asignacion->rol);
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($asignacion, 'asignacion', $asignacion->only(['usuario_id', 'sucursal_id', 'rol']));

        return response()->json(status: 204);
    }

    /**
     * Nadie da lo que no tiene ni más alcance del que tiene (R19): quien está acotado a
     * algunas sedes solo asigna en ellas y no se asigna a sí mismo (si se diera todas,
     * vería todo); y nadie da un rol con permisos que él no tiene.
     */
    private function exigirAlcance(Request $request, Usuario $usuario, int $sucursalId, string $rol): void
    {
        $actor = $request->attributes->get('usuario_tenant');
        if (! $actor instanceof Usuario) {
            abort(403);
        }
        $acceso = app(ResolverAccesoTenant::class);
        if ($acceso->sucursalesPermitidas($actor) !== null) {
            abort_unless($acceso->permiteSucursal($actor, $sucursalId), 403, 'Esa sucursal no te corresponde.');
            abort_if((int) $usuario->getKey() === (int) $actor->getKey(), 403, 'No puedes cambiar tus propias sucursales.');
        }
        $roles = app(RolesTenant::class);
        if (! RolesTenant::cabenEn($roles->permisosDe([$rol]), $roles->permisosDe($actor->rolesVigentes()))) {
            throw ValidationException::withMessages(['rol' => ['No puedes dar ni quitar un rol con permisos que tú no tienes.']]);
        }
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
