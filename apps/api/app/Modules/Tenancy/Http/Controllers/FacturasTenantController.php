<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EmitirFacturaTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Facturas (CFDI) tenant-local: emitir (timbrar vía FacturAPI), listar y consultar.
 * Requiere que el estudio haya cargado sus datos fiscales. Opera sobre la BD del
 * estudio resuelto.
 */
class FacturasTenantController
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly EmitirFacturaTenant $emisor) {}

    /**
     * Las facturas por páginas (antes, solo las últimas 100), de la más reciente a la
     * más antigua; se buscan por receptor (nombre o RFC) o folio fiscal, por estado y
     * por fechas (días del negocio). Con cuántas hay de cada estado para los filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoFactura::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $fechas = app(FechasNegocioTenant::class);
        $consulta = FacturaTenant::query()
            ->when($buscar !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('receptor_nombre', 'like', "%{$buscar}%")
                ->orWhere('receptor_rfc', 'like', '%'.mb_strtoupper($buscar).'%')
                ->orWhere('uuid', 'like', "%{$buscar}%")))
            ->when(isset($filtros['desde']), fn (Builder $q) => $q->where('created_at', '>=', $fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn (Builder $q) => $q->where('created_at', '<=', $fechas->finDelDia((string) $filtros['hasta'])));
        // De cada estado, con la búsqueda y las fechas (para los filtros).
        $conteos = (clone $consulta)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado')
            ->map(static fn ($n): int => (int) $n)->all();
        $pagina = $consulta
            ->when(($filtros['estado'] ?? null) !== null, fn (Builder $q) => $q->where('estado', $filtros['estado']))
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (FacturaTenant $f): array => $this->presentar($f))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => [
                    'timbrada' => $conteos[EstadoFactura::Timbrada->value] ?? 0,
                    'error' => $conteos[EstadoFactura::Error->value] ?? 0,
                ],
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $factura = FacturaTenant::query()->where('ulid', (string) $request->route('factura'))->firstOrFail();

        return response()->json(['data' => $this->presentar($factura)]);
    }

    public function emitir(Request $request): JsonResponse
    {
        // Sin proveedor real en producción no se factura (ni se simula).
        if (! ConfiguracionPlataforma::facturacionDisponible()) {
            throw ValidationException::withMessages(['facturacion' => [FacturacionNoConfigurada::MOTIVO]]);
        }
        // Solo en pesos mexicanos y en México (ADR 0099).
        if (! app(RegionNegocioTenant::class)->factura()) {
            throw ValidationException::withMessages(['facturacion' => [RegionNegocioTenant::MOTIVO_FACTURACION]]);
        }
        $validado = $request->validate([
            'receptor.nombre' => ['required', 'string', 'max:255'],
            'receptor.rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'],
            'receptor.email' => ['nullable', 'email', 'max:255'],
            'receptor.regimen_fiscal' => ['nullable', 'string', 'regex:/^[0-9]{3,4}$/'],
            'receptor.codigo_postal' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'uso_cfdi' => ['required', 'string', 'max:4'],
            'forma_pago' => ['nullable', 'string', 'max:2'],
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion' => ['required', 'string', 'max:1000'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario_minor' => ['required', 'integer', 'min:1'],
            'items.*.clave_prod_serv' => ['required', 'string', 'max:8'],
            'items.*.clave_unidad' => ['required', 'string', 'max:3'],
        ]);

        $datos = DatosFiscalesTenant::query()->first();
        if (! $datos instanceof DatosFiscalesTenant) {
            throw ValidationException::withMessages([
                'datos_fiscales' => ['Carga los datos fiscales del negocio antes de facturar.'],
            ]);
        }

        /** @var list<array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string}> $items */
        $items = $validado['items'];

        $factura = $this->emisor->emitir(
            $datos,
            $validado['receptor'],
            $items,
            (string) $validado['uso_cfdi'],
            (string) ($validado['forma_pago'] ?? '01'),
            mb_strtoupper((string) ($validado['moneda'] ?? app(ParametrosTenant::class)->moneda())),
        );

        $estado = $factura->estado === EstadoFactura::Timbrada ? 201 : 422;

        return response()->json(['data' => $this->presentar($factura)], $estado);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(FacturaTenant $factura): array
    {
        return [
            'id' => $factura->ulid,
            'estado' => $factura->estado->value,
            'receptor' => ['nombre' => $factura->receptor_nombre, 'rfc' => $factura->receptor_rfc],
            'uso_cfdi' => $factura->uso_cfdi,
            'moneda' => $factura->moneda,
            'subtotal_minor' => $factura->subtotal_minor,
            'impuesto_minor' => $factura->impuesto_minor,
            'total_minor' => $factura->total_minor,
            'uuid' => $factura->uuid,
            'pdf_url' => $factura->pdf_url,
            'xml_url' => $factura->xml_url,
            'motivo_error' => $factura->motivo_error,
            'timbrada_en' => $factura->timbrada_en?->toIso8601String(),
            'creado_en' => $factura->created_at?->toIso8601String(),
        ];
    }
}
