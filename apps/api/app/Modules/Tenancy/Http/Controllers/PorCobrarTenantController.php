<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PorCobrarTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Por cobrar»: las órdenes que ya se deben (compras sin pagar y citas o clases de
 * pago que ya pasaron), con quién debe, qué y desde cuándo, para registrar el pago
 * desde ahí. Es el mismo criterio que el Inicio (`PorCobrarTenant`), así que los
 * dos dicen lo mismo. Paginado, de lo más antiguo a lo más reciente.
 */
class PorCobrarTenantController
{
    public function __construct(
        private readonly PorCobrarTenant $porCobrar,
        private readonly ResolverAccesoTenant $acceso,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        $consulta = $this->porCobrar->vencidas($permitidas);
        $porPagina = (int) ($filtros['per_page'] ?? 20);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);
        $ordenes = $consulta
            ->with(['persona', 'lineas.producto', 'sesion.oferta', 'sesion.instructor', 'sesion.sucursal'])
            ->forPage($pagina, $porPagina)
            ->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenTenant $o): array => $this->presentar($o))->all(),
            'meta' => [
                'page' => $pagina, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $porPagina,
                ...$this->porCobrar->resumen($permitidas),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(OrdenTenant $orden): array
    {
        $sesion = $orden->sesion;
        $productos = $orden->lineas->map(static fn (LineaOrdenTenant $l): ?string => $l->producto?->nombre)->filter()->values();

        return [
            'id' => $orden->ulid,
            'persona' => $orden->persona !== null ? ['id' => $orden->persona->ulid, 'nombre' => $orden->persona->nombreCompleto()] : null,
            // Qué se debe: sus productos o, si es una cita o clase, el servicio.
            'concepto' => $productos->isNotEmpty() ? $productos->implode(', ') : $sesion?->oferta?->nombre,
            'total_minor' => $orden->total_minor,
            'moneda' => $orden->moneda,
            'creada_en' => $orden->created_at?->toIso8601String(),
            'sesion' => $sesion instanceof SesionTenant ? [
                'tipo' => $sesion->tipo->value,
                'profesional' => $sesion->instructor?->name,
                'inicia_en' => $sesion->inicia_en->toIso8601String(),
                'zona_horaria' => $sesion->zona_horaria,
                'sucursal' => $sesion->sucursal?->nombre,
            ] : null,
        ];
    }
}
