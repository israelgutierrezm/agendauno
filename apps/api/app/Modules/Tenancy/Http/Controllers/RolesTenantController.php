<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\GestionarRolesTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Models\RolTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Roles y permisos»: los roles del negocio (los de sistema, de solo lectura, y los
 * propios) con sus permisos, y el catálogo para armar roles nuevos. Las reglas de
 * quién puede dar qué viven en {@see GestionarRolesTenant}.
 */
class RolesTenantController
{
    public function __construct(
        private readonly RolesTenant $roles,
        private readonly GestionarRolesTenant $gestionar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $propios = RolTenant::query()->get()->keyBy('clave');

        $roles = [];
        foreach ($this->roles->todos() as $clave => $rol) {
            $modelo = $propios->get($clave);
            $roles[] = [
                'id' => $modelo?->ulid,
                'clave' => $clave,
                'nombre' => $rol['nombre'],
                'sistema' => $rol['sistema'],
                'faceta' => $rol['faceta'],
                'permisos' => $rol['permisos'],
                'personas' => $this->gestionar->personasCon($clave),
                'puede_cambiar' => $modelo instanceof RolTenant && $this->gestionar->puedeCambiarlo($actor, $modelo),
            ];
        }

        return response()->json([
            'data' => $roles,
            'catalogo' => CatalogoDePermisosTenant::catalogo(),
            'requisitos' => CatalogoDePermisosTenant::requisitos(),
            // Lo que quien arma el rol puede dar (su rol activo).
            'mis_permisos' => $this->roles->permisosDe($actor->rolesVigentes()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $this->validar($request);
        $rol = $this->gestionar->crear($this->actor($request), $validado['nombre'], $validado['permisos']);

        return response()->json(['data' => $this->presentar($rol)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $rol = $this->rol($request);
        $validado = $this->validar($request);
        $rol = $this->gestionar->actualizar($this->actor($request), $rol, $validado['nombre'], $validado['permisos']);

        return response()->json(['data' => $this->presentar($rol)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->gestionar->eliminar($this->actor($request), $this->rol($request));

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @return array{nombre: string, permisos: list<string>}
     */
    private function validar(Request $request): array
    {
        /** @var array{nombre: string, permisos: list<string>} $validado */
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:60'],
            'permisos' => ['required', 'array', 'min:1'],
            'permisos.*' => ['string', 'max:60'],
        ]);

        return $validado;
    }

    private function rol(Request $request): RolTenant
    {
        return RolTenant::query()->where('ulid', (string) $request->route('rol'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(RolTenant $rol): array
    {
        return [
            'id' => $rol->ulid,
            'clave' => $rol->clave,
            'nombre' => $rol->nombre,
            'sistema' => false,
            'faceta' => $rol->faceta,
            'permisos' => $rol->permisos,
        ];
    }

    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario, 401);

        return $actor;
    }
}
