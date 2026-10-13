<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El apartado de Reportes de Wellhub y TotalPass (ADR 0148): quiénes vinieron por cada
 * plataforma y sus visitas. Son clientes de la plataforma, no del negocio: aquí se ven
 * con lo que la plataforma manda, sin mezclarse con los alumnos. Si el negocio ya
 * registró a alguien con el mismo correo, se dice que ya es alumno.
 *
 * - `GET /reportes/plataformas`: el resumen de un periodo (días del negocio).
 * - `GET /visitantes-plataforma`: los visitantes, por página, con búsqueda.
 * - `GET /visitantes-plataforma/{visitante}`: uno, con sus visitas.
 */
class VisitantesPlataformaTenantController
{
    private const POR_PAGINA = 25;

    /** El resumen recorre hasta un año. */
    private const DIAS_MAXIMOS = 366;

    /** Estados de una visita que cuentan como visita (las rechazadas no se guardan). */
    private const VISITAS = [CheckinTenant::VALIDADO, CheckinTenant::NO_VERIFICABLE, CheckinTenant::EXCEPCION];

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    public function resumen(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'proveedor' => ['nullable', Rule::in(ProveedorPartner::valores())],
        ]);
        if (CarbonImmutable::parse($validado['desde'])->diffInDays(CarbonImmutable::parse($validado['hasta'])) >= self::DIAS_MAXIMOS) {
            throw ValidationException::withMessages(['hasta' => ['Elige un periodo de un año o menos.']]);
        }
        $inicio = $this->fechas->inicioDelDia((string) $validado['desde']);
        $fin = $this->fechas->finDelDia((string) $validado['hasta']);
        $proveedor = $validado['proveedor'] ?? null;

        /** @var Collection<int, CheckinTenant> $visitas */
        $visitas = CheckinTenant::query()
            ->with('sesion.oferta')
            ->whereIn('estado', self::VISITAS)
            ->whereBetween('registrado_en', [$inicio, $fin])
            ->when($proveedor !== null, fn (Builder $q) => $q->where('proveedor', $proveedor))
            ->get(['id', 'sesion_id', 'proveedor', 'estado', 'origen', 'visitante_id', 'registrado_en']);

        $nuevos = VisitantePlataformaTenant::query()
            ->whereBetween('primera_visita_en', [$inicio, $fin])
            ->when($proveedor !== null, fn (Builder $q) => $q->where('proveedor', $proveedor))
            ->count();

        $porDia = $visitas
            ->groupBy(fn (CheckinTenant $c): string => $this->fechas->fecha($c->registrado_en))
            ->map(fn (Collection $dia, string $fecha): array => ['fecha' => $fecha, 'visitas' => $dia->count()])
            ->sortKeys()
            ->values()
            ->all();

        $porClase = $visitas
            ->filter(fn (CheckinTenant $c): bool => $c->sesion?->oferta !== null)
            ->groupBy(fn (CheckinTenant $c): string => (string) $c->sesion?->oferta?->nombre)
            ->map(fn (Collection $grupo, string $clase): array => ['clase' => $clase, 'visitas' => $grupo->count()])
            ->sortByDesc('visitas')
            ->take(10)
            ->values()
            ->all();

        return response()->json(['data' => [
            'resumen' => [
                'visitas' => $visitas->count(),
                'validadas' => $visitas->where('estado', CheckinTenant::VALIDADO)->count(),
                'sin_verificar' => $visitas->where('estado', CheckinTenant::NO_VERIFICABLE)->count(),
                'excepciones' => $visitas->where('estado', CheckinTenant::EXCEPCION)->count(),
                'automaticas' => $visitas->where('origen', CheckinTenant::ORIGEN_AUTOMATICO)->count(),
                'sin_clase' => $visitas->whereNull('sesion_id')->count(),
                'visitantes' => $visitas->pluck('visitante_id')->filter()->unique()->count(),
                'nuevos' => $nuevos,
            ],
            'por_proveedor' => array_map(fn (ProveedorPartner $p): array => [
                'proveedor' => $p->value,
                'visitas' => $visitas->where('proveedor', $p->value)->count(),
                'visitantes' => $visitas->where('proveedor', $p->value)->pluck('visitante_id')->filter()->unique()->count(),
            ], ProveedorPartner::cases()),
            'por_dia' => $porDia,
            'por_clase' => $porClase,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'proveedor' => ['nullable', Rule::in(ProveedorPartner::valores())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($filtros['q'] ?? ''));
        $pagina = VisitantePlataformaTenant::query()
            ->withCount(['checkins as visitas' => fn (Builder $c) => $c->whereIn('estado', self::VISITAS)])
            ->when(($filtros['proveedor'] ?? null) !== null, fn (Builder $c) => $c->where('proveedor', $filtros['proveedor']))
            ->when($q !== '', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('telefono', 'like', "%{$q}%")
                ->orWhere('id_externo', 'like', "%{$q}%")))
            ->orderByDesc('ultima_visita_en')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        $visitantes = collect($pagina->items());
        $alumnos = $this->correosDeAlumnos($visitantes);

        return response()->json([
            'data' => $visitantes->map(fn (VisitantePlataformaTenant $v): array => $this->presentar($v, $alumnos))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $visitante = VisitantePlataformaTenant::query()
            ->withCount(['checkins as visitas' => fn (Builder $c) => $c->whereIn('estado', self::VISITAS)])
            ->where('ulid', (string) $request->route('visitante'))
            ->firstOrFail();
        $visitas = CheckinTenant::query()
            ->with('sesion.oferta')
            ->where('visitante_id', $visitante->getKey())
            ->whereIn('estado', self::VISITAS)
            ->orderByDesc('registrado_en')
            ->limit(100)
            ->get();

        return response()->json(['data' => [
            ...$this->presentar($visitante, $this->correosDeAlumnos(collect([$visitante]))),
            'historial' => $visitas->map(fn (CheckinTenant $c): array => [
                'id' => $c->ulid,
                'fecha' => $c->registrado_en->toIso8601String(),
                'clase' => $c->sesion?->oferta?->nombre,
                'sesion_inicia_en' => $c->sesion?->inicia_en->toIso8601String(),
                'estado' => $c->estado,
                'origen' => $c->origen,
            ])->all(),
        ]]);
    }

    /**
     * @param  array<string, bool>  $alumnos  correos de personas del negocio
     * @return array<string, mixed>
     */
    private function presentar(VisitantePlataformaTenant $v, array $alumnos): array
    {
        return [
            'id' => $v->ulid,
            'proveedor' => $v->proveedor,
            'nombre' => $v->nombre,
            'email' => $v->email,
            'telefono' => $v->telefono,
            'producto' => $v->producto,
            // El id en la plataforma, para reconocerlo en su portal.
            'id_externo' => $v->id_externo,
            'visitas' => (int) ($v->getAttribute('visitas') ?? 0),
            'primera_visita_en' => $v->primera_visita_en?->toIso8601String(),
            'ultima_visita_en' => $v->ultima_visita_en?->toIso8601String(),
            // El negocio ya lo registró por la vía de siempre (mismo correo).
            'es_alumno' => $v->email !== null && isset($alumnos[mb_strtolower($v->email)]),
        ];
    }

    /**
     * Los correos de los visitantes que ya son personas del negocio.
     *
     * @param  Collection<int, VisitantePlataformaTenant>  $visitantes
     * @return array<string, bool>
     */
    private function correosDeAlumnos(Collection $visitantes): array
    {
        $correos = $visitantes->pluck('email')->filter()->map(fn (string $e): string => mb_strtolower($e))->unique()->values()->all();
        if ($correos === []) {
            return [];
        }

        // Sin importar mayúsculas (como se capture el correo en cada lado).
        return PersonaTenant::query()
            ->whereIn(DB::raw('lower(email)'), $correos)
            ->pluck('email')
            ->mapWithKeys(fn (string $e): array => [mb_strtolower($e) => true])
            ->all();
    }
}
