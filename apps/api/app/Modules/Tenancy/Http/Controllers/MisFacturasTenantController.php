<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Mis facturas (ADR 0212): las que el negocio le timbró al cliente por sus compras y
 * sus ventas del mostrador, con su PDF y su XML. También le llegan por correo.
 *
 * Por páginas (antes, solo las últimas 100) y por periodo (días del negocio en que se
 * timbraron), de la más reciente a la más antigua (ADR 0228). `meta.disponible` dice si
 * el negocio factura: si no, y no tiene ninguna, la sección no se muestra.
 */
class MisFacturasTenantController
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly CuentaEnUsoTenant $cuenta) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }
        $porPagina = (int) ($filtros['per_page'] ?? self::POR_PAGINA);
        // Quien no tiene ficha en el negocio (parte del equipo) no tiene ninguna.
        $persona = $this->cuenta->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => $porPagina, 'ultima_pagina' => 1, 'disponible' => false]]);
        }
        $fechas = app(FechasNegocioTenant::class);
        $pagina = $this->deLaPersona($persona)
            ->when(isset($filtros['desde']), fn (Builder $q) => $q->where('timbrada_en', '>=', $fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn (Builder $q) => $q->where('timbrada_en', '<=', $fechas->finDelDia((string) $filtros['hasta'])))
            ->orderByDesc('timbrada_en')
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(static fn (FacturaTenant $f): array => [
                'id' => $f->ulid,
                'tipo' => $f->tipo,
                'uuid' => $f->uuid,
                'total_minor' => $f->total_minor,
                'moneda' => $f->moneda,
                'receptor_rfc' => $f->receptor_rfc,
                'timbrada_en' => $f->timbrada_en?->toIso8601String(),
                'cancelada' => $f->cancelacion_estado === EstadoCancelacion::Aceptada,
            ])->values()->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                // Si el negocio factura (en México, con sus datos fiscales).
                'disponible' => app(RegionNegocioTenant::class)->factura() && DatosFiscalesTenant::query()->exists(),
            ],
        ]);
    }

    public function descargar(Request $request, ClienteFacturacion $cliente): Response
    {
        $factura = $this->deLaPersona($this->persona($request))->where('ulid', (string) $request->route('factura'))->firstOrFail();
        abort_unless(DatosFiscalesTenant::query()->exists(), 404);

        return FacturasTenantController::archivo($factura, (string) $request->route('formato'), $cliente);
    }

    /**
     * Las timbradas de sus órdenes o de sus ventas del mostrador.
     *
     * @return Builder<FacturaTenant>
     */
    private function deLaPersona(PersonaTenant $persona): Builder
    {
        return FacturaTenant::query()
            ->where('estado', EstadoFactura::Timbrada->value)
            ->where(fn (Builder $q) => $q
                ->whereHas('orden', fn (Builder $o) => $o->where('persona_id', $persona->getKey()))
                ->orWhereHas('venta', fn (Builder $v) => $v->where('persona_id', $persona->getKey())));
    }

    private function persona(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }
}
