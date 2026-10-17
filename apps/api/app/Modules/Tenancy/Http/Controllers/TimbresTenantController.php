<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AcreditarTimbresPagados;
use App\Modules\Tenancy\Application\ComprarTimbres;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\MovimientoTimbreTenant;
use App\Modules\Tenancy\Models\TarifaSaas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los timbres del negocio para facturar a sus clientes (ADR 0107): cuántos le quedan,
 * cómo se usan por mes, sus movimientos (cada uno con su factura o su compra) y comprar
 * un paquete (en la página de Stripe).
 */
class TimbresTenantController
{
    public function __construct(
        private readonly TimbresTenant $timbres,
        private readonly AcreditarTimbresPagados $acreditar,
        private readonly FuncionesPlan $funciones,
        private readonly RegionNegocioTenant $region,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->acreditar->sincronizar($estudio);

        return response()->json(['data' => [
            'disponibles' => $this->timbres->disponibles(),
            'precio_timbre_minor' => $this->timbres->precioTimbreMinor(),
            'iva_porcentaje' => (int) (TarifaSaas::vigente($estudio->modalidad())->definicion['iva_porcentaje'] ?? 16),
            'moneda' => 'MXN',
            'paquetes' => $this->timbres->paquetes(),
            'posible' => $this->motivo($estudio) === null,
            'motivo' => $this->motivo($estudio),
            'movimientos' => $this->timbres->movimientos(),
            // Uso por mes (en la zona del negocio), promedio y para cuánto alcanza.
            'resumen' => $this->timbres->resumen($this->zona($estudio)),
            // Una compra que se empezó y no se pagó: se puede pagar desde aquí.
            'compra_pendiente' => $this->compraPendiente($estudio),
        ]]);
    }

    /**
     * Todos los movimientos de timbres por páginas, de los más recientes. Se filtran por
     * tipo (factura = `consumo`, `compra`, `ajuste`), por fechas del negocio y por
     * cliente (nombre, RFC o folio fiscal de la factura). Cada consumo trae su factura y
     * cada compra su cargo; `?formato=csv` descarga todos los filtrados.
     */
    public function movimientos(Request $request): JsonResponse|Response
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::in(['consumo', 'compra', 'ajuste'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'formato' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }
        $estudio = $this->estudio($request);
        // Quien está acotado a sus sedes no ve a quién se le facturó (R19): las facturas
        // no son de ninguna sede.
        $verClientes = app(ResolverAccesoTenant::class)->deLaSolicitud($request) === null;
        $consulta = $this->consulta($filtros, $verClientes);

        if (($filtros['formato'] ?? 'json') === 'csv') {
            return $this->csv($consulta, $estudio, $verClientes);
        }

        $pagina = $consulta->paginate((int) ($filtros['per_page'] ?? 20), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => $this->presentar(collect($pagina->items()), $estudio, $verClientes),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    public function comprar(Request $request, ComprarTimbres $comprar): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate([
            'cantidad' => ['required', 'integer'],
            // A dónde vuelve tras pagar: Mi suscripción o la pantalla de timbres.
            'retorno' => ['nullable', Rule::in(['/renta', '/renta/timbres'])],
        ]);
        $motivo = $this->motivo($estudio);
        if ($motivo !== null) {
            throw ValidationException::withMessages(['cantidad' => [$motivo]]);
        }

        $cargo = $comprar->comprar($estudio, (int) $validado['cantidad'], (string) ($validado['retorno'] ?? '/renta'));

        return response()->json(['data' => [
            'id' => $cargo->ulid,
            'estado' => $cargo->estado->value,
            'monto_minor' => $cargo->monto_minor,
            'moneda' => $cargo->moneda,
            'checkout' => $cargo->checkout,
        ]], 201);
    }

    /** Por qué no puede comprar timbres (null si puede). */
    private function motivo(Estudio $estudio): ?string
    {
        // Sin FacturAPI en la plataforma no se timbra: no se venden timbres que no sirven.
        if (! ConfiguracionPlataforma::facturacionDisponible()) {
            return FacturacionNoConfigurada::MOTIVO;
        }
        if (! $this->region->factura()) {
            return RegionNegocioTenant::MOTIVO_FACTURACION;
        }
        if (! $this->funciones->tiene($estudio, 'facturacion')) {
            return 'La facturación está en el plan Pro.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<MovimientoTimbreTenant>
     */
    private function consulta(array $filtros, bool $verClientes): Builder
    {
        $fechas = app(FechasNegocioTenant::class);
        $buscar = addcslashes(trim((string) ($filtros['q'] ?? '')), '%_\\');

        return MovimientoTimbreTenant::query()
            ->when(isset($filtros['tipo']), fn (Builder $q) => $q->where('tipo', (string) $filtros['tipo']))
            ->when(isset($filtros['desde']), fn (Builder $q) => $q->where('created_at', '>=', $fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn (Builder $q) => $q->where('created_at', '<=', $fechas->finDelDia((string) $filtros['hasta'])))
            // La búsqueda es por el cliente de la factura: solo para quien puede verlo.
            ->when($buscar !== '' && $verClientes, fn (Builder $q) => $q->where('tipo', 'consumo')->whereIn(
                'referencia',
                FacturaTenant::query()->select('ulid')->where(fn (Builder $w) => $w
                    ->where('receptor_nombre', 'like', "%{$buscar}%")
                    ->orWhere('receptor_rfc', 'like', '%'.mb_strtoupper($buscar).'%')
                    ->orWhere('uuid', 'like', "%{$buscar}%")),
            ))
            ->orderByDesc('id');
    }

    /**
     * Cada movimiento con su factura (consumo) o su compra (cargo pagado).
     *
     * @param  Collection<int, MovimientoTimbreTenant>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function presentar(Collection $movimientos, Estudio $estudio, bool $verClientes): array
    {
        $facturas = FacturaTenant::query()
            ->whereIn('ulid', $movimientos->where('tipo', 'consumo')->pluck('referencia')->filter()->values()->all())
            ->get()->keyBy('ulid');
        $cargos = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->whereIn('ulid', $movimientos->where('tipo', 'compra')->pluck('referencia')
                ->filter(static fn ($r): bool => is_string($r) && str_starts_with($r, 'cargo:'))
                ->map(static fn (string $r): string => substr($r, 6))->values()->all())
            ->get()->keyBy('ulid');

        return $movimientos->map(function (MovimientoTimbreTenant $m) use ($facturas, $cargos, $verClientes): array {
            $factura = $m->tipo === 'consumo' ? $facturas->get((string) $m->referencia) : null;
            $cargo = $m->tipo === 'compra' && is_string($m->referencia) && str_starts_with($m->referencia, 'cargo:')
                ? $cargos->get(substr($m->referencia, 6)) : null;

            return [
                'id' => (string) $m->ulid,
                'tipo' => $m->tipo,
                'cantidad' => $m->cantidad,
                'saldo_despues' => $m->saldo_despues,
                'detalle' => $m->detalle,
                'fecha' => $m->created_at?->toIso8601String(),
                'factura' => $factura instanceof FacturaTenant ? [
                    'id' => (string) $factura->ulid,
                    'cliente' => $verClientes ? $factura->receptor_nombre : null,
                    'rfc' => $verClientes ? $factura->receptor_rfc : null,
                    'total_minor' => (int) $factura->total_minor,
                    'moneda' => (string) $factura->moneda,
                    'estado' => $factura->estado->value,
                    'uuid' => $factura->uuid,
                ] : null,
                'compra' => $cargo instanceof CargoRenta ? [
                    'id' => (string) $cargo->ulid,
                    'monto_minor' => (int) $cargo->monto_minor,
                    'moneda' => (string) $cargo->moneda,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Todos los movimientos filtrados en CSV (por bloques, sin cargarlos de una vez).
     *
     * @param  Builder<MovimientoTimbreTenant>  $consulta
     */
    private function csv(Builder $consulta, Estudio $estudio, bool $verClientes): Response
    {
        $zona = $this->zona($estudio);
        $tipos = ['consumo' => 'Factura', 'compra' => 'Compra', 'ajuste' => 'Ajuste'];
        $lineas = ['Fecha,Movimiento,Cantidad,Saldo,Cliente,RFC,Total factura,Moneda,Folio fiscal,Detalle'];
        $consulta->chunk(500, function (Collection $bloque) use (&$lineas, $estudio, $verClientes, $zona, $tipos): void {
            foreach ($this->presentar($bloque, $estudio, $verClientes) as $m) {
                /** @var array{cliente: string|null, rfc: string|null, total_minor: int, moneda: string, uuid: string|null}|null $f */
                $f = $m['factura'];
                $fecha = is_string($m['fecha']) ? CarbonImmutable::parse($m['fecha'])->setTimezone($zona)->format('Y-m-d H:i') : '';
                $lineas[] = implode(',', array_map(fn (string $v): string => $this->escapar($v), [
                    $fecha,
                    $tipos[(string) $m['tipo']] ?? (string) $m['tipo'],
                    (string) $m['cantidad'],
                    (string) $m['saldo_despues'],
                    (string) ($f['cliente'] ?? ''),
                    (string) ($f['rfc'] ?? ''),
                    $f !== null ? number_format($f['total_minor'] / 100, 2, '.', '') : '',
                    (string) ($f['moneda'] ?? ''),
                    (string) ($f['uuid'] ?? ''),
                    (string) ($m['detalle'] ?? ''),
                ]));
            }
        });

        return response("\u{FEFF}".implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="timbres-'.CarbonImmutable::now($zona)->format('Y-m-d').'.csv"',
        ]);
    }

    private function escapar(string $valor): string
    {
        // Evita que una hoja de cálculo interprete fórmulas.
        if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true) && ! is_numeric($valor)) {
            $valor = "'".$valor;
        }

        return str_contains($valor, ',') || str_contains($valor, '"') || str_contains($valor, "\n")
            ? '"'.str_replace('"', '""', $valor).'"'
            : $valor;
    }

    /**
     * La compra de timbres empezada y sin pagar más reciente (se paga como cualquier
     * cargo de la renta).
     *
     * @return array{id: string, cantidad: int, monto_minor: int, moneda: string, emitido_en: string|null}|null
     */
    private function compraPendiente(Estudio $estudio): ?array
    {
        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('concepto', 'timbres')
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->orderByDesc('id')
            ->first();

        return $cargo instanceof CargoRenta ? [
            'id' => (string) $cargo->ulid,
            'cantidad' => (int) $cargo->alumnos_activos,
            'monto_minor' => (int) $cargo->monto_minor,
            'moneda' => (string) $cargo->moneda,
            'emitido_en' => $cargo->emitido_en?->toIso8601String(),
        ] : null;
    }

    private function zona(Estudio $estudio): string
    {
        return (string) ($estudio->zona_horaria ?: 'America/Mexico_City');
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
