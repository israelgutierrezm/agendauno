<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\CancelarFacturaTenant;
use App\Modules\Tenancy\Application\EmitirFacturaTenant;
use App\Modules\Tenancy\Application\FacturasDeVentasTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\CatalogosSat;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
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

    public function __construct(
        private readonly EmitirFacturaTenant $emisor,
        private readonly FacturasDeVentasTenant $ventas,
        private readonly CancelarFacturaTenant $cancelar,
    ) {}

    /**
     * Lo que se factura de una venta (ADR 0179): `orden` (un plan, una cita, una
     * compra) o `venta` (del mostrador). Conceptos sin IVA, a quién y la forma de pago.
     */
    public function borrador(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'orden' => ['nullable', 'string', 'required_without_all:venta,sustituye', 'prohibits:venta,sustituye'],
            'venta' => ['nullable', 'string', 'prohibits:sustituye'],
            // La sustituta de una factura con datos mal (ADR 0180).
            'sustituye' => ['nullable', 'string'],
        ]);
        if (isset($validado['sustituye'])) {
            $factura = $this->facturaDeMiSede($request, (string) $validado['sustituye']);
            $impedimento = $this->cancelar->impedimento($factura);
            if ($impedimento !== null) {
                throw ValidationException::withMessages(['sustituye' => [$impedimento]]);
            }

            return response()->json(['data' => $this->ventas->borradorDeSustituta($factura)]);
        }
        if (isset($validado['orden'])) {
            $orden = $this->ordenDeMiSede($request, (string) $validado['orden']);
            $this->ventas->exigirOrden($orden);

            return response()->json(['data' => $this->ventas->borradorDeOrden($orden)]);
        }
        $venta = $this->ventaDeMiSede($request, (string) $validado['venta']);
        $this->ventas->exigirVenta($venta);

        return response()->json(['data' => $this->ventas->borradorDeVenta($venta)]);
    }

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
            // Los anticipos timbrados y sin aplicar de ese RFC (para facturar el resto).
            'anticipos_de' => ['nullable', 'string', 'max:13'],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $fechas = app(FechasNegocioTenant::class);
        // Alcance por sucursal (R19): las de las compras y las ventas del mostrador de
        // sus sedes (una factura sin venta es del negocio y no es de ninguna sede).
        $sedes = app(ResolverAccesoTenant::class)->deLaSolicitud($request);
        $consulta = FacturaTenant::query()
            ->when($sedes !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('orden_id', OrdenTenant::query()->select('id')->whereIn('sucursal_id', $sedes))
                ->orWhereIn('venta_pos_id', VentaPosTenant::query()->select('id')->whereIn('sucursal_id', $sedes))))
            ->when($buscar !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('receptor_nombre', 'like', "%{$buscar}%")
                ->orWhere('receptor_rfc', 'like', '%'.mb_strtoupper($buscar).'%')
                ->orWhere('uuid', 'like', "%{$buscar}%")))
            ->when(isset($filtros['desde']), fn (Builder $q) => $q->where('created_at', '>=', $fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn (Builder $q) => $q->where('created_at', '<=', $fechas->finDelDia((string) $filtros['hasta'])))
            ->when(isset($filtros['anticipos_de']), fn (Builder $q) => $q
                ->where('anticipo', true)
                ->where('estado', EstadoFactura::Timbrada->value)
                ->whereNull('anticipo_aplicado_en')
                ->where('receptor_rfc', mb_strtoupper((string) $filtros['anticipos_de'])));
        // De cada estado, con la búsqueda y las fechas (para los filtros).
        $conteos = (clone $consulta)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado')
            ->map(static fn ($n): int => (int) $n)->all();
        $pagina = $consulta
            ->when(($filtros['estado'] ?? null) !== null, fn (Builder $q) => $q->where('estado', $filtros['estado']))
            ->with(['orden', 'venta', 'relacionada'])
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
                    'cancelada' => $conteos[EstadoFactura::Cancelada->value] ?? 0,
                ],
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $factura = $this->facturaDeMiSede($request, (string) $request->route('factura'));

        return response()->json(['data' => $this->presentar($factura)]);
    }

    /**
     * Pide cancelar el CFDI (ADR 0180) con el motivo `02` (con errores, sin sustituta)
     * o `03` (la operación no se llevó a cabo). La del `01` se pide sola al sustituirla.
     * Si el cliente debe aceptarla, queda en espera.
     */
    public function cancelar(Request $request): JsonResponse
    {
        $factura = $this->facturaDeMiSede($request, (string) $request->route('factura'));
        $validado = $request->validate([
            'motivo' => ['required', 'string', Rule::in(['02', '03'])],
        ], ['motivo.in' => 'Elige por qué se cancela: con errores (02) o no se llevó a cabo (03).']);
        $factura = $this->cancelar->solicitar($factura, (string) $validado['motivo'], $this->actor($request));

        return response()->json(['data' => $this->presentar($factura->load(['orden', 'venta', 'relacionada']))]);
    }

    /**
     * Una nota de crédito (ADR 0180) de una factura timbrada: lo devuelto, descontado o
     * bonificado (con IVA), cómo se le devolvió y, si la factura no lo guardó, el
     * régimen fiscal del cliente.
     */
    public function notaDeCredito(Request $request): JsonResponse
    {
        $this->exigirFacturacion();
        $factura = $this->facturaDeMiSede($request, (string) $request->route('factura'));
        $validado = $request->validate([
            'monto_minor' => ['required', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'forma_pago' => ['required', 'string', Rule::in(CatalogosSat::FORMAS_PAGO)],
            'regimen_fiscal' => ['nullable', 'string', 'regex:/^[0-9]{3,4}$/'],
        ]);
        $datos = $this->datosFiscales();

        $nota = $this->emisor->emitirNotaDeCredito(
            $datos,
            $factura,
            (int) $validado['monto_minor'],
            trim((string) ($validado['descripcion'] ?? '')) !== '' ? (string) $validado['descripcion'] : 'Devolución',
            (string) $validado['forma_pago'],
            $validado['regimen_fiscal'] ?? null,
        );

        $estado = $nota->estado === EstadoFactura::Timbrada ? 201 : 422;

        return response()->json(['data' => $this->presentar($nota->load(['orden', 'venta', 'relacionada']))], $estado);
    }

    public function emitir(Request $request): JsonResponse
    {
        $this->exigirFacturacion();
        $validado = $request->validate([
            'receptor.nombre' => ['required', 'string', 'max:255'],
            'receptor.rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'],
            'receptor.email' => ['nullable', 'email', 'max:255'],
            'receptor.regimen_fiscal' => ['nullable', 'string', 'regex:/^[0-9]{3,4}$/'],
            'receptor.codigo_postal' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            // Del catálogo del SAT: lo que no esté ahí, el SAT lo rechazaría al timbrar.
            'uso_cfdi' => ['required', 'string', Rule::in(CatalogosSat::USOS_CFDI)],
            'forma_pago' => ['nullable', 'string', Rule::in(CatalogosSat::FORMAS_PAGO)],
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion' => ['required', 'string', 'max:1000'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario_minor' => ['required', 'integer', 'min:1'],
            'items.*.clave_prod_serv' => ['required', 'string', 'max:8'],
            'items.*.clave_unidad' => ['required', 'string', 'max:3'],
            // Factura de un anticipo (ADR 0163): un solo concepto, su importe; la clave,
            // la unidad y la descripción las fija el SAT.
            'anticipo' => ['boolean'],
            // O la del resto: los anticipos (facturas) que aplica.
            'anticipos' => ['nullable', 'array', 'max:20', 'prohibited_if:anticipo,true'],
            'anticipos.*' => ['string', 'distinct'],
            // La venta que factura (ADR 0179): una orden o una venta del mostrador. Un
            // anticipo no factura la venta: la factura del resto sí.
            'orden_id' => ['nullable', 'string', 'prohibits:venta_id', 'prohibited_if:anticipo,true'],
            'venta_id' => ['nullable', 'string', 'prohibited_if:anticipo,true'],
            // La factura que sustituye (ADR 0180): la nueva toma su venta y se pide la
            // cancelación de la vieja con el motivo 01.
            'sustituye' => ['nullable', 'string', 'prohibits:orden_id,venta_id'],
        ]);
        $sustituye = isset($validado['sustituye']) ? $this->facturaDeMiSede($request, (string) $validado['sustituye']) : null;
        // Sustituir también cancela: pide el permiso de devoluciones, como cancelar.
        abort_if($sustituye !== null && ! $this->actor($request)?->puede('pagos.reembolsar'), 403, 'Para sustituir una factura hace falta poder cancelarla.');
        $orden = isset($validado['orden_id']) ? $this->ordenDeMiSede($request, (string) $validado['orden_id']) : null;
        $venta = isset($validado['venta_id']) ? $this->ventaDeMiSede($request, (string) $validado['venta_id']) : null;
        if ($orden !== null) {
            $this->ventas->exigirOrden($orden);
        }
        if ($venta !== null) {
            $this->ventas->exigirVenta($venta);
        }
        if (($validado['anticipo'] ?? false) && count($validado['items']) !== 1) {
            throw ValidationException::withMessages(['items' => ['La factura de un anticipo lleva un solo concepto: el importe del anticipo.']]);
        }
        // Un uso solo de personas físicas con un RFC de persona moral (12 caracteres).
        if (strlen((string) $validado['receptor']['rfc']) === 12 && in_array($validado['uso_cfdi'], CatalogosSat::USOS_SOLO_FISICAS, true)) {
            throw ValidationException::withMessages(['uso_cfdi' => ['Ese uso del CFDI es solo para personas físicas y el RFC es de persona moral.']]);
        }

        $datos = $this->datosFiscales();

        /** @var list<array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string}> $items */
        $items = $validado['items'];

        $factura = $this->emisor->emitir(
            $datos,
            $validado['receptor'],
            $items,
            (string) $validado['uso_cfdi'],
            (string) ($validado['forma_pago'] ?? '01'),
            mb_strtoupper((string) ($validado['moneda'] ?? app(ParametrosTenant::class)->moneda())),
            (bool) ($validado['anticipo'] ?? false),
            array_values($validado['anticipos'] ?? []),
            $orden !== null ? (int) $orden->getKey() : null,
            $venta !== null ? (int) $venta->getKey() : null,
            $sustituye,
        );

        // Timbrada la sustituta, se pide cancelar la que sustituye (motivo 01). Si el
        // proveedor no la acepta aún, se dice y se puede volver a pedir desde la lista.
        $aviso = null;
        if ($sustituye !== null && $factura->estado === EstadoFactura::Timbrada) {
            try {
                $this->cancelar->solicitar($sustituye, '01', $this->actor($request));
            } catch (ValidationException $e) {
                $aviso = (string) collect($e->errors())->flatten()->first();
            }
        }

        $estado = $factura->estado === EstadoFactura::Timbrada ? 201 : 422;
        $datosRespuesta = $this->presentar($factura->load(['orden', 'venta', 'relacionada']));

        return response()->json(['data' => $datosRespuesta] + ($aviso !== null ? ['meta' => ['aviso_cancelacion' => $aviso]] : []), $estado);
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
            // Factura de un anticipo y si ya se aplicó al facturar el resto (ADR 0163).
            'anticipo' => $factura->anticipo,
            'anticipo_aplicado' => $factura->anticipo_aplicado_en !== null,
            // La venta que factura (ADR 0179).
            'orden_id' => $factura->orden?->ulid,
            'venta_id' => $factura->venta?->ulid,
            // Ingreso o nota de crédito, y con cuál se relaciona (ADR 0180).
            'tipo' => $factura->tipo === FacturaTenant::EGRESO ? 'nota_de_credito' : 'ingreso',
            'relacionada' => $factura->relacionada !== null
                ? ['id' => $factura->relacionada->ulid, 'uuid' => $factura->relacionada->uuid, 'relacion' => $factura->relacion]
                : null,
            'forma_pago' => $factura->forma_pago,
            'regimen_receptor' => $factura->receptor_regimen,
            'cancelacion' => $factura->cancelacion_estado !== null ? [
                'estado' => $factura->cancelacion_estado->value,
                'motivo' => $factura->cancelacion_motivo,
                'detalle' => $factura->cancelacion_detalle,
                'solicitada_en' => $factura->cancelacion_solicitada_en?->toIso8601String(),
            ] : null,
            'cancelada_en' => $factura->cancelada_en?->toIso8601String(),
            // Lo que se puede hacer con ella.
            'cancelable' => $this->cancelar->impedimento($factura) === null,
            'acreditable_minor' => $factura->estado === EstadoFactura::Timbrada && $factura->tipo === FacturaTenant::INGRESO && ! $factura->anticipo
                ? $factura->acreditable()
                : 0,
        ];
    }

    /** La factura, si quien la pide alcanza su venta (R19); sin venta, es del negocio. */
    private function facturaDeMiSede(Request $request, string $ulid): FacturaTenant
    {
        $factura = FacturaTenant::query()->where('ulid', $ulid)->with(['orden', 'venta', 'relacionada'])->firstOrFail();
        if ($factura->orden instanceof OrdenTenant) {
            app(AlcancePersonaTenant::class)->exigirOrden($request, $factura->orden);
        } elseif ($factura->venta instanceof VentaPosTenant) {
            $this->exigirSedeDeVenta($request, $factura->venta);
        } else {
            abort_if(app(ResolverAccesoTenant::class)->deLaSolicitud($request) !== null, 403);
        }

        return $factura;
    }

    /** Sin proveedor real en producción no se factura (ni se simula); solo en México (ADR 0099). */
    private function exigirFacturacion(): void
    {
        if (! ConfiguracionPlataforma::facturacionDisponible()) {
            throw ValidationException::withMessages(['facturacion' => [FacturacionNoConfigurada::MOTIVO]]);
        }
        if (! app(RegionNegocioTenant::class)->factura()) {
            throw ValidationException::withMessages(['facturacion' => [RegionNegocioTenant::MOTIVO_FACTURACION]]);
        }
    }

    private function datosFiscales(): DatosFiscalesTenant
    {
        $datos = DatosFiscalesTenant::query()->first();
        if (! $datos instanceof DatosFiscalesTenant) {
            throw ValidationException::withMessages([
                'datos_fiscales' => ['Carga los datos fiscales del negocio antes de facturar.'],
            ]);
        }

        return $datos;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /** La orden, si quien la factura alcanza a su cliente y su sede. */
    private function ordenDeMiSede(Request $request, string $ulid): OrdenTenant
    {
        $orden = OrdenTenant::query()->where('ulid', $ulid)->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirOrden($request, $orden);

        return $orden;
    }

    /** La venta del mostrador, si es de una sede de quien la factura (R19). */
    private function ventaDeMiSede(Request $request, string $ulid): VentaPosTenant
    {
        $venta = VentaPosTenant::query()->where('ulid', $ulid)->firstOrFail();
        $this->exigirSedeDeVenta($request, $venta);

        return $venta;
    }

    private function exigirSedeDeVenta(Request $request, VentaPosTenant $venta): void
    {
        $sedes = app(ResolverAccesoTenant::class)->deLaSolicitud($request);
        abort_unless($sedes === null || in_array((int) $venta->sucursal_id, $sedes, true), 403);
    }
}
