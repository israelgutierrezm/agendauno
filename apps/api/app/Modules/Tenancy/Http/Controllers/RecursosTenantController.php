<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Recursos\ModoRecurso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    public function actualizar(Request $request): JsonResponse
    {
        $recurso = RecursoTenant::query()->with('sucursal')->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capacidad' => ['sometimes', 'integer', 'min:1'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('activo', $validado) && ! $validado['activo'] && $recurso->activo) {
            $sinOtro = $this->serviciosQueSeQuedanSinEspacio($recurso);
            if ($sinOtro !== []) {
                throw ValidationException::withMessages(['activo' => [
                    'Es el único espacio activo de '.implode(', ', array_map(static fn (string $n): string => "«{$n}»", $sinOtro))
                    .' en esta sede: activa otro o quítaselo al servicio antes de desactivarlo.',
                ]]);
            }
        }

        $recurso->update(array_filter([
            'nombre' => isset($validado['nombre']) ? trim((string) $validado['nombre']) : null,
            'capacidad' => isset($validado['capacidad']) && $recurso->modo === ModoRecurso::Pool ? (int) $validado['capacidad'] : null,
            'activo' => array_key_exists('activo', $validado) ? (bool) $validado['activo'] : null,
        ], static fn (mixed $v): bool => $v !== null) + (array_key_exists('tipo', $validado) ? ['tipo' => $validado['tipo']] : []));

        return response()->json(['data' => $this->presentar($recurso->refresh()->load('sucursal'))]);
    }

    /**
     * Los servicios que lo usan y no tienen otro espacio activo en su sede.
     *
     * @return list<string>
     */
    private function serviciosQueSeQuedanSinEspacio(RecursoTenant $recurso): array
    {
        $nombres = [];
        foreach ($recurso->ofertas()->get() as $oferta) {
            $otro = $oferta->recursos()
                ->where('recursos.sucursal_id', $recurso->sucursal_id)
                ->where('recursos.activo', true)
                ->whereKeyNot($recurso->getKey())
                ->exists();
            if (! $otro) {
                $nombres[] = (string) $oferta->nombre;
            }
        }

        return $nombres;
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $recurso = RecursoTenant::query()->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($recurso, 'recurso', $recurso->only(['nombre', 'tipo', 'capacidad']));

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
