<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\LibroPuntosTenant;
use App\Modules\Tenancy\Application\PuntosTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Lealtad\EstadoCanje;
use App\Modules\Tenancy\Listeners\AcumularPuntos;
use App\Modules\Tenancy\Models\CanjeLealtadTenant;
use App\Modules\Tenancy\Models\MovimientoPuntosTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProgramaLealtadTenant;
use App\Modules\Tenancy\Models\RecompensaLealtadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lealtad (R24): programa de puntos del estudio. Configura las reglas de acumulación y
 * el catálogo de recompensas, canjea recompensas para un miembro, y consulta/ajusta el
 * saldo de puntos. La acumulación automática (asistir/comprar) la hace el listener
 * {@see AcumularPuntos} sobre el outbox.
 */
class LealtadTenantController
{
    public function __construct(
        private readonly PuntosTenant $puntos,
        private readonly LibroPuntosTenant $libro,
    ) {}

    public function programa(): JsonResponse
    {
        return response()->json(['data' => $this->presentarPrograma($this->programaActual())]);
    }

    public function guardarPrograma(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            'puntos_por_asistencia' => ['required', 'integer', 'min:0', 'max:100000'],
            'puntos_por_moneda' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        // Activo sin puntos por asistir ni por comprar, nadie juntaría nada.
        if ((bool) $validado['activa'] && (int) $validado['puntos_por_asistencia'] === 0 && (int) $validado['puntos_por_moneda'] === 0) {
            throw ValidationException::withMessages(['activa' => ['Para activarlo define cuántos puntos se ganan por asistir o por comprar.']]);
        }

        $programa = $this->programaActual();
        $antes = $programa->only(['activa', 'puntos_por_asistencia', 'puntos_por_moneda']);
        $programa->update([
            'activa' => (bool) $validado['activa'],
            'puntos_por_asistencia' => (int) $validado['puntos_por_asistencia'],
            'puntos_por_moneda' => (int) $validado['puntos_por_moneda'],
        ]);
        $despues = $programa->only(['activa', 'puntos_por_asistencia', 'puntos_por_moneda']);
        if ($antes != $despues) {
            app(RegistrarAuditoria::class)->registrar($this->actor($request), 'lealtad.programa', 'programa_lealtad', (string) $programa->getKey(), $antes, $despues);
        }

        return response()->json(['data' => $this->presentarPrograma($programa)]);
    }

    public function recompensas(): JsonResponse
    {
        $recompensas = RecompensaLealtadTenant::query()->orderByDesc('activa')->orderBy('costo_puntos')->get();

        return response()->json(['data' => $recompensas->map(fn (RecompensaLealtadTenant $r): array => $this->presentarRecompensa($r))->all()]);
    }

    public function crearRecompensa(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'costo_puntos' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $recompensa = RecompensaLealtadTenant::query()->create([
            'nombre' => $validado['nombre'],
            'descripcion' => $validado['descripcion'] ?? null,
            'costo_puntos' => (int) $validado['costo_puntos'],
            'activa' => true,
        ]);
        app(RegistrarAuditoria::class)->registrar($this->actor($request), 'lealtad.recompensa_creada', 'recompensa_lealtad', (string) $recompensa->ulid, null, $recompensa->only(['nombre', 'costo_puntos', 'activa']));

        return response()->json(['data' => $this->presentarRecompensa($recompensa)], 201);
    }

    public function actualizarRecompensa(Request $request): JsonResponse
    {
        $modelo = RecompensaLealtadTenant::query()->where('ulid', (string) $request->route('recompensa'))->firstOrFail();

        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'costo_puntos' => ['required', 'integer', 'min:1', 'max:1000000'],
            'activa' => ['required', 'boolean'],
        ]);

        $antes = $modelo->only(['nombre', 'descripcion', 'costo_puntos', 'activa']);
        $modelo->update([
            'nombre' => $validado['nombre'],
            'descripcion' => $validado['descripcion'] ?? null,
            'costo_puntos' => (int) $validado['costo_puntos'],
            'activa' => (bool) $validado['activa'],
        ]);
        $despues = $modelo->only(['nombre', 'descripcion', 'costo_puntos', 'activa']);
        if ($antes != $despues) {
            app(RegistrarAuditoria::class)->registrar($this->actor($request), 'lealtad.recompensa_actualizada', 'recompensa_lealtad', (string) $modelo->ulid, $antes, $despues);
        }

        return response()->json(['data' => $this->presentarRecompensa($modelo)]);
    }

    public function canjear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'recompensa_id' => ['required', 'string'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);
        $recompensa = RecompensaLealtadTenant::query()->where('ulid', $validado['recompensa_id'])->where('activa', true)->firstOrFail();

        $canje = $this->puntos->canjear($persona->getKey(), $recompensa, $this->actor($request));

        return response()->json(['data' => $this->presentarCanje($canje->loadMissing('persona'))], 201);
    }

    /**
     * Los canjes por páginas (antes, solo los últimos 100). `pendientes`: los que faltan
     * por entregar, del más antiguo al más reciente (se atienden en orden); `historial`:
     * entregados y cancelados, del más reciente. Sin vista, los pendientes primero.
     */
    public function canjes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'vista' => ['nullable', 'in:pendientes,historial'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $vista = $filtros['vista'] ?? null;
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $pendiente = EstadoCanje::Pendiente->value;
        $consulta = app(AlcancePersonaTenant::class)->acotada(CanjeLealtadTenant::query(), $request)
            ->with('persona')
            ->when($buscar !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('persona', fn (Builder $p) => $p->buscar($buscar))
                ->orWhere('recompensa_nombre', 'like', "%{$buscar}%")));
        $conteos = [
            'pendientes' => (clone $consulta)->where('estado', $pendiente)->count(),
            'historial' => (clone $consulta)->where('estado', '!=', $pendiente)->count(),
        ];
        match ($vista) {
            'pendientes' => $consulta->where('estado', $pendiente)->orderBy('id'),
            'historial' => $consulta->where('estado', '!=', $pendiente)->orderByDesc('id'),
            default => $consulta->orderByRaw('case when estado = ? then 0 else 1 end', [$pendiente])->orderByDesc('id'),
        };
        $pagina = $consulta->paginate((int) ($filtros['per_page'] ?? 20), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (CanjeLealtadTenant $c): array => $this->presentarCanje($c))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => $conteos,
                // Para los indicadores, sin búsqueda: cuántos esperan y cuántos puntos
                // se han entregado en total.
                'resumen' => [
                    'pendientes' => CanjeLealtadTenant::query()->where('estado', $pendiente)->count(),
                    'puntos_entregados' => (int) CanjeLealtadTenant::query()->where('estado', EstadoCanje::Entregado->value)->sum('puntos'),
                ],
            ],
        ]);
    }

    public function entregarCanje(Request $request): JsonResponse
    {
        $modelo = CanjeLealtadTenant::query()->where('ulid', (string) $request->route('canje'))->firstOrFail();
        $this->puntos->entregarCanje($modelo);

        return response()->json(['data' => $this->presentarCanje($modelo->loadMissing('persona'))]);
    }

    public function cancelarCanje(Request $request): JsonResponse
    {
        $modelo = CanjeLealtadTenant::query()->where('ulid', (string) $request->route('canje'))->firstOrFail();
        $actualizado = $this->puntos->cancelarCanje($modelo, $this->actor($request));

        return response()->json(['data' => $this->presentarCanje($actualizado->loadMissing('persona'))]);
    }

    public function puntosMiembro(Request $request): JsonResponse
    {
        $modelo = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $modelo);

        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $consulta = MovimientoPuntosTenant::query()
            ->where('persona_id', $modelo->getKey())
            ->orderByDesc('id');
        // Con `page`, por páginas (un cliente frecuente suma decenas al mes); sin ella,
        // los últimos 50.
        $pagina = isset($validado['page'])
            ? $consulta->paginate((int) ($validado['per_page'] ?? 20), ['*'], 'page', (int) $validado['page'])
            : null;
        $movimientos = $pagina !== null ? collect($pagina->items()) : $consulta->limit(50)->get();

        return response()->json(['data' => [
            'persona' => $modelo->nombreCompleto(),
            'saldo' => $this->libro->saldo($modelo->getKey()),
            'movimientos' => $movimientos->map(fn (MovimientoPuntosTenant $m): array => $this->presentarMovimiento($m))->values()->all(),
        ], ...($pagina !== null ? ['meta' => [
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'ultima_pagina' => $pagina->lastPage(),
        ]] : [])]);
    }

    public function ajustar(Request $request): JsonResponse
    {
        $modelo = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $modelo);

        $validado = $request->validate([
            'puntos' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $movimiento = $this->puntos->ajustar(
            $modelo->getKey(), (int) $validado['puntos'], $validado['descripcion'] ?? null, $this->actor($request),
        );

        return response()->json(['data' => [
            'saldo' => $this->libro->saldo($modelo->getKey()),
            'movimiento' => $this->presentarMovimiento($movimiento),
        ]], 201);
    }

    private function programaActual(): ProgramaLealtadTenant
    {
        return ProgramaLealtadTenant::query()->firstOrCreate([], [
            'activa' => false, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 0,
        ]);
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarPrograma(ProgramaLealtadTenant $p): array
    {
        return [
            'activa' => $p->activa,
            'puntos_por_asistencia' => $p->puntos_por_asistencia,
            'puntos_por_moneda' => $p->puntos_por_moneda,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarRecompensa(RecompensaLealtadTenant $r): array
    {
        return [
            'id' => $r->ulid,
            'nombre' => $r->nombre,
            'descripcion' => $r->descripcion,
            'costo_puntos' => $r->costo_puntos,
            'activa' => $r->activa,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarCanje(CanjeLealtadTenant $c): array
    {
        return [
            'id' => $c->ulid,
            'persona' => $c->persona?->nombreCompleto(),
            'recompensa' => $c->recompensa_nombre,
            'puntos' => $c->puntos,
            'estado' => $c->estado->value,
            'entregado_en' => $c->entregado_en?->toIso8601String(),
            'creado_en' => $c->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarMovimiento(MovimientoPuntosTenant $m): array
    {
        return [
            'id' => $m->ulid,
            'tipo' => $m->tipo->value,
            'origen' => $m->origen->value,
            'puntos' => $m->puntos,
            'saldo_posterior' => $m->saldo_posterior,
            'descripcion' => $m->descripcion,
            'creado_en' => $m->created_at?->toIso8601String(),
        ];
    }
}
