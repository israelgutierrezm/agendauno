<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ProspectosTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\MotivoCierreProspecto;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoProspectoTenant;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Prospectos del negocio para el equipo (ADR 0117): quien todavía no es cliente y dejó
 * sus datos. Aparte de Miembros. Un empleado acotado a sedes ve los de sus sedes y los
 * que no dijeron sede (cualquiera puede atenderlos).
 */
class ProspectosTenantController
{
    /** Lo que trae el detalle de su historial; lo demás, por páginas. */
    private const EVENTOS_RECIENTES = 30;

    private const POR_PAGINA = 50;

    public function __construct(
        private readonly ProspectosTenant $prospectos,
        private readonly ResolverAccesoTenant $acceso,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            // `abiertos` (por omisión), `todos` o un estado.
            'estado' => ['nullable', 'string', Rule::in(['abiertos', 'todos', ...array_map(static fn (EstadoProspecto $e): string => $e->value, EstadoProspecto::cases())])],
            // `yo`, `sin` o el ulid de alguien del equipo.
            'responsable' => ['nullable', 'string', 'max:40'],
            // `sin_atender`: su responsable no lo atendió a tiempo (ADR 0138).
            'atencion' => ['nullable', 'string', Rule::in(['sin_atender'])],
            // `vencido`: su próximo seguimiento ya pasó.
            'seguimiento' => ['nullable', 'string', Rule::in(['vencido'])],
            // Por dónde llegó: un formulario (y cuál) o capturado a mano.
            'origen' => ['nullable', Rule::enum(OrigenProspecto::class)],
            'formulario_id' => ['nullable', 'string', 'max:40'],
            'sucursal_id' => ['nullable', 'string', 'max:40'],
            // Cuándo llegó, en días del negocio: hoy o los últimos 7 o 30.
            'periodo' => ['nullable', 'string', Rule::in(['hoy', '7', '30'])],
            // `con`: llegó por una campaña (UTM, anuncio o enlace de otro sitio); `sin`, no.
            'campana' => ['nullable', 'string', Rule::in(['con', 'sin'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $actor = $this->actor($request);

        $consulta = $this->alcance(ProspectoTenant::query(), $actor)
            ->with(['sucursal', 'actividad', 'sesion.oferta', 'producto', 'responsable', 'formulario']);
        $estado = $validado['estado'] ?? 'abiertos';
        if ($estado === 'abiertos') {
            $consulta->whereIn('estado', EstadoProspecto::abiertos());
        } elseif ($estado !== 'todos') {
            $consulta->where('estado', $estado);
        }
        $responsable = $validado['responsable'] ?? null;
        if ($responsable === 'yo') {
            $consulta->where('responsable_id', $actor->getKey());
        } elseif ($responsable === 'sin') {
            $consulta->whereNull('responsable_id');
        } elseif (is_string($responsable) && $responsable !== '') {
            $consulta->where('responsable_id', Usuario::query()->where('ulid', $responsable)->value('id') ?? 0);
        }
        if (($validado['atencion'] ?? null) === 'sin_atender') {
            $consulta->whereNotNull('sin_atender_desde');
        }
        if (($validado['seguimiento'] ?? null) === 'vencido') {
            $consulta->where('proximo_seguimiento_en', '<=', now());
        }
        if (($validado['origen'] ?? '') !== '') {
            $consulta->where('origen', $validado['origen']);
        }
        if (($validado['formulario_id'] ?? '') !== '') {
            $consulta->where('formulario_id', FormularioCaptacionTenant::query()->where('ulid', $validado['formulario_id'])->value('id') ?? 0);
        }
        if (($validado['sucursal_id'] ?? '') !== '') {
            $consulta->where('sucursal_id', SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->value('id') ?? 0);
        }
        if (($validado['periodo'] ?? '') !== '') {
            $dias = $validado['periodo'] === 'hoy' ? 0 : (int) $validado['periodo'] - 1;
            $consulta->where('created_at', '>=', $this->fechas->inicioDelDia($this->fechas->dia()->subDays($dias)->toDateString()));
        }
        if (($validado['campana'] ?? '') !== '') {
            $conCampana = fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->whereNotNull('campana')->orWhereNotNull('fuente')->orWhereNotNull('referente'));
            if ($validado['campana'] === 'con') {
                $consulta->whereHas('contexto', $conCampana);
            } else {
                $consulta->whereDoesntHave('contexto', $conCampana);
            }
        }
        $q = trim((string) ($validado['q'] ?? ''));
        if ($q !== '') {
            $consulta->where(function (Builder $w) use ($q): void {
                $w->where('nombre', 'like', "%{$q}%")
                    ->orWhere('email', 'like', '%'.mb_strtolower($q).'%')
                    ->orWhere('telefono', 'like', '%'.preg_replace('/\D/', '', $q).'%');
            });
        }

        $pagina = $consulta->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoProspecto::Nuevo->value])
            ->orderByDesc('created_at')
            ->paginate((int) ($validado['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($validado['page'] ?? 1));

        // Lo que pide atención: nuevos sin contactar y seguimientos vencidos (de lo que ve).
        $base = $this->alcance(ProspectoTenant::query(), $actor)->whereIn('estado', EstadoProspecto::abiertos());

        return response()->json([
            'data' => collect($pagina->items())->map(fn (ProspectoTenant $p): array => $this->presentar($p))->all(),
            'meta' => [
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => [
                    'nuevos' => (clone $base)->where('estado', EstadoProspecto::Nuevo->value)->count(),
                    'seguimientos_vencidos' => (clone $base)->where('proximo_seguimiento_en', '<=', now())->count(),
                    'sin_atender' => (clone $base)->whereNotNull('sin_atender_desde')->count(),
                    'abiertos' => (clone $base)->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:telefono'],
            'telefono' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'sucursal_id' => ['nullable', 'string'],
        ]);
        $actor = $this->actor($request);
        $sucursal = isset($validado['sucursal_id']) && $validado['sucursal_id'] !== ''
            ? SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail()
            : null;
        abort_unless($this->acceso->permiteSucursal($actor, $sucursal?->getKey()) || $sucursal === null, 403);

        $prospecto = $this->prospectos->crearManual([
            'nombre' => (string) $validado['nombre'],
            'email' => $validado['email'] ?? null,
            'telefono' => $validado['telefono'] ?? null,
            'sucursal_id' => $sucursal?->getKey(),
        ], $actor);

        return response()->json(['data' => $this->presentar($prospecto->refresh(), detalle: true)], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->prospecto($request), detalle: true)]);
    }

    public function estado(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['required', Rule::enum(EstadoProspecto::class)],
            'motivo_cierre' => ['nullable', Rule::enum(MotivoCierreProspecto::class)],
            'comentario' => ['nullable', 'string', 'max:300'],
        ]);
        $prospecto = $this->prospectos->cambiarEstado(
            $this->prospecto($request),
            EstadoProspecto::from((string) $validado['estado']),
            $this->actor($request),
            isset($validado['motivo_cierre']) ? MotivoCierreProspecto::from((string) $validado['motivo_cierre']) : null,
            $validado['comentario'] ?? null,
        );

        return response()->json(['data' => $this->presentar($prospecto, detalle: true)]);
    }

    public function responsable(Request $request): JsonResponse
    {
        $validado = $request->validate(['responsable_id' => ['nullable', 'string']]);
        $responsable = isset($validado['responsable_id']) && $validado['responsable_id'] !== ''
            ? Usuario::query()->where('ulid', $validado['responsable_id'])->where('activo', true)->firstOrFail()
            : null;
        abort_if($responsable instanceof Usuario && ! $responsable->puede('prospectos.ver'), 422, 'Esa persona no atiende prospectos.');

        $prospecto = $this->prospectos->asignar($this->prospecto($request), $responsable, $this->actor($request));

        return response()->json(['data' => $this->presentar($prospecto, detalle: true)]);
    }

    /** Quiénes pueden atender prospectos, para elegir responsable (ADR 0138). */
    public function responsables(): JsonResponse
    {
        return response()->json(['data' => $this->prospectos->responsables()->map(static fn (Usuario $u): array => [
            'id' => $u->ulid,
            'nombre' => $u->name,
            'email' => $u->email,
        ])->all()]);
    }

    /** Le escribió o le llamó: queda en su historia (ADR 0138). */
    public function contacto(Request $request): JsonResponse
    {
        $validado = $request->validate(['canal' => ['required', 'string', Rule::in(['whatsapp', 'llamada', 'correo'])]]);
        $prospecto = $this->prospectos->registrarContacto($this->prospecto($request), (string) $validado['canal'], $this->actor($request));

        return response()->json(['data' => $this->presentar($prospecto, detalle: true)]);
    }

    public function seguimiento(Request $request): JsonResponse
    {
        $validado = $request->validate(['cuando' => ['nullable', 'date']]);
        $cuando = isset($validado['cuando']) ? CarbonImmutable::parse((string) $validado['cuando']) : null;
        $prospecto = $this->prospectos->programarSeguimiento($this->prospecto($request), $cuando, $this->actor($request));

        return response()->json(['data' => $this->presentar($prospecto, detalle: true)]);
    }

    public function nota(Request $request): JsonResponse
    {
        $validado = $request->validate(['texto' => ['required', 'string', 'max:1000']]);
        $prospecto = $this->prospectos->anotar($this->prospecto($request), (string) $validado['texto'], $this->actor($request));

        return response()->json(['data' => $this->presentar($prospecto, detalle: true)], 201);
    }

    /**
     * Darle acceso: una ficha (la que el equipo elige o una nueva) y, si se pide, la
     * invitación a su cuenta.
     */
    public function convertir(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['nullable', 'string'],
            'invitar' => ['required', 'boolean'],
            'liberar_celular' => ['nullable', 'boolean'],
        ]);
        $existente = isset($validado['persona_id']) && $validado['persona_id'] !== ''
            ? PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail()
            : null;
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $resultado = $this->prospectos->convertir(
            $this->prospecto($request), $estudio, $this->actor($request), $existente,
            (bool) $validado['invitar'], (bool) ($validado['liberar_celular'] ?? false),
        );

        return response()->json(['data' => [
            ...$this->presentar($resultado['prospecto'], detalle: true),
            'activacion' => app()->environment('production') || $resultado['token'] === null
                ? null
                : ['email' => $resultado['persona']->email, 'token' => $resultado['token']],
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->prospectos->eliminar($this->prospecto($request), $this->actor($request));

        return response()->json(null, 204);
    }

    /**
     * @param  Builder<ProspectoTenant>  $consulta
     * @return Builder<ProspectoTenant>
     */
    private function alcance(Builder $consulta, Usuario $actor): Builder
    {
        $permitidas = $this->acceso->sucursalesPermitidas($actor);
        if ($permitidas !== null) {
            $consulta->where(fn (Builder $w) => $w->whereNull('sucursal_id')->orWhereIn('sucursal_id', $permitidas));
        }

        return $consulta;
    }

    private function prospecto(Request $request): ProspectoTenant
    {
        return $this->alcance(ProspectoTenant::query(), $this->actor($request))
            ->where('ulid', (string) $request->route('prospecto'))
            ->firstOrFail();
    }

    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario, 401);

        return $actor;
    }

    /**
     * @return array{fuente: string|null, medio: string|null, campana: string|null, referente: string|null, primer_origen: array<string, string>|null}|null
     */
    private function campana(ProspectoTenant $p): ?array
    {
        $contexto = $p->contexto;
        if ($contexto === null) {
            return null;
        }
        $primer = $contexto->primer_toque;

        return [
            'fuente' => $contexto->fuente,
            'medio' => $contexto->medio,
            'campana' => $contexto->campana,
            'referente' => $contexto->referente,
            'primer_origen' => $primer !== null && $primer !== $contexto->ultimo_toque ? $primer : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ProspectoTenant $p, bool $detalle = false): array
    {
        $p->loadMissing(['sucursal', 'actividad', 'sesion.oferta', 'producto', 'responsable', 'formulario', 'persona']);

        return [
            'id' => $p->ulid,
            'nombre' => $p->nombre,
            'email' => $p->email,
            'telefono' => $p->telefono,
            'estado' => $p->estado->value,
            'origen' => $p->origen->value,
            'fuente' => $p->fuente,
            'formulario' => $p->formulario?->nombre,
            'interes' => [
                'sucursal' => $p->sucursal ? ['id' => $p->sucursal->ulid, 'nombre' => $p->sucursal->nombre] : null,
                'actividad' => $p->actividad ? ['id' => $p->actividad->ulid, 'nombre' => $p->actividad->nombre] : null,
                'sesion' => $p->sesion ? [
                    'id' => $p->sesion->ulid,
                    'clase' => $p->sesion->oferta?->nombre,
                    'inicia_en' => $p->sesion->inicia_en->toIso8601String(),
                    'zona_horaria' => $p->sesion->zona_horaria,
                ] : null,
                'producto' => $p->producto ? ['id' => $p->producto->ulid, 'nombre' => $p->producto->nombre] : null,
            ],
            'responsable' => $p->responsable ? ['id' => $p->responsable->ulid, 'nombre' => $p->responsable->name] : null,
            'proximo_seguimiento_en' => $p->proximo_seguimiento_en?->toIso8601String(),
            'primer_contacto_en' => $p->primer_contacto_en?->toIso8601String(),
            'sin_atender_desde' => $p->sin_atender_desde?->toIso8601String(),
            'motivo_cierre' => $p->motivo_cierre?->value,
            'comentario_cierre' => $p->comentario_cierre,
            'persona' => $p->persona ? ['id' => $p->persona->ulid, 'nombre' => $p->persona->nombre] : null,
            'convertido_en' => $p->convertido_en?->toIso8601String(),
            'creado_en' => $p->created_at->toIso8601String(),
            'respuestas' => $detalle ? ($p->respuestas ?? []) : null,
            // De dónde llegó (ADR 0142): la campaña acreditada y su primer origen; null =
            // sin atribución.
            'campana' => $detalle ? $this->campana($p) : null,
            // Lo más reciente de su historial; lo demás, por páginas con eventos().
            'eventos' => $detalle ? $p->eventos()->with('autor')->limit(self::EVENTOS_RECIENTES)->get()
                ->map(fn (EventoProspectoTenant $e): array => $this->presentarEvento($e))->all() : null,
            'eventos_total' => $detalle ? $p->eventos()->count() : null,
        ];
    }

    /**
     * Su historial por páginas, del más reciente al más viejo (un prospecto de meses
     * junta cientos de seguimientos).
     */
    public function eventos(Request $request): JsonResponse
    {
        $prospecto = ProspectoTenant::query()->where('ulid', (string) $request->route('prospecto'))->firstOrFail();
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $pagina = $prospecto->eventos()->with('autor')
            ->paginate((int) ($validado['per_page'] ?? self::EVENTOS_RECIENTES), ['*'], 'page', (int) ($validado['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (EventoProspectoTenant $e): array => $this->presentarEvento($e))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarEvento(EventoProspectoTenant $e): array
    {
        return [
            'tipo' => $e->tipo->value,
            'detalle' => $e->detalle ?? [],
            'autor' => $e->autor?->name,
            'en' => $e->created_at->toIso8601String(),
        ];
    }
}
