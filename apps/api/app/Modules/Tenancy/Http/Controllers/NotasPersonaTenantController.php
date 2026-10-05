<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\NotaPersonaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notas internas del equipo sobre un cliente: las ve quien puede ver al cliente (con
 * el mismo alcance por sede e instructor que su ficha) y las escribe o borra quien lo
 * gestiona. La más reciente primero.
 */
class NotasPersonaTenantController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly AlcanceClientesTenant $alcance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $notas = NotaPersonaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->with('autor')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $notas->map(fn (NotaPersonaTenant $n): array => $this->presentar($n))->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate(['texto' => ['required', 'string', 'max:2000']]);
        $actor = $request->attributes->get('usuario_tenant');

        $nota = NotaPersonaTenant::query()->create([
            'persona_id' => $persona->getKey(),
            'autor_id' => $actor instanceof Usuario ? $actor->getKey() : null,
            'texto' => trim((string) $validado['texto']),
        ]);

        return response()->json(['data' => $this->presentar($nota->load('autor'))], 201);
    }

    public function eliminar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        NotaPersonaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('ulid', (string) $request->route('nota'))
            ->firstOrFail()
            ->delete();

        return response()->json(status: 204);
    }

    /** La persona de la ruta, si quien pregunta puede verla (sede e instructor). */
    private function persona(Request $request): PersonaTenant
    {
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        $usuario = $actor instanceof Usuario ? $actor : null;
        abort_unless(
            $usuario === null || $this->acceso->permiteSucursal($usuario, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );
        abort_unless($this->alcance->puedeVer($persona, $usuario), 403);

        return $persona;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(NotaPersonaTenant $nota): array
    {
        return [
            'id' => $nota->ulid,
            'texto' => $nota->texto,
            'autor' => $nota->autor?->name,
            'creada_en' => $nota->created_at?->toIso8601String(),
        ];
    }
}
