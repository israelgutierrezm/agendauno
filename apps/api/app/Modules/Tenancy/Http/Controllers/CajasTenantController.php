<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CajasTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\ConteoCajaTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\TurnoCajaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La caja de cada sucursal (ADR 0175): abrirla con su fondo, mover efectivo que no es
 * venta, contarla a ciegas y cerrarla; y, para quien supervisa, lo esperado en vivo,
 * los cierres y la revisión de sus diferencias.
 *
 * Quien solo opera (`caja.operar`) no ve lo esperado ni los cobros del turno mientras
 * la caja está abierta: el conteo es a ciegas. Ya contada, ve el resultado.
 */
class CajasTenantController
{
    public function __construct(
        private readonly CajasTenant $cajas,
        private readonly ResolverAccesoTenant $acceso,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /** La caja de una sede: la que está sin cerrar (o null) y si la sede ya usa caja. */
    public function actual(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $sucursal = $this->sucursal($request, (string) $request->query('sucursal_id', ''), $actor);
        $turno = TurnoCajaTenant::query()->where('sucursal_abierta', $sucursal->getKey())->first();

        return response()->json(['data' => [
            'sucursal' => ['id' => $sucursal->ulid, 'nombre' => $sucursal->nombre],
            'usa_caja' => $turno instanceof TurnoCajaTenant || TurnoCajaTenant::query()->where('sucursal_id', $sucursal->getKey())->exists(),
            'turno' => $turno instanceof TurnoCajaTenant ? $this->presentar($turno, $actor->puede('caja.supervisar'), true) : null,
        ]]);
    }

    public function abrir(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'fondo_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'nota' => ['nullable', 'string', 'max:300'],
        ]);
        $sucursal = $this->sucursal($request, (string) $validado['sucursal_id'], $actor);
        $turno = $this->cajas->abrir($sucursal, (int) $validado['fondo_minor'], $validado['nota'] ?? null, $actor);

        return response()->json(['data' => $this->presentar($turno, $actor->puede('caja.supervisar'), true)], 201);
    }

    /** Un retiro o un ingreso de efectivo que no es venta. */
    public function moverEfectivo(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $turno = $this->turno($request, $actor);
        $validado = $request->validate([
            'tipo' => ['required', Rule::in([MovimientoCajaTenant::RETIRO, MovimientoCajaTenant::INGRESO])],
            'monto_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
            'clave' => ['nullable', 'string', 'max:64'],
        ]);
        $this->cajas->moverEfectivo($turno, (string) $validado['tipo'], (int) $validado['monto_minor'], (string) $validado['motivo'], $actor, $validado['clave'] ?? null);

        return response()->json(['data' => $this->presentar($turno->refresh(), $actor->puede('caja.supervisar'), true)], 201);
    }

    /** El conteo a ciegas: lo contado de cada forma de pago, una sola vez. */
    public function contar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $turno = $this->turno($request, $actor);
        $reglas = [];
        foreach (CajasTenant::METODOS as $metodo) {
            $reglas["{$metodo}_minor"] = ['nullable', 'integer', $metodo === 'efectivo' ? 'min:0' : 'min:-1000000000', 'max:1000000000'];
        }
        $validado = $request->validate($reglas);
        $contado = [];
        foreach (CajasTenant::METODOS as $metodo) {
            $contado[$metodo] = (int) ($validado["{$metodo}_minor"] ?? 0);
        }
        $turno = $this->cajas->contar($turno, $contado, $actor);

        return response()->json(['data' => [
            ...$this->presentar($turno, $actor->puede('caja.supervisar'), true),
            'cuadra' => $turno->estado === TurnoCajaTenant::CERRADA,
        ]]);
    }

    /** Cierra la caja contada que no cuadró, con el motivo. */
    public function cerrar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $turno = $this->turno($request, $actor);
        $validado = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $turno = $this->cajas->cerrarConMotivo($turno, (string) $validado['motivo'], $actor);

        return response()->json(['data' => $this->presentar($turno, $actor->puede('caja.supervisar'), true)]);
    }

    /** Los turnos de caja (quien supervisa), del más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $filtros = $request->validate([
            'sucursal_id' => ['nullable', 'string'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'revision' => ['nullable', Rule::in(['pendiente'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $permitidas = $this->acceso->sucursalesPermitidas($actor);
        $sucursal = isset($filtros['sucursal_id']) ? $this->sucursal($request, (string) $filtros['sucursal_id'], $actor) : null;

        $consulta = TurnoCajaTenant::query()
            ->with(['sucursal', 'abiertaPor', 'cerradaPor', 'revisadaPor'])
            ->when($sucursal !== null, fn ($q) => $q->where('sucursal_id', $sucursal?->getKey()))
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->when(isset($filtros['desde']), fn ($q) => $q->where('abierta_en', '>=', $this->fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn ($q) => $q->where('abierta_en', '<=', $this->fechas->finDelDia((string) $filtros['hasta'])))
            ->when(($filtros['revision'] ?? null) === 'pendiente', fn ($q) => $q->where('requiere_revision', true)->whereNull('revisada_en'))
            ->orderByDesc('abierta_en')
            ->orderByDesc('id');

        $porPagina = (int) ($filtros['per_page'] ?? 20);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);

        return response()->json([
            'data' => $consulta->forPage($pagina, $porPagina)->get()
                ->map(fn (TurnoCajaTenant $t): array => $this->presentar($t, true, false))->values()->all(),
            'meta' => ['page' => $pagina, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $porPagina],
        ]);
    }

    /** Un turno con su libro y su conteo (quien supervisa). */
    public function show(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(['data' => $this->presentar($this->turno($request, $actor), true, true)]);
    }

    /** El admin revisa la diferencia de un cierre. */
    public function revisar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $turno = $this->turno($request, $actor);
        $validado = $request->validate(['nota' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->presentar($this->cajas->revisar($turno, (string) $validado['nota'], $actor), true, true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(TurnoCajaTenant $turno, bool $supervisa, bool $conLibro): array
    {
        $turno->loadMissing(['sucursal', 'abiertaPor', 'cerradaPor', 'revisadaPor']);
        $abierta = $turno->estado === TurnoCajaTenant::ABIERTA;
        // A ciegas: con la caja abierta, quien solo opera ve sus retiros e ingresos, no lo
        // esperado ni los cobros.
        $ciega = $abierta && ! $supervisa;
        $libro = [];
        if ($conLibro) {
            $libro = MovimientoCajaTenant::query()
                ->with(['usuario', 'pago', 'venta'])
                ->where('turno_caja_id', $turno->getKey())
                ->when($ciega, fn ($q) => $q->whereIn('tipo', [MovimientoCajaTenant::RETIRO, MovimientoCajaTenant::INGRESO]))
                ->orderBy('id')
                ->get()
                ->map(fn (MovimientoCajaTenant $m): array => [
                    'id' => $m->ulid,
                    'tipo' => $m->tipo,
                    'metodo' => $m->metodo,
                    'monto_minor' => $m->monto_minor,
                    'motivo' => $m->motivo,
                    'quien' => $m->usuario?->name,
                    'fecha' => $m->created_at?->toIso8601String(),
                    'pago' => $m->pago?->ulid,
                    'venta' => $m->venta?->ulid,
                ])->values()->all();
        }

        return [
            'id' => $turno->ulid,
            'sucursal' => ['id' => $turno->sucursal?->ulid, 'nombre' => $turno->sucursal?->nombre],
            'estado' => $turno->estado,
            'moneda' => $turno->moneda,
            'fondo_minor' => $turno->fondo_minor,
            'nota_apertura' => $turno->nota_apertura,
            'abierta_por' => $turno->abiertaPor?->name,
            'abierta_en' => $turno->abierta_en->toIso8601String(),
            'contada_en' => $turno->contada_en?->toIso8601String(),
            'cerrada_por' => $turno->cerradaPor?->name,
            'cerrada_en' => $turno->cerrada_en?->toIso8601String(),
            'diferencia_minor' => $ciega ? null : $turno->diferencia_minor,
            'motivo_diferencia' => $turno->motivo_diferencia,
            'requiere_revision' => $turno->requiere_revision,
            'revisada_por' => $turno->revisadaPor?->name,
            'revisada_en' => $turno->revisada_en?->toIso8601String(),
            'nota_revision' => $turno->nota_revision,
            'esperado' => $abierta && $supervisa ? $this->cajas->esperado($turno) : null,
            'conteos' => $abierta ? [] : ConteoCajaTenant::query()->where('turno_caja_id', $turno->getKey())->orderBy('id')->get()
                ->map(fn (ConteoCajaTenant $c): array => [
                    'metodo' => $c->metodo,
                    'esperado_minor' => $c->esperado_minor,
                    'contado_minor' => $c->contado_minor,
                    'diferencia_minor' => $c->diferencia_minor,
                ])->values()->all(),
            'movimientos' => $libro,
        ];
    }

    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario, 401);

        return $actor;
    }

    /** La sede pedida, si es una de las de quien opera. */
    private function sucursal(Request $request, string $ulid, Usuario $actor): SucursalTenant
    {
        $sucursal = $ulid !== '' ? SucursalTenant::query()->where('ulid', $ulid)->first() : null;
        if (! $sucursal instanceof SucursalTenant) {
            throw ValidationException::withMessages(['sucursal_id' => ['Elige la sucursal de la caja.']]);
        }
        abort_unless($this->acceso->permiteSucursal($actor, (int) $sucursal->getKey()), 403, 'Esa sucursal no es de las tuyas.');

        return $sucursal;
    }

    /** El turno de la ruta, si es de una sede de quien opera. */
    private function turno(Request $request, Usuario $actor): TurnoCajaTenant
    {
        $turno = TurnoCajaTenant::query()->where('ulid', (string) $request->route('turno'))->firstOrFail();
        abort_unless($this->acceso->permiteSucursal($actor, $turno->sucursal_id), 403, 'Esa caja no es de tus sucursales.');

        return $turno;
    }
}
