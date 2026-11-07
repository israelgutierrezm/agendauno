<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\PorCobrarTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Por cobrar»: las órdenes que ya se deben (compras sin pagar y citas o clases de
 * pago que ya pasaron), con quién debe, qué y desde cuándo, para registrar el pago
 * desde ahí. Es el mismo criterio que el Inicio (`PorCobrarTenant`), así que los
 * dos dicen lo mismo. Paginado, de lo más antiguo a lo más reciente. Una renovación
 * dice cuándo vence (`renovacion`); las que aún no vencen se cuentan aparte.
 *
 * Se busca del lado del servidor (ADR 0235): `q` por la persona (nombre, correo o
 * celular), el folio de la orden, la referencia o el concepto; `tipo` (compra, sesión
 * o renovación) y `desde`/`hasta` (días del negocio, por la fecha de la orden). El
 * resumen de `meta` (cuánto se debe en total) no depende de la página ni de los filtros.
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
            'q' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(['compra', 'sesion', 'renovacion'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        $consulta = $this->filtrar($this->porCobrar->vencidas($permitidas), $filtros);
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
     * @param  Builder<OrdenTenant>  $consulta
     * @param  array<string, mixed>  $filtros
     * @return Builder<OrdenTenant>
     */
    private function filtrar(Builder $consulta, array $filtros): Builder
    {
        $buscar = trim((string) ($filtros['q'] ?? ''));
        if ($buscar !== '') {
            $patron = '%'.$buscar.'%';
            $consulta->where(fn (Builder $q) => $q
                ->whereHas('persona', fn (Builder $p) => $p->buscar($buscar))
                ->orWhere('ulid', $buscar)
                ->orWhereHas('lineas', fn (Builder $l) => $l
                    ->where('descripcion', 'like', $patron)
                    ->orWhere('referencia', 'like', $patron)
                    ->orWhereHas('producto', fn (Builder $pr) => $pr->where('nombre', 'like', $patron)))
                ->orWhereHas('sesion.oferta', fn (Builder $o) => $o->where('nombre', 'like', $patron)));
        }
        match ($filtros['tipo'] ?? null) {
            'sesion' => $consulta->whereNotNull('sesion_id'),
            'renovacion' => $consulta->whereNotNull('renueva_vence_el'),
            'compra' => $consulta->whereNull('sesion_id')->whereNull('renueva_vence_el'),
            default => null,
        };
        $fechas = app(FechasNegocioTenant::class);
        if (isset($filtros['desde'])) {
            $consulta->where('created_at', '>=', $fechas->inicioDelDia((string) $filtros['desde'])->utc());
        }
        if (isset($filtros['hasta'])) {
            $consulta->where('created_at', '<=', $fechas->finDelDia((string) $filtros['hasta'])->utc());
        }

        return $consulta;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(OrdenTenant $orden): array
    {
        $sesion = $orden->sesion;
        $productos = $orden->lineas->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->values();

        return [
            'id' => $orden->ulid,
            'persona' => $orden->persona !== null ? ['id' => $orden->persona->ulid, 'nombre' => $orden->persona->nombreCompleto()] : null,
            // Qué se debe: sus productos o, si es una cita o clase, el servicio.
            'concepto' => $productos->isNotEmpty() ? $productos->implode(', ') : $sesion?->oferta?->nombre,
            'total_minor' => $orden->total_minor,
            'moneda' => $orden->moneda,
            // Las cuotas que lleva (inscripción, anualidad, penalización; B04), para
            // exentarlas por separado.
            'cuotas' => $orden->lineas->map(static fn (LineaOrdenTenant $l): ?array => $l->comoCuota())->filter()->values()->all(),
            'creada_en' => $orden->created_at?->toIso8601String(),
            // Una renovación: cuándo vence y si ya venció (con hoy del negocio). Se puede
            // cobrar antes, por eso sigue en la lista.
            'renovacion' => $this->porCobrar->renovacion($orden),
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
