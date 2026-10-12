<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\WidgetTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Widgets para la web del negocio (ADR 0141), en Captación → «Integrar en mi web»: el
 * calendario de clases o los planes, con filtros iniciales, tema, color de acento y las
 * webs donde se mostrarán. Reutilizan lo público (calendario, escaparate y el formulario
 * de captación): no es otro motor de reservas.
 */
class WidgetsTenantController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WidgetTenant::query()->orderBy('id')->get()->map(fn (WidgetTenant $w): array => $this->presentar($w))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $widget = WidgetTenant::query()->create($this->validar($request));

        return response()->json(['data' => $this->presentar($widget)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $widget = $this->widget($request);
        $widget->update($this->validar($request));

        return response()->json(['data' => $this->presentar($widget->refresh())]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->widget($request)->delete();

        return response()->json(null, 204);
    }

    /**
     * Lo que necesita el widget para mostrarse en otra web (público): su tipo, filtros,
     * tema y acento, y dónde puede mostrarse. Apagado o con la página del negocio
     * cerrada, no existe.
     */
    public function publico(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio && $estudio->paginaPublica(), 404);
        $widget = $this->widget($request);
        abort_unless($widget->activo, 404);

        return response()->json(['data' => [
            ...$this->presentar($widget),
            'negocio' => $estudio->nombre,
            'modalidad' => $estudio->modalidad()->value,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $validado = $request->validate([
            'tipo' => ['required', Rule::in(WidgetTenant::TIPOS)],
            'nombre' => ['required', 'string', 'max:120'],
            'filtros' => ['nullable', 'array'],
            'filtros.sucursal' => ['nullable', 'string', 'max:40'],
            'filtros.actividad' => ['nullable', 'string', 'max:40'],
            'tema' => ['nullable', Rule::in(WidgetTenant::TEMAS)],
            'acento' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'dominios' => ['nullable', 'array', 'max:10'],
            'dominios.*' => ['string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $estudio = $request->attributes->get('estudio');
        if ($validado['tipo'] === 'calendario' && $estudio instanceof Estudio && $estudio->modalidad() !== ModalidadServicio::Clases) {
            throw ValidationException::withMessages(['tipo' => ['El calendario es de clases: tu negocio agenda citas.']]);
        }
        $sucursal = $validado['filtros']['sucursal'] ?? null;
        if ($sucursal !== null && ! SucursalTenant::query()->where('ulid', $sucursal)->exists()) {
            throw ValidationException::withMessages(['filtros.sucursal' => ['Esa sucursal no existe.']]);
        }
        $actividad = $validado['filtros']['actividad'] ?? null;
        if ($actividad !== null && ! ActividadTenant::query()->where('ulid', $actividad)->exists()) {
            throw ValidationException::withMessages(['filtros.actividad' => ['Esa actividad no existe.']]);
        }

        return [
            'tipo' => $validado['tipo'],
            'nombre' => trim((string) $validado['nombre']),
            'filtros' => array_filter(['sucursal' => $sucursal, 'actividad' => $actividad], static fn (?string $v): bool => $v !== null),
            'tema' => $validado['tema'] ?? 'claro',
            'acento' => isset($validado['acento']) ? strtolower((string) $validado['acento']) : null,
            'dominios' => self::dominios($validado['dominios'] ?? []),
            'activo' => (bool) ($validado['activo'] ?? true),
        ];
    }

    /**
     * Las webs donde se mostrará, como dominio (sin «https://», ruta ni puerto):
     * «https://www.miestudio.com/clases» queda «www.miestudio.com».
     *
     * @param  array<int, string>  $entrada
     * @return list<string>
     */
    private static function dominios(array $entrada): array
    {
        $dominios = [];
        foreach ($entrada as $n => $texto) {
            $texto = trim(mb_strtolower($texto));
            if ($texto === '') {
                continue;
            }
            $host = parse_url(str_contains($texto, '://') ? $texto : "https://{$texto}", PHP_URL_HOST);
            if (! is_string($host) || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
                throw ValidationException::withMessages(["dominios.{$n}" => ['Escribe la dirección de tu web, por ejemplo www.miestudio.com.']]);
            }
            $dominios[] = $host;
        }

        return array_values(array_unique($dominios));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(WidgetTenant $w): array
    {
        return [
            'id' => $w->ulid,
            'tipo' => $w->tipo,
            'nombre' => $w->nombre,
            'filtros' => ['sucursal' => $w->filtros['sucursal'] ?? null, 'actividad' => $w->filtros['actividad'] ?? null],
            'tema' => $w->tema,
            'acento' => $w->acento,
            'dominios' => $w->dominios ?? [],
            'activo' => $w->activo,
        ];
    }

    private function widget(Request $request): WidgetTenant
    {
        return WidgetTenant::query()->where('ulid', (string) $request->route('widget'))->firstOrFail();
    }
}
