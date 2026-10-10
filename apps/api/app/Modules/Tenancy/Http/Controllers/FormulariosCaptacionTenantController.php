<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CaptacionTenant;
use App\Modules\Tenancy\Application\FormulariosCaptacionTenant;
use App\Modules\Tenancy\Models\CampoFormularioCaptacionTenant;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\TipoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los formularios de captación del negocio (ADR 0117): el de «Pedir acceso» de su
 * página y los que crea para compartir por enlace o QR o embeber en su propio sitio.
 * Aparte de los formularios que llenan sus miembros.
 */
class FormulariosCaptacionTenantController
{
    public function __construct(
        private readonly FormulariosCaptacionTenant $formularios,
        private readonly CaptacionTenant $captacion,
    ) {}

    public function index(): JsonResponse
    {
        // Siempre hay uno: el de «Pedir acceso».
        $this->formularios->predeterminado();
        $conteos = ProspectoTenant::query()->selectRaw('formulario_id, COUNT(*) as total')->groupBy('formulario_id')->pluck('total', 'formulario_id');

        return response()->json([
            'data' => FormularioCaptacionTenant::query()->with('campos')->orderByDesc('predeterminado')->orderBy('nombre')->get()
                ->map(fn (FormularioCaptacionTenant $f): array => [...$this->presentar($f), 'prospectos' => (int) ($conteos[$f->getKey()] ?? 0)])
                ->all(),
            'meta' => ['captacion_activa' => $this->captacion->activa()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $formulario = $this->formularios->guardar(null, $this->validar($request));

        return response()->json(['data' => $this->presentar($formulario)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $formulario = FormularioCaptacionTenant::query()->where('ulid', (string) $request->route('formulario'))->firstOrFail();

        return response()->json(['data' => $this->presentar($this->formularios->guardar($formulario, $this->validar($request)))]);
    }

    /**
     * @return array{nombre: string, titulo: string, descripcion?: string|null, mensaje_confirmacion?: string|null, pide_telefono?: bool, telefono_obligatorio?: bool, predeterminado?: bool, activo?: bool, campos?: list<array{id?: string|null, etiqueta: string, tipo: string, obligatorio?: bool, opciones?: list<string>|null}>}
     */
    private function validar(Request $request): array
    {
        /** @var array{nombre: string, titulo: string, descripcion?: string|null, mensaje_confirmacion?: string|null, pide_telefono?: bool, telefono_obligatorio?: bool, predeterminado?: bool, activo?: bool, campos?: list<array{id?: string|null, etiqueta: string, tipo: string, obligatorio?: bool, opciones?: list<string>|null}>} */
        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'titulo' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'mensaje_confirmacion' => ['nullable', 'string', 'max:500'],
            'pide_telefono' => ['nullable', 'boolean'],
            'telefono_obligatorio' => ['nullable', 'boolean'],
            'predeterminado' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
            'campos' => ['nullable', 'array', 'max:'.FormulariosCaptacionTenant::MAX_CAMPOS],
            'campos.*.id' => ['nullable', 'string', 'max:40'],
            'campos.*.etiqueta' => ['required', 'string', 'max:160'],
            'campos.*.tipo' => ['required', Rule::enum(TipoCampo::class)],
            'campos.*.obligatorio' => ['nullable', 'boolean'],
            'campos.*.opciones' => ['nullable', 'array', 'max:20'],
            'campos.*.opciones.*' => ['string', 'max:100'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(FormularioCaptacionTenant $f): array
    {
        $f->loadMissing('campos');

        return [
            'id' => $f->ulid,
            'nombre' => $f->nombre,
            'titulo' => $f->titulo,
            'descripcion' => $f->descripcion,
            'mensaje_confirmacion' => $f->mensaje_confirmacion,
            'pide_telefono' => $f->pide_telefono,
            'telefono_obligatorio' => $f->telefono_obligatorio,
            'predeterminado' => $f->predeterminado,
            'activo' => $f->activo,
            'campos' => $f->campos->map(fn (CampoFormularioCaptacionTenant $c): array => [
                'id' => $c->ulid,
                'etiqueta' => $c->etiqueta,
                'tipo' => $c->tipo->value,
                'obligatorio' => $c->obligatorio,
                'opciones' => $c->opciones,
            ])->values()->all(),
        ];
    }
}
