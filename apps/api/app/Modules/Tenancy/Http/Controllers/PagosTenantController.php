<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AnularCobroTenant;
use App\Modules\Tenancy\Application\CorregirMetodoPagoTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pagos capturados del estudio (data plane del tenant): base de la pantalla de
 * cobranza para consultar cobros y emitir reembolsos. Solo pagos con dinero recibido
 * (aprobados / total o parcialmente reembolsados); los pendientes/rechazados no
 * aplican. Incluye el monto ya reembolsado y el reembolsable restante. Quien está
 * acotado a sedes (R19) solo ve, corrige o anula cobros de compras de las suyas.
 */
class PagosTenantController
{
    private const POR_PAGINA = 25;

    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Los movimientos de cobro, por páginas y del más reciente al más antiguo: se busca
     * por persona y se acotan por estado (aprobados u otros) y por fechas (días del
     * negocio). `meta.resumen` cuenta y suma todo lo encontrado (no solo la página),
     * sin el filtro de estado: así los indicadores no cambian al elegirlo.
     */
    public function index(Request $request, AnularCobroTenant $anular, CorregirMetodoPagoTenant $corregir): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['aprobado', 'otros'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }

        $actor = $request->attributes->get('usuario_tenant');
        $sedes = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;
        $q = trim((string) ($filtros['q'] ?? ''));
        $encontrados = fn (): Builder => PagoTenant::query()
            ->when($sedes !== null, fn ($c) => $c->whereHas('orden', fn ($o) => $o->whereIn('sucursal_id', $sedes)))
            ->whereIn('estado', [
                EstadoPago::Aprobado->value,
                EstadoPago::ParcialmenteReembolsado->value,
                EstadoPago::Reembolsado->value,
            ])
            ->when($q !== '', fn ($c) => $c->whereHas('orden.persona', fn ($p) => $p->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('primer_apellido', 'like', "%{$q}%")
                ->orWhere('segundo_apellido', 'like', "%{$q}%"))))
            ->when($desde !== null, fn ($c) => $c->where('created_at', '>=', $this->fechas->inicioDelDia((string) $desde)))
            ->when($hasta !== null, fn ($c) => $c->where('created_at', '<=', $this->fechas->finDelDia((string) $hasta)));

        $estado = $filtros['estado'] ?? null;
        $pagina = $encontrados()
            ->when($estado === 'aprobado', fn ($c) => $c->where('estado', EstadoPago::Aprobado->value))
            ->when($estado === 'otros', fn ($c) => $c->where('estado', '!=', EstadoPago::Aprobado->value))
            ->with(['orden.persona', 'registradoPor'])
            // Devuelto de verdad (aprobado) y lo comprometido (incluye lo que está en curso);
            // una devolución fallida no cuenta.
            ->withSum(['reembolsos as reembolsado_minor' => fn ($c) => $c->where('estado', EstadoReembolso::Aprobado->value)], 'monto_minor')
            ->withSum(['reembolsos as comprometido_minor' => fn ($c) => $c->whereIn('estado', EstadoReembolso::comprometidos())], 'monto_minor')
            ->withCount('reembolsos')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));
        $pagos = collect($pagina->items());

        $aprobados = $encontrados()->where('estado', EstadoPago::Aprobado->value);
        $resumen = [
            'cobros' => (clone $aprobados)->count(),
            'cobrado' => $this->porMoneda((clone $aprobados)->toBase()),
            'reembolsado' => $this->porMoneda(ReembolsoTenant::query()
                ->where('estado', EstadoReembolso::Aprobado->value)
                ->whereIn('pago_id', $encontrados()->select('id'))
                ->toBase()),
            'sin_aprobar' => $encontrados()->where('estado', '!=', EstadoPago::Aprobado->value)->count(),
        ];

        return response()->json([
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => $resumen,
            ],
            'data' => $pagos->map(function (PagoTenant $pago) use ($anular, $corregir): array {
                $reembolsado = (int) ($pago->getAttribute('reembolsado_minor') ?? 0);
                $comprometido = (int) ($pago->getAttribute('comprometido_minor') ?? 0);

                return [
                    'id' => $pago->ulid,
                    'fecha' => $pago->created_at?->toIso8601String(),
                    'persona' => $pago->orden?->persona?->nombreCompleto(),
                    'monto_minor' => $pago->monto_minor,
                    'moneda' => $pago->moneda,
                    'estado' => $pago->estado->value,
                    'proveedor' => $pago->proveedor,
                    'metodo' => $pago->metodo?->value,
                    'reembolsado_minor' => $reembolsado,
                    'reembolsable_minor' => max(0, $pago->monto_minor - $comprometido),
                    'con_reembolsos' => (int) $pago->getAttribute('reembolsos_count') > 0,
                    'registrado_por' => $pago->registradoPor?->name,
                    // Correcciones de un cobro en caja (ADR 0086/0087); lo que pide
                    // consultas por fila (factura, uso de créditos) se valida al guardar.
                    'metodo_caja' => $pago->proveedor === 'manual' ? $pago->orden?->metodo_pago : null,
                    'corregible' => $corregir->impedimento($pago, conFactura: false) === null,
                    'anulable' => $anular->impedimento($pago, completo: false) === null,
                ];
            })->all(),
        ]);
    }

    /**
     * Total por moneda de una consulta con `monto_minor` y `moneda`.
     *
     * @return list<array{moneda: string, total_minor: int}>
     */
    private function porMoneda(\Illuminate\Database\Query\Builder $consulta): array
    {
        return $consulta->selectRaw('moneda, COALESCE(SUM(monto_minor), 0) as total')
            ->groupBy('moneda')->orderBy('moneda')->get()
            ->map(fn (object $fila): array => ['moneda' => (string) $fila->moneda, 'total_minor' => (int) $fila->total])
            ->values()->all();
    }

    /**
     * Anula un cobro en caja registrado por error (ADR 0087): el pago queda anulado, la
     * venta vuelve a estar por cobrar y se retira lo que concedió. Motivo obligatorio.
     */
    public function anular(Request $request, AnularCobroTenant $anular): JsonResponse
    {
        $pago = $this->pagoDeMiSede($request, $this->acceso);
        $validado = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');

        $pago = $anular->anular($pago, $actor instanceof Usuario ? $actor : null, (string) $validado['motivo']);

        return response()->json(['data' => [
            'id' => $pago->ulid,
            'estado' => $pago->estado->value,
        ]]);
    }

    /**
     * Corrige la forma de pago de un cobro en caja (ADR 0086): mismo monto, otra forma.
     * Con motivo opcional; queda en la bitácora.
     */
    public function corregirMetodo(Request $request, CorregirMetodoPagoTenant $corregir): JsonResponse
    {
        $pago = $this->pagoDeMiSede($request, $this->acceso);
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(CorregirMetodoPagoTenant::METODOS)],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');

        $pago = $corregir->corregir(
            $pago,
            (string) $validado['metodo'],
            $actor instanceof Usuario ? $actor : null,
            $validado['motivo'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $pago->ulid,
            'metodo' => $pago->orden->metodo_pago ?? $pago->metodo->value,
        ]]);
    }

    /**
     * El pago de la ruta, si su compra es de una sede que le corresponde a quien lo
     * toca (R19); un cobro sin sede no es de ninguna.
     */
    public static function pagoDeMiSede(Request $request, ResolverAccesoTenant $acceso): PagoTenant
    {
        $pago = PagoTenant::query()->where('ulid', (string) $request->route('pago'))->with('orden')->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        $sucursal = $pago->orden?->sucursal_id;
        abort_unless(
            ! $actor instanceof Usuario || $acceso->permiteSucursal($actor, $sucursal !== null ? (int) $sucursal : null),
            403,
        );

        return $pago;
    }
}
