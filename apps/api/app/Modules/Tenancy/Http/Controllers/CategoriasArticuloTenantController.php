<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\CategoriaArticuloTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las categorías de los productos del mostrador (bebidas, ropa, suplementos…): el
 * negocio las crea, las renombra, las ordena y las quita. Con ellas el mostrador
 * filtra sus productos. Quitar una deja sus productos sin categoría.
 */
class CategoriasArticuloTenantController
{
    public function index(): JsonResponse
    {
        $categorias = CategoriaArticuloTenant::query()
            ->withCount('articulos')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $categorias->map(fn (CategoriaArticuloTenant $c): array => $this->presentar($c))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $this->validar($request);
        $categoria = CategoriaArticuloTenant::query()->create([
            'nombre' => $validado['nombre'],
            // Al final de las que ya hay, si no se dice dónde.
            'orden' => $validado['orden'] ?? ((int) CategoriaArticuloTenant::query()->max('orden') + 1),
        ]);

        return response()->json(['data' => $this->presentar($categoria->loadCount('articulos'))], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $categoria = $this->categoria($request);
        $validado = $this->validar($request, $categoria);
        $categoria->update([
            'nombre' => $validado['nombre'],
            'orden' => $validado['orden'] ?? $categoria->orden,
        ]);

        return response()->json(['data' => $this->presentar($categoria->loadCount('articulos'))]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $categoria = $this->categoria($request);
        DB::connection('tenant')->transaction(function () use ($categoria): void {
            ArticuloTenant::query()->where('categoria_id', $categoria->getKey())->update(['categoria_id' => null]);
            $categoria->delete();
        });

        return response()->json(null, 204);
    }

    private function categoria(Request $request): CategoriaArticuloTenant
    {
        return CategoriaArticuloTenant::query()->where('ulid', (string) $request->route('categoria'))->firstOrFail();
    }

    /**
     * Un nombre que no repita otra categoría (sin importar mayúsculas).
     *
     * @return array{nombre: string, orden?: int|null}
     */
    private function validar(Request $request, ?CategoriaArticuloTenant $actual = null): array
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $nombre = trim((string) $validado['nombre']);
        $repetida = CategoriaArticuloTenant::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual?->getKey()))
            ->exists();
        if ($repetida) {
            throw ValidationException::withMessages(['nombre' => ['Ya hay una categoría con ese nombre.']]);
        }

        return ['nombre' => $nombre, 'orden' => isset($validado['orden']) ? (int) $validado['orden'] : null];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CategoriaArticuloTenant $categoria): array
    {
        return [
            'id' => $categoria->ulid,
            'nombre' => $categoria->nombre,
            'orden' => $categoria->orden,
            'productos' => (int) ($categoria->getAttribute('articulos_count') ?? 0),
        ];
    }
}
