<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\GestionarLlavesApiTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LlaveApiTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de llaves de API tenant-local (R40): crear (el secreto se muestra una
 * sola vez), listar (nunca el secreto) y revocar. Gateado por
 * `integraciones.configurar`. Opera sobre la BD del estudio resuelto.
 */
class LlavesApiTenantController
{
    public function __construct(private readonly GestionarLlavesApiTenant $llaves) {}

    public function index(): JsonResponse
    {
        $llaves = LlaveApiTenant::query()->orderByDesc('id')->get();

        return response()->json([
            'data' => $llaves->map(fn (LlaveApiTenant $l): array => $this->presentar($l))->all(),
            'scopes' => CatalogoDePermisosTenant::scopesApi(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(CatalogoDePermisosTenant::scopesApi())],
        ]);

        /** @var list<string> $scopes */
        $scopes = array_values(array_unique($validado['scopes']));
        // Nadie concede lo que no tiene: cada alcance de la llave debe ser un permiso de
        // quien la crea. Y quien está acotado a sus sedes no crea llaves: la integración
        // vería a los clientes de todo el negocio (R19).
        $actor = $request->attributes->get('usuario_tenant');
        if ($actor instanceof Usuario) {
            abort_if(app(ResolverAccesoTenant::class)->sucursalesPermitidas($actor) !== null, 403, 'Una llave de API ve todo el negocio: la crea quien no está acotado a sus sedes.');
            $faltan = array_values(array_filter($scopes, static fn (string $scope): bool => ! $actor->puede($scope)));
            if ($faltan !== []) {
                throw ValidationException::withMessages(['scopes' => ['No puedes dar a la llave permisos que no tienes: '.implode(', ', $faltan).'.']]);
            }
        }

        ['llave' => $llave, 'secreto' => $secreto] = $this->llaves->crear((string) $validado['nombre'], $scopes);

        // El secreto se devuelve UNA sola vez (no se puede recuperar después).
        return response()->json(['data' => $this->presentar($llave) + ['secreto' => $secreto]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $llave = LlaveApiTenant::query()->where('ulid', (string) $request->route('llave'))->firstOrFail();

        $llave->update(['activa' => false]);

        return response()->json(['data' => $this->presentar($llave)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(LlaveApiTenant $llave): array
    {
        return [
            'id' => $llave->ulid,
            'nombre' => $llave->nombre,
            'prefijo' => $llave->prefijo,
            'scopes' => $llave->scopes,
            'activa' => (bool) $llave->activa,
            'ultimo_uso_en' => $llave->ultimo_uso_en?->toIso8601String(),
        ];
    }
}
