<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\MembresiasPorModalidadTenant;
use App\Modules\Tenancy\Application\PresupuestosTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Exceptions\PresupuestoNoPermitido;
use App\Modules\Tenancy\Mail\CorreoPresupuesto;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\ConfiguracionPresupuestosTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\LineaPresupuestoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PresupuestoTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Presupuestos\EstadoPresupuesto;
use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
use App\Modules\Tenancy\Presupuestos\TipoLineaPresupuesto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Presupuestos de los negocios de citas (ADR 0127): lista, alta y edición, enviar por
 * correo, marcar aceptado o rechazado, convertir en cobro y la configuración de
 * impuestos y vigencia. Con alcance por sucursal como las ventas.
 */
class PresupuestosTenantController
{
    private const MAX_MINOR = 100_000_000_00;

    public function __construct(
        private readonly PresupuestosTenant $presupuestos,
        private readonly ResolverAccesoTenant $acceso,
    ) {}

    /**
     * Los presupuestos por páginas (antes, solo los últimos 300), por folio. «Vencido»
     * es uno sin responder cuya vigencia ya pasó en el negocio.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $estado = (string) $request->query('estado', '');
        $buscar = trim((string) $request->query('q', ''));
        $consulta = PresupuestoTenant::query()
            ->with(['persona', 'sucursal'])
            ->tap(fn (Builder $q) => $this->acotar($q, $request))
            ->orderByDesc('folio');
        if ($estado === 'vencido') {
            $consulta->whereIn('estado', [EstadoPresupuesto::Borrador->value, EstadoPresupuesto::Enviado->value])
                ->whereDate('valido_hasta', '<', app(FechasNegocioTenant::class)->hoy());
        } elseif ($estado !== '') {
            $consulta->where('estado', $estado);
        }
        if ($buscar !== '') {
            $consulta->where(fn (Builder $q) => $q
                ->where('nombre_contacto', 'like', "%{$buscar}%")
                ->orWhereHas('persona', fn (Builder $p) => $p->buscar($buscar))
                ->orWhere('folio', (int) ltrim(preg_replace('/\D/', '', $buscar) ?? '', '0')));
        }
        $pagina = $consulta->paginate((int) ($filtros['per_page'] ?? 25), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (PresupuestoTenant $p): array => $this->resumen($p))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->presupuesto($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $presupuesto = $this->presupuestos->crear($datos, $this->actor($request));

        return response()->json(['data' => $this->presentar($presupuesto)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $presupuesto = $this->presupuesto($request);
        $datos = $this->validar($request);

        return response()->json(['data' => $this->presentar($this->presupuestos->actualizar($presupuesto, $datos, $this->actor($request)))]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->presupuestos->eliminar($this->presupuesto($request), $this->actor($request));

        return response()->json(['data' => ['eliminado' => true]]);
    }

    /** Lo envía por correo al cliente (o al contacto) y queda como enviado. */
    public function enviar(Request $request): JsonResponse
    {
        $presupuesto = $this->presupuesto($request);
        $correo = $presupuesto->persona->email ?? $presupuesto->email_contacto;
        if ($correo === null || $correo === '') {
            throw new PresupuestoNoPermitido('El cliente no tiene correo: compártelo impreso o en PDF.', 'sin_correo');
        }
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        Mail::to($correo)->send(new CorreoPresupuesto(
            (string) $estudio->slug,
            (string) $estudio->nombre,
            $this->presentar($presupuesto),
            $estudio->contacto_email,
        ));
        $this->presupuestos->marcarEnviado($presupuesto);

        return response()->json(['data' => $this->presentar($presupuesto->refresh())]);
    }

    public function aceptar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->presupuestos->aceptar($this->presupuesto($request), $this->actor($request)))]);
    }

    public function rechazar(Request $request): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->presentar($this->presupuestos->rechazar($this->presupuesto($request), $datos['motivo'] ?? null, $this->actor($request)))]);
    }

    /** Aceptado, se vuelve una orden de cobro (se cobra en Por cobrar). */
    public function convertir(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor?->puede('ordenes.gestionar') === true, 403);
        $orden = $this->presupuestos->convertir($this->presupuesto($request), $actor);

        return response()->json(['data' => ['orden' => $orden->ulid, 'total_minor' => $orden->total_minor]], 201);
    }

    /** Lo que se puede presupuestar: servicios, paquetes/bonos/membresías y artículos. */
    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => [
            'servicios' => OfertaTenant::query()->orderBy('nombre')->get()
                ->map(fn (OfertaTenant $o): array => ['id' => $o->ulid, 'nombre' => $o->nombre, 'precio_minor' => (int) $o->precio_clase_minor, 'duracion_minutos' => $o->duracion_minutos])->all(),
            // Bonos y paquetes: en citas no hay membresías (ADR 0130).
            'productos' => app(MembresiasPorModalidadTenant::class)
                ->vendibles(ProductoTenant::query()->where('archivado', false))->orderBy('nombre')->get()
                ->map(fn (ProductoTenant $p): array => ['id' => $p->ulid, 'nombre' => $p->nombre, 'precio_minor' => (int) $p->precio_minor])->all(),
            'articulos' => ArticuloTenant::query()->where('activo', true)->orderBy('nombre')->get()
                ->map(fn (ArticuloTenant $a): array => ['id' => $a->ulid, 'nombre' => $a->nombre, 'precio_minor' => (int) $a->precio_minor])->all(),
        ]]);
    }

    public function configuracion(): JsonResponse
    {
        return response()->json(['data' => $this->presentarConfiguracion($this->presupuestos->configuracion())]);
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'impuestos' => ['required', Rule::enum(ModoImpuestos::class)],
            // 0 a 100 %, en puntos base (1600 = 16 %).
            'tasa_impuesto_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'vigencia_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->presentarConfiguracion($this->presupuestos->guardarConfiguracion($datos, $this->actor($request)))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'persona_id' => ['nullable', 'string'],
            'nombre_contacto' => ['nullable', 'string', 'max:255'],
            'email_contacto' => ['nullable', 'email', 'max:255'],
            'celular_contacto' => ['nullable', 'string', 'max:40'],
            'sucursal_id' => ['nullable', 'string'],
            'valido_hasta' => ['nullable', 'date_format:Y-m-d'],
            'descuento_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'notas' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:100'],
            'lineas.*.tipo' => ['required', Rule::enum(TipoLineaPresupuesto::class)],
            'lineas.*.referencia' => ['nullable', 'string', 'max:40'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:255'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'lineas.*.precio_unitario_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'lineas.*.descuento_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
        ]);

        // Los identificadores públicos, a los internos.
        if (($datos['persona_id'] ?? null) !== null) {
            $datos['persona_id'] = PersonaTenant::query()->where('ulid', $datos['persona_id'])->firstOrFail()->getKey();
        }
        $actor = $this->actor($request);
        if (($datos['sucursal_id'] ?? null) !== null) {
            $sucursal = SucursalTenant::query()->where('ulid', $datos['sucursal_id'])->firstOrFail();
            abort_unless($actor === null || $this->acceso->permiteSucursal($actor, (int) $sucursal->getKey()), 403);
            $datos['sucursal_id'] = $sucursal->getKey();
        } elseif ($actor !== null && ($permitidas = $this->acceso->sucursalesPermitidas($actor)) !== null) {
            // Personal acotado a una sede: el presupuesto es de su sede.
            $datos['sucursal_id'] = $permitidas[0] ?? null;
        }

        return $datos;
    }

    private function presupuesto(Request $request): PresupuestoTenant
    {
        $presupuesto = PresupuestoTenant::query()
            ->where('ulid', (string) $request->route('presupuesto'))
            ->tap(fn (Builder $q) => $this->acotar($q, $request))
            ->firstOrFail();

        return $presupuesto;
    }

    /**
     * Personal acotado por sucursal: solo los presupuestos de sus sedes.
     *
     * @param  Builder<PresupuestoTenant>  $consulta
     */
    private function acotar(Builder $consulta, Request $request): void
    {
        $actor = $this->actor($request);
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
        if ($permitidas !== null) {
            $consulta->where(fn (Builder $q) => $q->whereIn('sucursal_id', $permitidas)->orWhereNull('sucursal_id'));
        }
    }

    private function actor(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(PresupuestoTenant $p): array
    {
        return [
            'id' => $p->ulid,
            'folio' => $p->folioVisible(),
            'cliente' => $p->persona?->nombreCompleto() ?? $p->nombre_contacto,
            'estado' => $p->estado->value,
            'vencido' => $this->presupuestos->vencido($p),
            'valido_hasta' => $p->valido_hasta->toDateString(),
            'total_minor' => $p->total_minor,
            'moneda' => $p->moneda,
            'sucursal' => $p->sucursal?->nombre,
            'creado_en' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PresupuestoTenant $p): array
    {
        $p->loadMissing(['persona', 'sucursal', 'lineas', 'orden', 'creadoPor']);

        return $this->resumen($p) + [
            'persona_id' => $p->persona?->ulid,
            'contacto' => [
                'nombre' => $p->persona?->nombreCompleto() ?? $p->nombre_contacto,
                'email' => $p->persona->email ?? $p->email_contacto,
                'celular' => $p->persona->celular ?? $p->celular_contacto,
            ],
            'sucursal_id' => $p->sucursal?->ulid,
            'impuestos' => $p->impuestos->value,
            'tasa_impuesto_bp' => $p->tasa_impuesto_bp,
            'tasa_impuesto' => PresupuestosTenant::porcentaje($p->tasa_impuesto_bp),
            'subtotal_minor' => $p->subtotal_minor,
            'descuento_minor' => $p->descuento_minor,
            'impuesto_minor' => $p->impuesto_minor,
            'notas' => $p->notas,
            'enviado_en' => $p->enviado_en?->toIso8601String(),
            'respondido_en' => $p->respondido_en?->toIso8601String(),
            'motivo_rechazo' => $p->motivo_rechazo,
            'orden' => $p->orden?->ulid,
            'creado_por' => $p->creadoPor?->nombreCorto(),
            'lineas' => $p->lineas->map(fn (LineaPresupuestoTenant $l): array => [
                'tipo' => $l->tipo->value,
                'referencia' => $this->referenciaPublica($l),
                'descripcion' => $l->descripcion,
                'cantidad' => $l->cantidad,
                'precio_unitario_minor' => $l->precio_unitario_minor,
                'descuento_minor' => $l->descuento_minor,
                'subtotal_minor' => $l->subtotal_minor,
            ])->all(),
            'editable' => $p->estado->editable(),
            'puede_convertir' => $p->estado === EstadoPresupuesto::Aceptado,
        ];
    }

    private function referenciaPublica(LineaPresupuestoTenant $linea): ?string
    {
        if ($linea->referencia_id === null) {
            return null;
        }
        $modelo = match ($linea->tipo) {
            TipoLineaPresupuesto::Servicio => OfertaTenant::query()->find($linea->referencia_id),
            TipoLineaPresupuesto::Producto => ProductoTenant::query()->find($linea->referencia_id),
            TipoLineaPresupuesto::Articulo => ArticuloTenant::query()->find($linea->referencia_id),
            TipoLineaPresupuesto::Libre => null,
        };

        return $modelo?->getAttribute('ulid');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarConfiguracion(ConfiguracionPresupuestosTenant $c): array
    {
        return [
            'impuestos' => $c->impuestos->value,
            'tasa_impuesto_bp' => $c->tasa_impuesto_bp,
            'vigencia_dias' => $c->vigencia_dias,
            'notas' => $c->notas,
        ];
    }
}
