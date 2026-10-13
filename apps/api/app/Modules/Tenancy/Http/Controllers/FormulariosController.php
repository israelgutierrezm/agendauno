<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\TipoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formularios dinámicos definidos por el administrador (tenant-local). El admin
 * crea formularios y sus campos para solicitar información a miembros/instructores.
 */
class FormulariosController
{
    public function index(): JsonResponse
    {
        $formularios = Formulario::query()->with('campos')->orderBy('id')->get();

        return response()->json([
            'data' => $formularios->map(fn (Formulario $formulario): array => $this->presentar($formulario))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'aplica_a' => ['nullable', 'in:miembro,instructor,todos'],
        ]);

        $formulario = Formulario::query()->create([
            'nombre' => $validado['nombre'],
            'descripcion' => $validado['descripcion'] ?? null,
            'aplica_a' => $validado['aplica_a'] ?? 'miembro',
            'activo' => true,
        ]);

        return response()->json(['data' => $this->presentar($formulario->load('campos'))], 201);
    }

    public function agregarCampo(Request $request): JsonResponse
    {
        $formulario = Formulario::query()->where('ulid', (string) $request->route('formulario'))->firstOrFail();

        $validado = $request->validate([
            'etiqueta' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoCampo::class)],
            'obligatorio' => ['nullable', 'boolean'],
            'opciones' => ['nullable', 'array'],
            // Las vacías se descartan.
            'opciones.*' => ['nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
        // Con una sola opción (o ninguna) no habría qué elegir y nadie podría enviarlo.
        $opciones = array_values(array_filter(
            array_map(static fn (?string $opcion): string => trim((string) $opcion), $validado['opciones'] ?? []),
            static fn (string $opcion): bool => $opcion !== '',
        ));
        if ($validado['tipo'] === TipoCampo::Seleccion->value && count($opciones) < 2) {
            throw ValidationException::withMessages(['opciones' => ['Una pregunta de opción necesita al menos dos opciones.']]);
        }

        $campo = $formulario->campos()->create([
            'etiqueta' => $validado['etiqueta'],
            'tipo' => $validado['tipo'],
            'obligatorio' => (bool) ($validado['obligatorio'] ?? false),
            'opciones' => $validado['tipo'] === TipoCampo::Seleccion->value ? $opciones : ($validado['opciones'] ?? null),
            'orden' => (int) ($validado['orden'] ?? 0),
        ]);

        return response()->json(['data' => $this->presentarCampo($campo)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Formulario $formulario): array
    {
        return [
            'id' => $formulario->ulid,
            'nombre' => $formulario->nombre,
            'descripcion' => $formulario->descripcion,
            'aplica_a' => $formulario->aplica_a,
            'activo' => $formulario->activo,
            'campos' => $formulario->campos->map(fn (CampoFormulario $campo): array => $this->presentarCampo($campo))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarCampo(CampoFormulario $campo): array
    {
        return [
            'id' => $campo->ulid,
            'etiqueta' => $campo->etiqueta,
            'tipo' => $campo->tipo->value,
            'obligatorio' => $campo->obligatorio,
            'opciones' => $campo->opciones,
            'orden' => $campo->orden,
        ];
    }
}
