<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FichasDeDatosTenant;
use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formularios dinámicos definidos por el administrador (tenant-local). El admin
 * crea formularios y sus campos para solicitar información a miembros/instructores;
 * los edita completos, los reordena y los archiva sin perder lo respondido (ADR 0150).
 */
class FormulariosController
{
    public function __construct(private readonly FichasDeDatosTenant $fichas) {}

    /**
     * Las fichas vigentes; con `?archivados=1`, las archivadas (solo quien las diseña).
     */
    public function index(Request $request): JsonResponse
    {
        $archivados = $request->boolean('archivados');
        $usuario = $request->attributes->get('usuario_tenant');
        abort_if($archivados && ! ($usuario instanceof Usuario && $usuario->puede('formularios.gestionar')), 403);
        $formularios = Formulario::query()
            ->with('campos')
            ->withCount('respuestas')
            ->when($archivados, fn ($q) => $q->whereNotNull('archivado_en'), fn ($q) => $q->whereNull('archivado_en'))
            ->orderBy('id')
            ->get();

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

    /**
     * Guarda la ficha completa: nombre, a quién aplica y sus preguntas en el orden en que
     * llegan (las que traen `id` se actualizan, las nuevas se crean, las que faltan se
     * quitan). Con respuestas, nada respondido se pierde y sube la versión.
     */
    public function update(Request $request): JsonResponse
    {
        $formulario = $this->resolver($request);
        abort_if($formulario->archivado_en !== null, 409, 'Restaura la ficha para editarla.');
        /** @var array{nombre: string, descripcion?: string|null, aplica_a?: string|null, campos?: list<array{id?: string|null, etiqueta: string, tipo: string, obligatorio?: bool|null, opciones?: list<string|null>|null}>} $datos */
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'aplica_a' => ['nullable', 'in:miembro,instructor,todos'],
            'campos' => ['present', 'array', 'max:'.FichasDeDatosTenant::MAX_PREGUNTAS],
            'campos.*.id' => ['nullable', 'string', 'max:40'],
            'campos.*.etiqueta' => ['required', 'string', 'max:255'],
            'campos.*.tipo' => ['required', Rule::enum(TipoCampo::class)],
            'campos.*.obligatorio' => ['nullable', 'boolean'],
            'campos.*.opciones' => ['nullable', 'array', 'max:30'],
            'campos.*.opciones.*' => ['nullable', 'string', 'max:255'],
        ]);

        $guardado = $this->fichas->guardar($formulario, $datos)->loadCount('respuestas');

        return response()->json(['data' => $this->presentar($guardado)]);
    }

    /**
     * Archivar: deja de pedirse (nadie la ve ni la responde) y sus respuestas se
     * conservan. Restaurar la vuelve a pedir.
     */
    public function archivar(Request $request): JsonResponse
    {
        $formulario = $this->resolver($request);
        $formulario->update(['archivado_en' => now(), 'activo' => false]);

        return response()->json(['data' => $this->presentar($formulario->load('campos')->loadCount('respuestas'))]);
    }

    public function restaurar(Request $request): JsonResponse
    {
        $formulario = $this->resolver($request);
        $formulario->update(['archivado_en' => null, 'activo' => true]);

        return response()->json(['data' => $this->presentar($formulario->load('campos')->loadCount('respuestas'))]);
    }

    private function resolver(Request $request): Formulario
    {
        return Formulario::query()->where('ulid', (string) $request->route('formulario'))->firstOrFail();
    }

    public function agregarCampo(Request $request): JsonResponse
    {
        $formulario = Formulario::query()->where('ulid', (string) $request->route('formulario'))->firstOrFail();
        abort_if($formulario->archivado_en !== null, 409, 'Restaura la ficha para editarla.');

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
        $this->fichas->cambioDePreguntas($formulario);

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
            // Versiones (ADR 0150): con respuestas, cambiar sus preguntas sube la versión.
            'version' => $formulario->version,
            'archivado' => $formulario->archivado_en !== null,
            'respuestas' => (int) ($formulario->getAttribute('respuestas_count') ?? 0),
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
