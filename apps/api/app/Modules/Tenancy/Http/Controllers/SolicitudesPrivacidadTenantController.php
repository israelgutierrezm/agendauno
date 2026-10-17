<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Solicitudes de baja de datos (ARCO) que el negocio atiende o rechaza con motivo.
 * La ley pide responder en un plazo de 20 días hábiles.
 */
class SolicitudesPrivacidadTenantController
{
    /** Plazo para responder, en días hábiles. */
    private const DIAS_HABILES = 20;

    /** «Por vencer»: pendientes a las que les quedan estos días hábiles o menos. */
    private const AVISO_DIAS_HABILES = 5;

    public function __construct(
        private readonly BajaDePersonaTenant $baja,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Las pendientes primero y luego las más recientes. Con `page`, por páginas (20 por
     * omisión) y con cuántas siguen pendientes y cuántas ya vencieron; sin ella, las
     * últimas 200 como antes. Se buscan por nombre (`q`) y se filtran por `estado` y
     * por `vencimiento` (`vencidas` o `por_vencer`, solo pendientes).
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in([SolicitudPrivacidadTenant::PENDIENTE, SolicitudPrivacidadTenant::ATENDIDA, SolicitudPrivacidadTenant::RECHAZADA])],
            'vencimiento' => ['nullable', Rule::in(['vencidas', 'por_vencer'])],
        ]);
        $plazos = $this->plazosPendientes();
        $consulta = SolicitudPrivacidadTenant::query()->with('persona')
            ->when($validado['q'] ?? null, fn (Builder $q, string $texto) => $q->whereHas('persona', fn (Builder $p) => $p->buscar($texto)))
            ->when($validado['estado'] ?? null, fn (Builder $q, string $estado) => $q->where('estado', $estado))
            ->when($validado['vencimiento'] ?? null, fn (Builder $q, string $cual) => $q->whereIn('id', $plazos[$cual]))
            ->orderByRaw("case when estado = 'pendiente' then 0 else 1 end")->orderByDesc('id');

        if (! isset($validado['page'])) {
            return response()->json(['data' => $consulta->limit(200)->get()->map(fn (SolicitudPrivacidadTenant $s): array => $this->presentar($s))->all()]);
        }

        $pagina = $consulta->paginate((int) ($validado['per_page'] ?? 20), ['*'], 'page', (int) $validado['page']);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (SolicitudPrivacidadTenant $s): array => $this->presentar($s))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'pendientes' => SolicitudPrivacidadTenant::query()->where('estado', SolicitudPrivacidadTenant::PENDIENTE)->count(),
                'vencidas' => count($plazos['vencidas']),
                'por_vencer' => count($plazos['por_vencer']),
            ],
        ]);
    }

    public function atender(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);

        return response()->json(['data' => $this->presentar($this->baja->atender($solicitud, $this->actor($request))->load('persona'))]);
    }

    public function rechazar(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);
        $validado = $request->validate(['respuesta' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->presentar($this->baja->rechazar($solicitud, (string) $validado['respuesta'], $this->actor($request))->load('persona'))]);
    }

    /**
     * Las pendientes vencidas y por vencer, con el mismo plazo que se muestra (las
     * pendientes son pocas: se atienden o vencen).
     *
     * @return array{vencidas: list<int>, por_vencer: list<int>}
     */
    private function plazosPendientes(): array
    {
        $hoy = $this->fechas->hoy();
        $aviso = CarbonImmutable::parse($hoy)->addWeekdays(self::AVISO_DIAS_HABILES)->toDateString();
        $plazos = ['vencidas' => [], 'por_vencer' => []];
        SolicitudPrivacidadTenant::query()->where('estado', SolicitudPrivacidadTenant::PENDIENTE)->get(['id', 'created_at'])
            ->each(function (SolicitudPrivacidadTenant $s) use ($hoy, $aviso, &$plazos): void {
                $vence = $this->venceEn($s);
                if ($vence === null) {
                    return;
                }
                if ($vence < $hoy) {
                    $plazos['vencidas'][] = (int) $s->getKey();
                } elseif ($vence <= $aviso) {
                    $plazos['por_vencer'][] = (int) $s->getKey();
                }
            });

        return $plazos;
    }

    /** El último día para responder, contado en los días del negocio. */
    private function venceEn(SolicitudPrivacidadTenant $s): ?string
    {
        if ($s->created_at === null) {
            return null;
        }

        return CarbonImmutable::parse($this->fechas->fecha($s->created_at))->addWeekdays(self::DIAS_HABILES)->toDateString();
    }

    private function solicitud(Request $request): SolicitudPrivacidadTenant
    {
        return SolicitudPrivacidadTenant::query()->where('ulid', (string) $request->route('solicitud'))->with('persona')->firstOrFail();
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(SolicitudPrivacidadTenant $s): array
    {
        return [
            'id' => $s->ulid,
            'persona' => $s->persona?->nombreCompleto(),
            'persona_id' => $s->persona?->ulid,
            'tipo' => $s->tipo,
            'estado' => $s->estado,
            'motivo' => $s->motivo,
            'respuesta' => $s->respuesta,
            'solicitada_en' => $s->created_at?->toIso8601String(),
            'atendida_en' => $s->atendida_en?->toIso8601String(),
            // Plazo de respuesta: 20 días hábiles (aprox. 28 naturales), en los días del negocio.
            'vence_en' => $this->venceEn($s),
        ];
    }
}
