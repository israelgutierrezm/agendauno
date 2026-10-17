<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDeRecursoTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Recursos\ModoRecurso;
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

    /**
     * Cambia sus datos o lo activa y desactiva. No se desactiva el único espacio activo
     * de un servicio en su sede: el servicio ya no se podría agendar ahí.
     */
    public function actualizar(Request $request, BajaDeRecursoTenant $bajas): JsonResponse
    {
        $recurso = RecursoTenant::query()->with('sucursal')->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capacidad' => ['sometimes', 'integer', 'min:1'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $cambios = array_filter([
            'nombre' => isset($validado['nombre']) ? trim((string) $validado['nombre']) : null,
            'capacidad' => isset($validado['capacidad']) && $recurso->modo === ModoRecurso::Pool ? (int) $validado['capacidad'] : null,
            'activo' => array_key_exists('activo', $validado) ? (bool) $validado['activo'] : null,
        ], static fn (mixed $v): bool => $v !== null) + (array_key_exists('tipo', $validado) ? ['tipo' => $validado['tipo']] : []);

        // Desactivarlo sigue las mismas reglas que eliminarlo (BajaDeRecursoTenant).
        $movidas = 0;
        if (array_key_exists('activo', $validado) && ! $validado['activo'] && $recurso->activo) {
            $movidas = $bajas->dejarDeUsar($recurso, 'activo', static fn (RecursoTenant $r) => $r->update($cambios));
        } else {
            $recurso->update($cambios);
        }

        return response()->json([
            'data' => $this->presentar($recurso->refresh()->load('sucursal')),
            'meta' => ['movidas' => $movidas],
        ]);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones, BajaDeRecursoTenant $bajas): JsonResponse
    {
        $recurso = RecursoTenant::query()->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $movidas = $bajas->dejarDeUsar($recurso, 'recurso', static fn (RecursoTenant $r) => $eliminaciones->eliminar($r, 'recurso', $r->only(['nombre', 'tipo', 'capacidad'])));

        return response()->json(['data' => ['movidas' => $movidas]]);
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
