<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La bandeja de WhatsApp e Instagram (ADR 0200): las conversaciones del negocio, leerlas,
 * contestarlas (en las 24 horas que deja Meta), archivarlas y vincularlas con la ficha
 * de un cliente. Con `mensajes.atender`.
 */
class BandejaTenantController
{
    private const POR_PAGINA = 30;

    /** Los mensajes que se muestran de una conversación (los más recientes). */
    private const MENSAJES = 200;

    public function __construct(private readonly BandejaTenant $bandeja) {}

    public function resumen(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'sin_leer' => ConversacionTenant::query()->where('estado', ConversacionTenant::ABIERTA)->where('no_leidos', '>', 0)->count(),
            'canales' => CanalMensajeria::query()->where('estudio_id', $estudio->getKey())->pluck('canal')->all(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([ConversacionTenant::ABIERTA, ConversacionTenant::ARCHIVADA])],
            'canal' => ['nullable', Rule::in(CanalMensajeria::CANALES)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = trim((string) ($filtros['q'] ?? ''));
        $digitos = (string) preg_replace('/\D/', '', $q);
        $pagina = ConversacionTenant::query()
            ->where('estado', $filtros['estado'] ?? ConversacionTenant::ABIERTA)
            ->when(isset($filtros['canal']), fn ($c) => $c->where('canal', $filtros['canal']))
            ->when($q !== '', fn ($c) => $c->where(function ($w) use ($q, $digitos): void {
                $w->where('contacto_nombre', 'like', '%'.$q.'%')
                    ->when($digitos !== '', fn ($d) => $d->orWhere('contacto_id', 'like', '%'.$digitos.'%'))
                    ->orWhereHas('persona', fn ($p) => $p->buscar($q));
            }))
            ->with('persona')
            ->orderByDesc('ultimo_mensaje_en')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA, ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (ConversacionTenant $c): array => $this->presentar($c))->all(),
            'meta' => ['total' => $pagina->total(), 'page' => $pagina->currentPage(), 'per_page' => $pagina->perPage(), 'ultima_pagina' => $pagina->lastPage()],
        ]);
    }

    /** Una conversación con sus mensajes; abrirla la deja leída. */
    public function show(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        if ($conversacion->no_leidos > 0) {
            $conversacion->forceFill(['no_leidos' => 0])->save();
        }
        $mensajes = MensajeConversacionTenant::query()
            ->where('conversacion_id', $conversacion->getKey())
            ->with('autor')
            ->orderByDesc('enviado_en')
            ->orderByDesc('id')
            ->limit(self::MENSAJES)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['data' => [
            ...$this->presentar($conversacion),
            'mensajes' => $mensajes->map(fn (MensajeConversacionTenant $m): array => $this->presentarMensaje($m))->all(),
        ]]);
    }

    public function enviar(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            // Instagram acepta hasta 1,000 caracteres; WhatsApp, 4,096.
            'texto' => ['required', 'string', 'max:'.($conversacion->canal === CanalMensajeria::INSTAGRAM ? 1000 : 4096)],
        ]);
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        $mensaje = $this->bandeja->responder($this->estudio($request), $conversacion, $usuario, trim((string) $validado['texto']));

        return response()->json(['data' => $this->presentarMensaje($mensaje->load('autor'))], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            'estado' => ['sometimes', Rule::in([ConversacionTenant::ABIERTA, ConversacionTenant::ARCHIVADA])],
            'persona_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        if (array_key_exists('estado', $validado)) {
            $conversacion->estado = (string) $validado['estado'];
        }
        if (array_key_exists('persona_id', $validado)) {
            $conversacion->persona_id = $validado['persona_id'] === null
                ? null
                : (int) PersonaTenant::query()->where('ulid', (string) $validado['persona_id'])->firstOrFail()->getKey();
        }
        $conversacion->save();

        return response()->json(['data' => $this->presentar($conversacion->load('persona'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ConversacionTenant $c): array
    {
        return [
            'id' => $c->ulid,
            'canal' => $c->canal,
            'contacto' => $c->contacto_nombre,
            // El número (WhatsApp) sirve para reconocerlo; el id de Instagram no dice nada.
            'telefono' => $c->canal === CanalMensajeria::WHATSAPP ? '+'.$c->contacto_id : null,
            'persona' => $c->persona === null ? null : ['id' => $c->persona->ulid, 'nombre' => $c->persona->nombreCompleto()],
            'estado' => $c->estado,
            'no_leidos' => $c->no_leidos,
            'ultimo_texto' => $c->ultimo_texto,
            'ultimo_mensaje_en' => $c->ultimo_mensaje_en?->toIso8601String(),
            'puede_contestar' => $c->puedeContestar(),
            'contestar_hasta' => $c->ultimo_entrante_en?->copy()->addHours(ConversacionTenant::HORAS_PARA_CONTESTAR)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarMensaje(MensajeConversacionTenant $m): array
    {
        return [
            'id' => $m->ulid,
            'direccion' => $m->direccion,
            'tipo' => $m->tipo,
            'texto' => $m->texto,
            'estado' => $m->estado,
            'enviado_en' => $m->enviado_en->toIso8601String(),
            'enviado_por' => $m->autor?->nombreCorto(),
        ];
    }

    private function conversacion(Request $request): ConversacionTenant
    {
        return ConversacionTenant::query()->where('ulid', (string) $request->route('conversacion'))->with('persona')->firstOrFail();
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
