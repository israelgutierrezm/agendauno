<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AccesoExpedienteTenant;
use App\Modules\Tenancy\Application\FichasDeDatosTenant;
use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoCampo;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Respuestas a formularios dinámicos. Valida DINÁMICAMENTE contra la definición de
 * campos (obligatorios y opciones de selección) y guarda una respuesta por persona
 * (upsert). Tenant-local: opera sobre la BD del estudio resuelto.
 */
class RespuestasFormularioController
{
    private const POR_PAGINA = 25;

    /**
     * Las respuestas de un formulario por páginas (antes, solo las últimas 200), con
     * quién respondió y cuándo; se buscan por la persona. El id de su usuario
     * (instructores) sirve para abrir su expediente desde la lista.
     *
     * Solo de quienes el usuario puede abrir el expediente (R02): la misma regla que
     * el expediente, también en la búsqueda, las páginas y el total.
     */
    public function index(Request $request): JsonResponse
    {
        $formulario = Formulario::query()->where('ulid', (string) $request->route('formulario'))->firstOrFail();
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 403);
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $buscar = trim((string) ($filtros['q'] ?? ''));

        $consulta = RespuestaFormulario::query()->with('persona')->where('formulario_id', $formulario->id);
        AccesoExpedienteTenant::acotar($consulta, $usuario);
        $pagina = $consulta
            ->when($buscar !== '', fn (Builder $q) => $q->whereHas('persona', fn (Builder $p) => $p->buscar($buscar)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));
        $respuestas = collect($pagina->items());

        $usuarios = Usuario::query()
            ->whereIn('id', $respuestas->pluck('persona.usuario_id')->filter()->unique()->all())
            ->pluck('ulid', 'id');

        return response()->json([
            'data' => $respuestas->map(static fn (RespuestaFormulario $respuesta): array => [
                'id' => $respuesta->ulid,
                'persona' => $respuesta->persona?->nombreCompleto(),
                'persona_id' => $respuesta->persona?->ulid,
                'persona_tipo' => $respuesta->persona?->tipo->value,
                'usuario_id' => $respuesta->persona?->usuario_id !== null
                    ? $usuarios->get($respuesta->persona->usuario_id)
                    : null,
                'respondido_en' => $respuesta->updated_at?->toIso8601String(),
                'valores' => $respuesta->valores,
                // Con qué versión y qué preguntas se contestó (ADR 0150); las de antes
                // de las versiones no las traen (se leen con las vigentes).
                'version' => $respuesta->version,
                'campos' => $respuesta->campos,
            ])->all(),
            'meta' => [
                'version_actual' => $formulario->version,
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $formulario = Formulario::query()->with('campos')->where('ulid', (string) $request->route('formulario'))->firstOrFail();
        $persona = PersonaTenant::query()->where('ulid', (string) $request->input('persona_id'))->firstOrFail();

        // Se responde por alguien cuyo expediente se puede ver (o por uno mismo), y
        // solo formularios que le aplican (miembro / instructor / todos).
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless(
            $usuario instanceof Usuario
                && ($persona->usuario_id === $usuario->getKey() || AccesoExpedienteTenant::puedeVer($usuario, $persona)),
            403,
        );
        /** @var array<string, mixed> $entrada */
        $entrada = is_array($request->input('valores')) ? $request->input('valores') : [];
        $respuesta = $this->responder($formulario, $persona, $entrada);

        return response()->json(['data' => ['id' => $respuesta->ulid, 'valores' => $respuesta->valores]], 201);
    }

    /**
     * Guarda la respuesta de una persona (una por persona y ficha): la ficha debe estar
     * vigente y aplicarle, y los valores se validan contra sus preguntas. Quién puede
     * responder por quién lo decide quien llama (el equipo o la cuenta de la persona).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function responder(Formulario $formulario, PersonaTenant $persona, array $entrada): RespuestaFormulario
    {
        if ($formulario->archivado_en !== null) {
            throw ValidationException::withMessages(['formulario' => ['Esta ficha de datos está archivada.']]);
        }
        if (! in_array($formulario->aplica_a, [$persona->tipo->value, 'todos'], true)) {
            throw ValidationException::withMessages([
                'persona_id' => ['Esta ficha de datos no aplica a esta persona.'],
            ]);
        }
        $formulario->loadMissing('campos');
        $valores = $this->validarYNormalizar($formulario->campos, $entrada);

        return RespuestaFormulario::query()->updateOrCreate(
            ['formulario_id' => $formulario->id, 'persona_id' => $persona->id],
            [
                'valores' => $valores,
                // La versión y las preguntas de ahora: se lee igual aunque la ficha cambie.
                'version' => $formulario->version,
                'campos' => FichasDeDatosTenant::preguntasActuales($formulario),
            ],
        );
    }

    /**
     * Valida las respuestas contra la definición de campos y devuelve los valores
     * normalizados (solo campos conocidos).
     *
     * @param  Collection<int, CampoFormulario>  $campos
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validarYNormalizar($campos, array $entrada): array
    {
        $valores = [];

        foreach ($campos as $campo) {
            $valor = $entrada[$campo->ulid] ?? null;

            if ($campo->obligatorio && ($valor === null || $valor === '')) {
                throw ValidationException::withMessages([
                    $campo->ulid => ["El campo '{$campo->etiqueta}' es obligatorio."],
                ]);
            }

            if ($valor === null || $valor === '') {
                continue;
            }

            if ($campo->tipo === TipoCampo::Seleccion && ! in_array($valor, $campo->opciones ?? [], true)) {
                throw ValidationException::withMessages([
                    $campo->ulid => ["Valor inválido para '{$campo->etiqueta}'."],
                ]);
            }

            if ($campo->tipo === TipoCampo::Numero && ! is_numeric($valor)) {
                throw ValidationException::withMessages([
                    $campo->ulid => ["'{$campo->etiqueta}' debe ser numérico."],
                ]);
            }

            if ($campo->tipo === TipoCampo::Booleano) {
                $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($valor === null) {
                    throw ValidationException::withMessages([
                        $campo->ulid => ["'{$campo->etiqueta}' debe ser sí o no."],
                    ]);
                }
            }

            if ($campo->tipo === TipoCampo::Fecha) {
                $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
                if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                    throw ValidationException::withMessages([
                        $campo->ulid => ["'{$campo->etiqueta}' debe ser una fecha (AAAA-MM-DD)."],
                    ]);
                }
            }

            $valores[$campo->ulid] = $valor;
        }

        return $valores;
    }
}
