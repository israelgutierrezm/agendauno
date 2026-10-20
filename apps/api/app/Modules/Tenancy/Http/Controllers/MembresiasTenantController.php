<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\CorteDePlanesTenant;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasPorModalidadTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Membresias\BajaAnticipada;
use App\Modules\Tenancy\Membresias\CondicionesDePlan;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\ConfiguracionCuotasTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\Exceptions\CondicionesNoAceptadas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membresias del estudio (data plane del tenant): productos vendibles, venta
 * (acuerdo → derecho + concesion de creditos) y consulta de derechos con su saldo
 * derivado del ledger. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class MembresiasTenantController
{
    private const LIMITE = 200;

    /** Tope de la cantidad de vigencia (366 días o meses): evita capturas absurdas. */
    private const VIGENCIA_MAXIMA = 366;

    public function __construct(
        private readonly MembresiasTenant $membresias,
        private readonly LibroMayorTenant $libro,
        private readonly RegistrarAuditoria $auditoria,
        private readonly MembresiasPorModalidadTenant $modalidades,
    ) {}

    public function productos(Request $request): JsonResponse
    {
        // Por defecto solo vendibles (no archivados). El editor pide `?incluir=todos`
        // para poder ver y reactivar los archivados.
        // Los que están a la venta primero: si hubiera más de LIMITE, sobran archivados.
        // Nada se corta en silencio: `meta.truncado` lo dice.
        $productos = ProductoTenant::query()
            ->when((string) $request->query('incluir') !== 'todos', fn ($q) => $q->where('archivado', false))
            ->with(['actividad', 'sucursal'])
            ->orderBy('archivado')
            ->orderByDesc('id')
            ->limit(self::LIMITE + 1)
            ->get();
        $truncado = $productos->count() > self::LIMITE;

        return response()->json([
            'data' => $productos->take(self::LIMITE)->map(fn (ProductoTenant $producto): array => $this->presentarProducto($producto))->values()->all(),
            'meta' => ['truncado' => $truncado, 'limite' => self::LIMITE],
        ]);
    }

    public function crearProducto(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoProducto::class)],
            'precio_minor' => ['required', 'integer', 'min:0'],
            // Sin moneda, la del negocio; siempre una del catálogo (ADR 0097).
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'ilimitado' => ['boolean'],
            'creditos_incluidos' => ['nullable', 'integer', 'min:0'],
            'vigencia_tipo' => ['nullable', Rule::enum(TipoVigencia::class)],
            'vigencia_cantidad' => ['nullable', 'required_with:vigencia_tipo', 'integer', 'min:1', 'max:'.self::VIGENCIA_MAXIMA],
            'politica_reset' => ['nullable', Rule::enum(PoliticaReset::class)],
            'unidades_por_ciclo' => ['nullable', 'integer', 'min:0'],
            'politica_rollover' => ['nullable', Rule::enum(PoliticaRollover::class)],
            'rollover_max' => ['nullable', 'integer', 'min:0'],
            'actividad_id' => ['nullable', 'string'],
            'sucursal_id' => ['nullable', 'string', 'prohibited_with:sucursal_ids'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'min:1', 'max:200'],
            'sucursal_ids.*' => ['string', 'distinct'],
            'ofertas' => ['sometimes', 'array', 'max:200'],
            'ofertas.*' => ['string', 'distinct'],
            // Para el público (M03/B03).
            'descripcion' => ['nullable', 'string', 'max:300'],
            'destacado' => ['sometimes', 'boolean'],
            'primera_vez' => ['sometimes', 'boolean'],
            // Cuotas del negocio (B04).
            'cobra_inscripcion' => ['sometimes', 'boolean'],
            'cobra_anualidad' => ['sometimes', 'boolean'],
            ...self::reglasPermanencia(),
        ]);
        // Un negocio de citas vende bonos de sesiones, no membresías (ADR 0130).
        $this->modalidades->exigirAtributos($validado);
        self::exigirCuotasConMonto($validado);

        $producto = $this->membresias->crearProducto(
            $validado['nombre'],
            TipoProducto::from($validado['tipo']),
            (int) $validado['precio_minor'],
            mb_strtoupper((string) ($validado['moneda'] ?? app(ParametrosTenant::class)->moneda())),
            (bool) ($validado['ilimitado'] ?? false),
            isset($validado['creditos_incluidos']) ? (int) $validado['creditos_incluidos'] : null,
            isset($validado['politica_reset']) ? PoliticaReset::from($validado['politica_reset']) : PoliticaReset::Ninguno,
            isset($validado['unidades_por_ciclo']) ? (int) $validado['unidades_por_ciclo'] : null,
            isset($validado['politica_rollover']) ? PoliticaRollover::from($validado['politica_rollover']) : PoliticaRollover::Ninguno,
            isset($validado['rollover_max']) ? (int) $validado['rollover_max'] : null,
            $this->resolverId(ActividadTenant::class, $validado['actividad_id'] ?? null),
            $this->resolverId(SucursalTenant::class, $validado['sucursal_id'] ?? null),
            isset($validado['vigencia_tipo'])
                ? new VigenciaProducto(TipoVigencia::from($validado['vigencia_tipo']), (int) $validado['vigencia_cantidad'])
                : null,
            $this->ofertasDe($validado['ofertas'] ?? []),
            isset($validado['sucursal_ids']) ? $this->sucursalesDe($validado['sucursal_ids']) : null,
        );
        $producto->update([
            'descripcion' => self::texto($validado['descripcion'] ?? null),
            'destacado' => (bool) ($validado['destacado'] ?? false),
            'primera_vez' => (bool) ($validado['primera_vez'] ?? false),
            'cobra_inscripcion' => (bool) ($validado['cobra_inscripcion'] ?? false),
            'cobra_anualidad' => (bool) ($validado['cobra_anualidad'] ?? false),
            ...self::permanencia($validado),
        ]);

        return response()->json(['data' => $this->presentarProducto($producto)], 201);
    }

    /**
     * Editor completo: actualiza la plantilla del producto (precio, acceso, vigencia,
     * ciclos/rollover, restricciones) o lo archiva/reactiva. Solo cambia ventas futuras
     * (no toca acuerdos ya vendidos). Cambio sensible → queda en la bitácora. Archivarlo
     * es su baja: pide `productos.eliminar` (ADR 0077).
     */
    public function actualizarProducto(Request $request): JsonResponse
    {
        $producto = ProductoTenant::query()->where('ulid', (string) $request->route('producto'))->firstOrFail();

        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['sometimes', Rule::enum(TipoProducto::class)],
            'precio_minor' => ['sometimes', 'integer', 'min:0'],
            'moneda' => ['sometimes', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'ilimitado' => ['sometimes', 'boolean'],
            'creditos_incluidos' => ['nullable', 'integer', 'min:0'],
            'vigencia_tipo' => ['sometimes', 'nullable', Rule::enum(TipoVigencia::class)],
            'vigencia_cantidad' => ['nullable', 'required_with:vigencia_tipo', 'integer', 'min:1', 'max:'.self::VIGENCIA_MAXIMA],
            'politica_reset' => ['sometimes', Rule::enum(PoliticaReset::class)],
            'unidades_por_ciclo' => ['nullable', 'integer', 'min:0'],
            'politica_rollover' => ['sometimes', Rule::enum(PoliticaRollover::class)],
            'rollover_max' => ['nullable', 'integer', 'min:0'],
            'archivado' => ['sometimes', 'boolean'],
            'actividad_id' => ['sometimes', 'nullable', 'string'],
            'sucursal_id' => ['sometimes', 'nullable', 'string', 'prohibited_with:sucursal_ids'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'min:1', 'max:200'],
            'sucursal_ids.*' => ['string', 'distinct'],
            'ofertas' => ['sometimes', 'array', 'max:200'],
            'ofertas.*' => ['string', 'distinct'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:300'],
            'destacado' => ['sometimes', 'boolean'],
            'primera_vez' => ['sometimes', 'boolean'],
            'cobra_inscripcion' => ['sometimes', 'boolean'],
            'cobra_anualidad' => ['sometimes', 'boolean'],
            ...self::reglasPermanencia(),
        ]);

        self::exigirCuotasConMonto($validado);

        $actor = $request->attributes->get('usuario_tenant');
        $archiva = (bool) ($validado['archivado'] ?? false) && ! $producto->archivado;
        abort_if($archiva && ! ($actor instanceof Usuario && $actor->puede('productos.eliminar')), 403);

        $atributos = [];
        foreach (['nombre', 'precio_minor', 'moneda', 'ilimitado', 'creditos_incluidos', 'vigencia_cantidad', 'unidades_por_ciclo', 'rollover_max', 'archivado', 'destacado', 'primera_vez', 'cobra_inscripcion', 'cobra_anualidad'] as $campo) {
            if ($request->has($campo)) {
                $atributos[$campo] = $validado[$campo] ?? null;
            }
        }
        if (isset($atributos['moneda'])) {
            $atributos['moneda'] = mb_strtoupper((string) $atributos['moneda']);
        }
        if ($request->has('descripcion')) {
            $atributos['descripcion'] = self::texto($validado['descripcion'] ?? null);
        }
        if ($request->has('permanencia_meses')) {
            $atributos = [...$atributos, ...self::permanencia($validado)];
        }
        if ($request->has('tipo')) {
            $atributos['tipo'] = TipoProducto::from($validado['tipo']);
        }
        if ($request->has('vigencia_tipo')) {
            $atributos['vigencia_tipo'] = isset($validado['vigencia_tipo']) ? TipoVigencia::from($validado['vigencia_tipo']) : null;
        }
        if ($request->has('politica_reset')) {
            $atributos['politica_reset'] = PoliticaReset::from($validado['politica_reset']);
        }
        if ($request->has('politica_rollover')) {
            $atributos['politica_rollover'] = PoliticaRollover::from($validado['politica_rollover']);
        }
        if ($request->has('actividad_id')) {
            $atributos['actividad_id'] = $this->resolverId(ActividadTenant::class, $validado['actividad_id'] ?? null);
        }
        if ($request->has('sucursal_id')) {
            $atributos['sucursal_id'] = $this->resolverId(SucursalTenant::class, $validado['sucursal_id'] ?? null);
            $atributos['sucursales_ids'] = null;
        }
        if (array_key_exists('sucursal_ids', $validado)) {
            $atributos['sucursal_id'] = null;
            $atributos['sucursales_ids'] = isset($validado['sucursal_ids']) ? $this->sucursalesDe($validado['sucursal_ids']) : null;
        }

        // Como queda: en citas no puede quedar a la venta como membresía (ADR 0130).
        $this->modalidades->exigirAtributos([
            ...$producto->only(['tipo', 'politica_reset', 'ilimitado', 'permanencia_meses', 'cobra_inscripcion', 'cobra_anualidad', 'archivado']),
            ...$atributos,
        ]);

        $antes = $producto->only(['nombre', 'precio_minor', 'ilimitado', 'creditos_incluidos', 'vigencia_tipo', 'vigencia_cantidad', 'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max', 'archivado', 'actividad_id', 'sucursal_id', 'sucursales_ids']);
        $this->membresias->actualizarProducto(
            $producto,
            $atributos,
            $request->has('ofertas') ? $this->ofertasDe($validado['ofertas'] ?? []) : null,
        );

        $this->auditoria->registrar(
            $actor instanceof Usuario ? $actor : null,
            'producto.actualizado',
            'producto',
            $producto->ulid,
            $antes,
            $producto->only(['nombre', 'precio_minor', 'ilimitado', 'creditos_incluidos', 'vigencia_tipo', 'vigencia_cantidad', 'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max', 'archivado', 'actividad_id', 'sucursal_id', 'sucursales_ids']),
        );

        return response()->json(['data' => $this->presentarProducto($producto)]);
    }

    public function vender(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'producto_id' => ['required', 'string'],
            'fecha_inicio' => ['nullable', 'date'],
            'acepta_condiciones' => ['sometimes', 'boolean'],
            // Una cortesía (sin cobro) siempre dice por qué (ADR 0181).
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);
        $producto = ProductoTenant::query()->where('ulid', $validado['producto_id'])->firstOrFail();
        $this->modalidades->exigirQueSeVenda($producto);

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        // Permanencia (B04): el negocio registra que el cliente aceptó sus condiciones.
        $aceptada = ($validado['acepta_condiciones'] ?? false) === true;
        if (CondicionesDePlan::aplica($producto) && ! $aceptada) {
            throw new CondicionesNoAceptadas("«{$producto->nombre}» tiene permanencia: acepta sus condiciones para venderlo.");
        }
        $acuerdo = $this->membresias->venderProducto(
            $persona,
            $producto,
            $validado['fecha_inicio'] ?? null,
            $actor,
            condiciones: $aceptada ? CondicionesDePlan::aceptadas($producto, 'negocio', $actor, $request->ip()) : null,
        );
        $derecho = $acuerdo->derechos()->firstOrFail();
        app(RegistrarAuditoria::class)->registrar($actor, 'membresia.cortesia', 'acuerdo', (string) $acuerdo->ulid,
            null, ['persona' => $persona->ulid, 'producto' => $producto->nombre], trim((string) $validado['motivo']));

        return response()->json([
            'data' => [
                'acuerdo' => $acuerdo->ulid,
                'persona' => $persona->ulid,
                'producto' => $producto->nombre,
                'derecho' => $this->presentarDerecho($derecho),
            ],
        ], 201);
    }

    public function derechos(Request $request): JsonResponse
    {
        // Su historial se consulta aunque esté dada de baja.
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);

        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $derechos->map(fn (DerechoTenant $derecho): array => $this->presentarDerecho($derecho))->all(),
        ]);
    }

    /**
     * Corte de los planes de una persona (el mismo que ve en su cuenta).
     */
    public function planes(Request $request, CorteDePlanesTenant $corte): JsonResponse
    {
        // Su historial se consulta aunque esté dada de baja.
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);

        return response()->json(['data' => $corte->dePersona($persona)]);
    }

    /**
     * En qué clases se usó un plan, por páginas (el corte solo trae los más recientes).
     */
    public function usosPlan(Request $request, CorteDePlanesTenant $corte): JsonResponse
    {
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $plan = DerechoTenant::query()
            ->where('ulid', (string) $request->route('derecho'))
            ->whereNull('extra_de_id')
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->firstOrFail();

        return response()->json($corte->usosDe($plan, (int) ($validado['page'] ?? 1), (int) ($validado['per_page'] ?? 20)));
    }

    public function topUp(Request $request): JsonResponse
    {
        $derecho = DerechoTenant::query()->where('ulid', (string) $request->route('derecho'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirDerecho($request, $derecho);
        $validado = $request->validate([
            'unidades' => ['required', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $unidades = (int) $validado['unidades'];
        $descripcion = is_string($validado['descripcion'] ?? null) ? $validado['descripcion'] : null;

        // Concesion manual de credito = operacion sensible: el actor queda en el propio
        // asiento del ledger (origen/actor) y en la bitacora transversal de auditoria.
        $actor = $request->attributes->get('usuario_tenant');
        $actorUsuario = $actor instanceof Usuario ? $actor : null;
        $this->membresias->agregarTopUp($derecho, $unidades, $descripcion, $actorUsuario);

        $this->auditoria->registrar(
            $actorUsuario,
            'credito.top_up',
            'derecho',
            $derecho->ulid,
            null,
            ['unidades' => $unidades, 'saldo_nuevo' => $this->libro->saldo($derecho->refresh())],
            $descripcion,
        );

        return response()->json(['data' => $this->presentarDerecho($derecho->refresh())], 201);
    }

    /**
     * Resuelve un ulid a la PK interna de un modelo tenant (o null).
     *
     * @param  class-string<ActividadTenant|SucursalTenant>  $modelo
     */
    private function resolverId(string $modelo, ?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $encontrado = $modelo::query()->where('ulid', $ulid)->first();

        return $encontrado instanceof $modelo ? (int) $encontrado->getKey() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarProducto(ProductoTenant $producto): array
    {
        $producto->loadMissing(['actividad', 'sucursal', 'ofertas']);

        return [
            'id' => $producto->ulid,
            'nombre' => $producto->nombre,
            'tipo' => $producto->tipo->value,
            'precio_minor' => $producto->precio_minor,
            'moneda' => $producto->moneda,
            'ilimitado' => $producto->ilimitado,
            'creditos_incluidos' => $producto->creditos_incluidos,
            'vigencia_tipo' => $producto->vigencia_tipo?->value,
            'vigencia_cantidad' => $producto->vigencia_cantidad,
            // Para mostrar la vigencia sin adivinar: hasta cuándo, si se compra hoy.
            'vence_si_compra_hoy' => VigenciaProducto::de($producto)?->hasta(now($this->membresias->zona())->toDateString()),
            'archivado' => $producto->archivado,
            'descripcion' => $producto->descripcion,
            'destacado' => $producto->destacado,
            'primera_vez' => $producto->primera_vez,
            'cobra_inscripcion' => $producto->cobra_inscripcion,
            'cobra_anualidad' => $producto->cobra_anualidad,
            // Permanencia (B04) y las condiciones que se aceptan al comprarlo.
            'permanencia_meses' => $producto->permanencia_meses,
            'baja_anticipada' => $producto->baja_anticipada?->value,
            'penalizacion_minor' => $producto->penalizacion_minor,
            'penalizacion_porcentaje' => $producto->penalizacion_porcentaje,
            'condiciones' => CondicionesDePlan::texto($producto),
            'politica_reset' => $producto->politica_reset->value,
            'unidades_por_ciclo' => $producto->unidades_por_ciclo,
            'politica_rollover' => $producto->politica_rollover->value,
            'rollover_max' => $producto->rollover_max,
            'actividad_id' => $producto->actividad?->ulid,
            'actividad' => $producto->actividad?->nombre,
            'sucursal_id' => $producto->sucursal?->ulid,
            'sucursal' => $producto->sucursal?->nombre,
            ...$producto->coberturaSucursales(),
            // Clases o servicios a los que aplica; vacío = a todos.
            'ofertas' => $producto->ofertas
                ->map(fn (OfertaTenant $o): array => ['id' => $o->ulid, 'nombre' => $o->nombre])
                ->values()->all(),
        ];
    }

    /**
     * Ids internos de las sucursales elegidas (por su ulid). Una que no exista es un
     * error de captura, no se ignora en silencio.
     *
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function sucursalesDe(array $ulids): array
    {
        $ids = SucursalTenant::query()->whereIn('ulid', $ulids)->pluck('id');
        if ($ids->count() !== count($ulids)) {
            throw ValidationException::withMessages(['sucursal_ids' => 'Alguna de las sucursales elegidas ya no existe.']);
        }

        return $ids->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Ids internos de las clases elegidas (por su ulid). Una que no exista es un error
     * de captura, no se ignora en silencio.
     *
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function ofertasDe(array $ulids): array
    {
        $ids = OfertaTenant::query()->whereIn('ulid', $ulids)->pluck('id', 'ulid');
        if ($ids->count() !== count($ulids)) {
            throw ValidationException::withMessages(['ofertas' => 'Alguna de las clases elegidas ya no existe.']);
        }

        return array_values(array_map('intval', $ids->all()));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarDerecho(DerechoTenant $derecho): array
    {
        return [
            'id' => $derecho->ulid,
            'ambito' => $derecho->ambito,
            ...$derecho->coberturaSucursales(),
            'ilimitado' => $derecho->ilimitado,
            'saldo' => $derecho->ilimitado ? null : $this->libro->saldo($derecho),
            'disponible' => $derecho->ilimitado ? null : $this->libro->disponible($derecho),
        ];
    }

    /**
     * Permanencia (B04): meses (0 o vacío = sin permanencia) y qué paga si da de baja
     * antes; el monto fijo solo cuando es por monto.
     *
     * @return array<string, list<mixed>>
     */
    private static function reglasPermanencia(): array
    {
        return [
            'permanencia_meses' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'baja_anticipada' => ['sometimes', 'nullable', Rule::enum(BajaAnticipada::class)],
            'penalizacion_minor' => ['sometimes', 'nullable', 'required_if:baja_anticipada,monto_fijo', 'integer', 'min:0', 'max:100000000'],
            // Con «meses restantes», qué porcentaje de lo que falte (vacío = 100; ADR 0137).
            'penalizacion_porcentaje' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validado
     * @return array{permanencia_meses: int|null, baja_anticipada: string|null, penalizacion_minor: int|null, penalizacion_porcentaje: int|null}
     */
    private static function permanencia(array $validado): array
    {
        $meses = (int) ($validado['permanencia_meses'] ?? 0);
        if ($meses <= 0) {
            return ['permanencia_meses' => null, 'baja_anticipada' => null, 'penalizacion_minor' => null, 'penalizacion_porcentaje' => null];
        }
        $modo = BajaAnticipada::tryFrom((string) ($validado['baja_anticipada'] ?? '')) ?? BajaAnticipada::SinPenalizacion;

        return [
            'permanencia_meses' => $meses,
            'baja_anticipada' => $modo->value,
            'penalizacion_minor' => $modo === BajaAnticipada::MontoFijo ? (int) ($validado['penalizacion_minor'] ?? 0) : null,
            'penalizacion_porcentaje' => $modo === BajaAnticipada::MesesRestantes ? (int) ($validado['penalizacion_porcentaje'] ?? 100) : null,
        ];
    }

    /** Un texto opcional sin espacios sobrantes (vacío = null). */
    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Cobrar una inscripción o una anualidad que valen 0 no cobraría nada (y el plan
     * pasaría por membresía): primero se define su monto en las cuotas del negocio.
     *
     * @param  array<string, mixed>  $validado
     */
    private static function exigirCuotasConMonto(array $validado): void
    {
        $cuotas = ConfiguracionCuotasTenant::actual();
        $errores = [];
        if ((bool) ($validado['cobra_inscripcion'] ?? false) && $cuotas->inscripcion_minor <= 0) {
            $errores['cobra_inscripcion'] = ['Primero define el monto de la inscripción en las cuotas del negocio.'];
        }
        if ((bool) ($validado['cobra_anualidad'] ?? false) && $cuotas->anualidad_minor <= 0) {
            $errores['cobra_anualidad'] = ['Primero define el monto de la anualidad en las cuotas del negocio.'];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }
}
