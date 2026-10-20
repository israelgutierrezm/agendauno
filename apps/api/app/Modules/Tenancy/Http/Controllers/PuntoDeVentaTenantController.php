<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\CajasTenant;
use App\Modules\Tenancy\Application\CompradoresPosTenant;
use App\Modules\Tenancy\Application\CorregirVentaPosTenant;
use App\Modules\Tenancy\Application\DevolverVentaPosTenant;
use App\Modules\Tenancy\Application\FacturasDeVentasTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\TicketVentaPosTenant;
use App\Modules\Tenancy\Models\DevolucionVentaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Pagos\PartesCobro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Punto de venta minorista del estudio, tenant-local (R21): cobra un ticket de caja
 * (descuenta stock) y lista las ventas. Opera SIEMPRE sobre la BD del estudio resuelto.
 *
 * Cada venta es a una persona del negocio o a público en general, y a la persona se le
 * puede mandar su ticket por correo (ADR 0145).
 */
class PuntoDeVentaTenantController
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly PuntoDeVentaTenant $pos,
        private readonly ResolverAccesoTenant $acceso,
        private readonly CorregirVentaPosTenant $corregir,
        private readonly CompradoresPosTenant $compradores,
        private readonly TicketVentaPosTenant $tickets,
        private readonly FechasNegocioTenant $fechas,
        private readonly DevolverVentaPosTenant $devoluciones,
    ) {}

    /**
     * Las ventas, de la más reciente a la más antigua, por páginas. Se filtran por días
     * del negocio (`desde` / `hasta`), sucursal y persona. `meta.resumen` suma lo
     * filtrado y `meta.hoy`, lo de hoy en esas sucursales (sin anuladas).
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'sucursal_id' => ['nullable', 'string'],
            'persona_id' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }

        // Alcance por sucursal (R19): el staff acotado solo ve las ventas de SUS sedes.
        $actor = $this->actor($request);
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
        $sucursal = null;
        if (($filtros['sucursal_id'] ?? '') !== '') {
            $sucursal = (int) SucursalTenant::query()->where('ulid', $filtros['sucursal_id'])->value('id');
        }
        $persona = null;
        if (($filtros['persona_id'] ?? '') !== '') {
            $persona = (int) PersonaTenant::withTrashed()->where('ulid', $filtros['persona_id'])->value('id');
        }

        $deSusSedes = fn (): Builder => VentaPosTenant::query()->delMostrador()
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $permitidas ?? []))
            ->when($sucursal !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursal))
            ->when($persona !== null, fn (Builder $q) => $q->where('persona_id', $persona));
        $consulta = $deSusSedes()
            ->when($desde !== null, fn (Builder $q) => $q->where('created_at', '>=', $this->fechas->inicioDelDia((string) $desde)))
            ->when($hasta !== null, fn (Builder $q) => $q->where('created_at', '<=', $this->fechas->finDelDia((string) $hasta)));

        $hoy = $this->fechas->hoy();
        $pagina = (clone $consulta)
            ->with(['sucursal', 'lineas.articulo', 'persona', 'facturaTimbrada'])
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (VentaPosTenant $v): array => $this->presentar($v))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => $this->sumar($consulta),
                'hoy' => $this->sumar($deSusSedes()
                    ->where('created_at', '>=', $this->fechas->inicioDelDia($hoy))
                    ->where('created_at', '<=', $this->fechas->finDelDia($hoy))),
            ],
        ]);
    }

    /**
     * Cuántas ventas y cuánto se vendió (sin anuladas ni lo devuelto; las propinas,
     * aparte).
     *
     * @param  Builder<VentaPosTenant>  $consulta
     * @return array{ventas: int, vendido_minor: int, propinas_minor: int, devuelto_minor: int}
     */
    private function sumar(Builder $consulta): array
    {
        $vigentes = (clone $consulta)->whereNull('anulada_en');
        $fila = (clone $vigentes)
            ->selectRaw('COUNT(*) as ventas, COALESCE(SUM(total_minor), 0) as vendido, COALESCE(SUM(propina_minor), 0) as propinas')
            ->toBase()->first();
        $devuelto = (int) DevolucionVentaTenant::query()
            ->whereIn('venta_pos_id', (clone $vigentes)->select('ventas_pos.id'))
            ->sum('monto_minor');

        return [
            'ventas' => (int) ($fila->ventas ?? 0),
            'vendido_minor' => (int) ($fila->vendido ?? 0) - $devuelto,
            'propinas_minor' => (int) ($fila->propinas ?? 0),
            'devuelto_minor' => $devuelto,
        ];
    }

    /**
     * A quién se le puede vender: personas del negocio por nombre, correo o celular
     * (ADR 0145). Sin elegir a nadie, la venta es a público en general.
     */
    public function compradores(Request $request): JsonResponse
    {
        $validado = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->compradores->buscar((string) ($validado['q'] ?? ''), $this->actor($request))]);
    }

    public function vender(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'metodo_pago' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.articulo_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            // Sus extras (ADR 0139): de ese artículo y a la venta.
            'items.*.extras' => ['nullable', 'array', 'max:30'],
            'items.*.extras.*' => ['string'],
            // La propina, aparte de lo vendido.
            'propina_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // En efectivo: lo que entregó el cliente, para el cambio.
            'recibido_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // A quién (de `GET /pos/compradores`); sin él, a público en general. A la
            // persona se le puede mandar su ticket por correo (ADR 0145).
            'comprador_id' => ['nullable', 'string', 'max:80'],
            'enviar_ticket' => ['nullable', 'boolean'],
            // Lo que mostró el mostrador por pieza (con extras): si cambió, se revisa.
            'items.*.precio_minor' => ['nullable', 'integer', 'min:0'],
            // La clave del intento de cobro: un reintento devuelve la misma venta.
            'clave_intento' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            // Con varias formas de pago (ADR 0177): cuánto con cada una.
            ...PartesCobro::reglas(),
        ]);
        $partes = PartesCobro::desde($validado['partes'] ?? null);

        // Ya se registró con esta clave (la respuesta se perdió): la misma venta, si es la
        // misma operación; con otra (u otra cita), conflicto (R05).
        $clave = $validado['clave_intento'] ?? null;
        $huella = PuntoDeVentaTenant::huella('mostrador', [
            'sucursal' => $validado['sucursal_id'],
            'items' => array_map(static fn (array $i): array => [
                (string) $i['articulo_id'], (int) $i['cantidad'], array_values((array) ($i['extras'] ?? [])), $i['precio_minor'] ?? null,
            ], array_values($validado['items'])),
            'metodo' => $validado['metodo_pago'] ?? 'efectivo',
            'partes' => $partes,
            'propina' => (int) ($validado['propina_minor'] ?? 0),
            'recibido' => $validado['recibido_minor'] ?? null,
            'comprador' => $validado['comprador_id'] ?? null,
        ]);
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        // Alcance por sucursal (R19): un acotado no vende en una sede ajena.
        $actor = $this->actor($request);
        abort_unless(
            $actor === null || $this->acceso->permiteSucursal($actor, (int) $sucursal->id),
            403,
            'No puedes vender en una sucursal que no te corresponde.',
        );

        $items = $this->pos->lineasPedidas($validado['items']);

        // A público en general no se le manda ticket; a una persona, solo si tiene correo.
        $comprador = ($validado['comprador_id'] ?? '') !== ''
            ? $this->compradores->resolver((string) $validado['comprador_id'], $actor)
            : null;

        // El reintento devuelve la venta que ya se hizo, pero solo después de revisar la
        // sede y al comprador (R05): repetir la clave y el cuerpo de una venta ajena no
        // la muestra a quien no la podría hacer.
        $previa = $clave !== null ? VentaPosTenant::query()->where('clave_intento', $clave)->first() : null;
        if ($previa instanceof VentaPosTenant) {
            PuntoDeVentaTenant::exigirMismaOperacion($previa, null, $huella);

            return response()->json(['data' => $this->presentar($previa->load(['sucursal', 'lineas.articulo', 'persona']))]);
        }
        $enviarTicket = $comprador !== null && (bool) ($validado['enviar_ticket'] ?? false);
        if ($enviarTicket && ($comprador->email ?? '') === '') {
            throw ValidationException::withMessages(['enviar_ticket' => ['No tiene correo: no se le puede mandar el ticket.']]);
        }

        try {
            $venta = $this->pos->vender(
                $sucursal, $items, $validado['metodo_pago'] ?? 'efectivo', $actor,
                (int) ($validado['propina_minor'] ?? 0), $comprador,
                isset($validado['recibido_minor']) ? (int) $validado['recibido_minor'] : null,
                $clave,
                huella: $huella,
                partes: $partes,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Dos intentos con la misma clave a la vez: el otro ya la registró.
            $previa = $clave !== null ? VentaPosTenant::query()->where('clave_intento', $clave)->first() : null;
            if (! $previa instanceof VentaPosTenant) {
                throw $e;
            }
            PuntoDeVentaTenant::exigirMismaOperacion($previa, null, $huella);

            return response()->json(['data' => $this->presentar($previa->load(['sucursal', 'lineas.articulo', 'persona']))]);
        }
        if ($enviarTicket) {
            $this->tickets->enviar($venta->load('persona'));
        }

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo', 'persona']))], 201);
    }

    /**
     * Reenvía el ticket de una venta (del mostrador o del cobro de una cita) a su
     * comprador (R06): solo manda el correo; el cobro, el dinero y el inventario no se
     * tocan. Quien cobra en el mostrador o cobra citas, de su sede.
     */
    public function reenviarTicket(Request $request): JsonResponse
    {
        $venta = VentaPosTenant::query()->where('ulid', (string) $request->route('venta'))->with('persona')->firstOrFail();
        $actor = $this->actor($request);
        abort_unless($actor === null || $actor->puede('pos.vender') || $actor->puede('ordenes.gestionar'), 403);
        abort_unless($actor === null || $this->acceso->permiteSucursal($actor, (int) $venta->sucursal_id), 403, 'Esta venta es de una sucursal que no tienes asignada.');
        app(AlcancePersonaTenant::class)->exigirPersona($request, $venta->persona);
        if ($venta->anulada_en !== null) {
            throw ValidationException::withMessages(['venta' => ['Esta venta está anulada: su ticket ya no se manda.']]);
        }
        if (($venta->persona->email ?? '') === '') {
            throw ValidationException::withMessages(['venta' => ['No tiene correo: no se le puede mandar el ticket.']]);
        }

        $envio = $this->tickets->enviar($venta);

        return response()->json(['data' => ['ticket_envio' => $envio]], $envio === 'fallido' ? 502 : 200);
    }

    /**
     * Corrige la forma de pago de una venta registrada con la equivocada (ADR 0089).
     * Cambia cómo cuadra el corte: pide el motivo (ADR 0166).
     */
    public function corregirMetodo(Request $request): JsonResponse
    {
        $venta = $this->ventaDeMiSede($request);
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(CorregirVentaPosTenant::METODOS)],
            'motivo' => ['required', 'string', 'max:255'],
        ]);
        $venta = $this->corregir->corregirMetodo($venta, $validado['metodo'], $this->actor($request), (string) $validado['motivo']);

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo', 'persona']))]);
    }

    /**
     * Anula una venta registrada por error: no cuenta en el corte y lo vendido
     * regresa al inventario (ADR 0089).
     */
    /**
     * Devuelve algunos productos de la venta (ADR 0178): las piezas por línea, el
     * motivo y, si se cobró con varias formas, por cuál sale el dinero.
     */
    public function devolver(Request $request): JsonResponse
    {
        $venta = $this->ventaDeMiSede($request);
        $validado = $request->validate([
            'lineas' => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*.id' => ['required', 'string'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'motivo' => ['required', 'string', 'max:255'],
            'metodo' => ['nullable', 'string', 'in:'.implode(',', CajasTenant::METODOS)],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $cantidades = [];
        foreach ($validado['lineas'] as $linea) {
            $cantidades[(string) $linea['id']] = ($cantidades[(string) $linea['id']] ?? 0) + (int) $linea['cantidad'];
        }
        $llave = $request->header('Idempotency-Key') ?: ($validado['idempotency_key'] ?? null);
        $this->devoluciones->devolver(
            $venta, $cantidades, $validado['motivo'], $validado['metodo'] ?? null, $this->actor($request),
            is_string($llave) && $llave !== '' ? mb_substr($llave, 0, 100) : null,
        );

        return response()->json(['data' => $this->presentar($venta->refresh()->load(['sucursal', 'lineas.articulo', 'persona']))], 201);
    }

    public function anular(Request $request): JsonResponse
    {
        $venta = $this->ventaDeMiSede($request);
        $validado = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        $venta = $this->corregir->anular($venta, $validado['motivo'], $this->actor($request));

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo', 'persona']))]);
    }

    /** La venta, si es de una sede que le corresponde a quien la toca (R19). */
    private function ventaDeMiSede(Request $request): VentaPosTenant
    {
        $venta = VentaPosTenant::query()->where('ulid', (string) $request->route('venta'))->firstOrFail();
        $actor = $this->actor($request);
        abort_unless($actor === null || $this->acceso->permiteSucursal($actor, (int) $venta->sucursal_id), 403);

        return $venta;
    }

    private function actor(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(VentaPosTenant $venta): array
    {
        $persona = $venta->persona;
        // Con varias formas (ADR 0177): cuánto con cada una; el cambio, del efectivo.
        $partes = CajasTenant::partesDelCobro($venta);
        $mixto = count($partes) > 1;

        return [
            'id' => $venta->ulid,
            'folio' => TicketVentaPosTenant::folio($venta),
            'sucursal' => $venta->sucursal?->nombre,
            // A quién se le vendió; null = público en general (ADR 0145).
            'comprador' => $persona !== null ? [
                'id' => $persona->ulid,
                'nombre' => $persona->nombreCompleto(),
                'tipo' => $this->compradores->tipo($persona),
                'genero' => $persona->genero?->value,
            ] : null,
            'ticket_enviado_en' => $venta->ticket_enviado_en?->toIso8601String(),
            // Cómo va el envío del ticket, aparte de la venta (R06).
            'ticket_envio' => $venta->estadoTicket(),
            'total_minor' => $venta->total_minor,
            // Aparte de lo vendido (ADR 0139): la propina, lo cobrado y el cambio.
            'propina_minor' => $venta->propina_minor,
            'cobrado_minor' => $venta->cobradoMinor(),
            'recibido_minor' => $venta->recibido_minor,
            'cambio_minor' => $venta->recibido_minor !== null
                ? $venta->recibido_minor - ($mixto ? PartesCobro::efectivo($partes) : $venta->cobradoMinor())
                : null,
            'moneda' => $venta->moneda,
            'metodo_pago' => $mixto ? PartesCobro::MIXTO : $venta->metodo_pago,
            'partes' => $mixto ? $partes : [],
            'creado_en' => $venta->created_at?->toIso8601String(),
            // Corregir la forma de pago o anularla (ADR 0089).
            'anulada_en' => $venta->anulada_en?->toIso8601String(),
            'motivo_anulacion' => $venta->motivo_anulacion,
            'corregible' => $this->corregir->impedimentoMetodo($venta) === null,
            'anulable' => $this->corregir->impedimentoAnular($venta) === null,
            // Devoluciones parciales (ADR 0178): cuánto salió y si aún se puede devolver.
            'devuelto_minor' => (int) $venta->devoluciones()->sum('monto_minor'),
            // Su factura, si ya se facturó (ADR 0179).
            'factura' => $venta->facturaTimbrada !== null
                ? ['id' => $venta->facturaTimbrada->ulid, 'uuid' => $venta->facturaTimbrada->uuid]
                : null,
            'facturable' => $venta->facturaTimbrada === null && $venta->anulada_en === null && (int) $venta->total_minor > 0
                && FacturasDeVentasTenant::quedaPorFacturar($venta),
            'devolvible' => $this->devoluciones->impedimento($venta) === null
                && $venta->lineas->contains(fn ($l): bool => (int) $l->cantidad > (int) $l->cantidad_devuelta),
            'lineas' => $venta->lineas->map(fn ($l): array => [
                'id' => $l->ulid,
                'articulo' => $l->articulo?->nombre,
                'cantidad' => $l->cantidad,
                'cantidad_devuelta' => (int) $l->cantidad_devuelta,
                'subtotal_minor' => $l->subtotal_minor,
                'extras' => array_map(static fn (array $e): string => (string) $e['nombre'], $l->extras ?? []),
            ])->all(),
        ];
    }
}
