<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mensajes (comunicaciones) del estudio (R28): historial de lo generado/enviado, con
 * su estado, por páginas. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class MensajesTenantController
{
    private const POR_PAGINA = 25;

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    /**
     * La bandeja de salida por páginas: se busca por asunto, destinatario o persona y se
     * acota por estado, canal y fechas (días del negocio). `meta.conteos` cuenta lo
     * encontrado por estado, sin el filtro de estado: así se ve cuántos fallaron.
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoMensaje::class)],
            'canal' => ['nullable', Rule::enum(CanalComunicacion::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($validado['desde'], $validado['hasta']) && $validado['hasta'] < $validado['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }

        $q = trim((string) ($validado['q'] ?? ''));
        // De sus sedes (R19): los avisos al equipo (sin persona) los ve todo el equipo.
        $encontrados = fn (): Builder => app(AlcancePersonaTenant::class)->acotada(MensajeTenant::query(), $request, 'persona_id', true)
            ->when(isset($validado['canal']), fn ($c) => $c->where('canal', $validado['canal']))
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w
                ->where('asunto', 'like', "%{$q}%")
                ->orWhere('destinatario', 'like', "%{$q}%")
                ->orWhereHas('persona', fn ($p) => $p->buscar($q))
                ->orWhereHas('usuario', fn ($u) => $u->where('name', 'like', "%{$q}%"))))
            ->when(isset($validado['desde']), fn ($c) => $c->where('created_at', '>=', $this->fechas->inicioDelDia((string) $validado['desde'])))
            ->when(isset($validado['hasta']), fn ($c) => $c->where('created_at', '<=', $this->fechas->finDelDia((string) $validado['hasta'])));

        // «Enviando» (tomado por una pasada, ADR 0209) es interno: se ve y se cuenta como «en cola».
        $estados = fn (string $estado): array => $estado === EstadoMensaje::Encolado->value
            ? [EstadoMensaje::Encolado->value, EstadoMensaje::Enviando->value]
            : [$estado];
        $pagina = $encontrados()
            ->when(isset($validado['estado']), fn ($c) => $c->whereIn('estado', $estados((string) $validado['estado'])))
            ->with(['persona', 'usuario'])
            ->orderByDesc('id')
            ->paginate((int) ($validado['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($validado['page'] ?? 1));
        $porEstado = $encontrados()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->toBase()->pluck('total', 'estado');
        $porEstado[EstadoMensaje::Encolado->value] = (int) ($porEstado[EstadoMensaje::Encolado->value] ?? 0) + (int) ($porEstado[EstadoMensaje::Enviando->value] ?? 0);

        return response()->json([
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => collect(EstadoMensaje::cases())
                    ->reject(fn (EstadoMensaje $e): bool => $e === EstadoMensaje::Enviando)
                    ->mapWithKeys(fn (EstadoMensaje $e): array => [$e->value => (int) ($porEstado[$e->value] ?? 0)])
                    ->all(),
            ],
            'data' => collect($pagina->items())->map(fn (MensajeTenant $m): array => [
                'id' => $m->ulid,
                // A quién se envió: el usuario del equipo (avisos al profesional) o la persona.
                'persona' => $m->usuario->name ?? $m->persona?->nombre,
                'canal' => $m->canal->value,
                'destinatario' => $m->destinatario,
                'asunto' => $m->asunto,
                'estado' => $m->estado === EstadoMensaje::Enviando ? EstadoMensaje::Encolado->value : $m->estado->value,
                'intentos' => $m->intentos,
                'evento_ulid' => $m->evento_ulid,
                'creado_en' => $m->created_at?->toIso8601String(),
                'enviado_en' => $m->enviado_en?->toIso8601String(),
                // WhatsApp (ADR 0074): según el aviso de Meta.
                'entregado_en' => $m->entregado_en?->toIso8601String(),
                'leido_en' => $m->leido_en?->toIso8601String(),
                'ultimo_error' => $m->ultimo_error,
            ])->all(),
        ]);
    }
}
