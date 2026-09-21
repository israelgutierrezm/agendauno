<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Recursos\ModoRecurso;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recursos reservables del estudio (R3): salas/canchas/carriles/equipos por sucursal.
 * El motor de agenda impide sobre-reservarlos. Opera SIEMPRE sobre la BD del estudio
 * resuelto.
 */
class RecursosTenantController
{
    public function index(): JsonResponse
    {
        $recursos = RecursoTenant::query()->with('sucursal')->orderByDesc('id')->get();

        return response()->json([
            'data' => $recursos->map(fn (RecursoTenant $r): array => $this->presentar($r))->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'modo' => ['required', Rule::enum(ModoRecurso::class)],
            'capacidad' => ['nullable', 'integer', 'min:1'],
            'activo' => ['boolean'],
        ]);

        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $recurso = RecursoTenant::query()->create([
            'sucursal_id' => $sucursal->getKey(),
            'nombre' => $validado['nombre'],
            'tipo' => $validado['tipo'] ?? null,
            'modo' => $validado['modo'],
            'capacidad' => isset($validado['capacidad']) ? (int) $validado['capacidad'] : 1,
            'activo' => (bool) ($validado['activo'] ?? true),
        ]);

        return response()->json(['data' => $this->presentar($recurso->load('sucursal'))], 201);
    }

    public function eliminar(Request $request): JsonResponse
    {
        $recurso = RecursoTenant::query()->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        $recurso->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(RecursoTenant $recurso): array
    {
        return [
            'id' => $recurso->ulid,
            'sucursal' => $recurso->sucursal?->nombre,
            'sucursal_id' => $recurso->sucursal?->ulid,
            'nombre' => $recurso->nombre,
            'tipo' => $recurso->tipo,
            'modo' => $recurso->modo->value,
            'capacidad' => $recurso->capacidad,
            'activo' => $recurso->activo,
        ];
    }
}
