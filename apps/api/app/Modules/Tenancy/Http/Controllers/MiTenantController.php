<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Pagos\MetodoPago;
use App\Modules\Reservas\EstadoReserva;
use App\Modules\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WaiverTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Autoservicio del miembro (data plane del tenant): opera SOLO sobre la persona del
 * usuario autenticado. La persona se resuelve por `usuario_id`, o por correo (enlace
 * diferido la primera vez). Sin permisos especiales: cada quien ve/gestiona lo suyo.
 */
class MiTenantController
{
    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly LibroMayorTenant $libro,
        private readonly WaiversTenant $waivers,
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrarOrden,
    ) {}

    /**
     * Waivers/consentimientos que el miembro tiene pendientes de aceptar (incluye
     * re-aceptacion cuando el estudio publica una version nueva).
     */
    public function waiversPendientes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $this->waivers->pendientesDe($persona)->map(fn (WaiverTenant $w): array => [
                'id' => $w->ulid,
                'clave' => $w->clave,
                'titulo' => $w->titulo,
                'contenido' => $w->contenido,
                'version' => $w->version,
            ])->all(),
        ]);
    }

    public function aceptarWaiver(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);

        $waiver = WaiverTenant::query()->where('ulid', (string) $request->route('waiver'))->firstOrFail();
        $aceptacion = $this->waivers->aceptar($persona, $waiver, $request->ip());

        return response()->json(['data' => [
            'waiver' => $waiver->ulid,
            'aceptado_en' => $aceptacion->aceptado_en->toIso8601String(),
        ]], 201);
    }

    public function perfil(Request $request): JsonResponse
    {
        $persona = $this->persona($request);

        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => ['persona' => null, 'derechos' => [], 'reservas' => []]]);
        }

        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->get()
            ->map(fn (DerechoTenant $d): array => [
                'id' => $d->ulid,
                'ilimitado' => $d->ilimitado,
                'saldo' => $d->ilimitado ? null : $this->libro->saldo($d),
                'disponible' => $d->ilimitado ? null : $this->libro->disponible($d),
            ])->all();

        $reservas = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            // Incluye las citas pendientes de pago: el miembro debe verlas para pagarlas.
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            ->with(['sesion.oferta', 'sesion.sucursal', 'orden'])
            ->get()
            ->filter(fn (ReservaTenant $r): bool => $r->sesion !== null && ! $r->sesion->inicia_en->isPast())
            ->map(fn (ReservaTenant $r): array => $this->presentarReserva($r))
            ->values()->all();

        // Política de cancelación global (para mostrar las reglas al miembro).
        $politica = PoliticaCancelacionTenant::query()->whereNull('actividad_id')->first();

        return response()->json(['data' => [
            'persona' => ['nombre' => $persona->nombreCompleto(), 'email' => $persona->email],
            'derechos' => $derechos,
            'reservas' => $reservas,
            'politica_cancelacion' => $politica instanceof PoliticaCancelacionTenant ? [
                'horas_limite' => $politica->horas_limite,
                'penaliza_tarde' => (bool) $politica->penaliza_tarde,
                'penaliza_no_show' => (bool) $politica->penaliza_no_show,
            ] : null,
        ]]);
    }

    public function agenda(Request $request): JsonResponse
    {
        $sesiones = SesionTenant::query()
            ->where('estado', 'programada')
            // Solo clases abiertas: las citas son privadas de su titular.
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('inicia_en', '>=', CarbonImmutable::now())
            ->with(['oferta', 'sucursal'])
            // Cupo ocupado = reservas que toman lugar (confirmadas, ofrecidas y
            // pendientes de pago, que retienen el cupo mientras se pagan).
            ->withCount(['reservas as ocupados' => fn ($q) => $q->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value])])
            ->orderBy('inicia_en')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $sesiones->map(fn (SesionTenant $s): array => [
                'id' => $s->ulid,
                'oferta' => $s->oferta?->nombre,
                'sucursal' => $s->sucursal?->nombre,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'zona_horaria' => $s->zona_horaria,
                'capacidad' => $s->capacidad,
                'ocupados' => (int) ($s->getAttribute('ocupados') ?? 0),
            ])->all(),
        ]);
    }

    public function reservar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');

        $validado = $request->validate([
            'sesion_id' => ['required', 'string'],
            'esperar' => ['boolean'],
        ]);

        $sesion = SesionTenant::query()->where('ulid', $validado['sesion_id'])->with('oferta')->firstOrFail();

        // Una cita es de su titular: nadie más puede reservarla ni esperar su lugar.
        if ($sesion->esCita()) {
            throw new SesionNoReservable('Esta cita es privada.');
        }

        // Citas (pago-para-reservar): si la oferta EXIGE pago, se crea una reserva
        // pendiente (retiene el cupo) + una orden por la sesión; el miembro paga esa
        // orden (checkout con las pasarelas) para CONFIRMAR. Si no, flujo por membresía.
        if ($sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago) {
            $monto = (int) ($sesion->oferta->precio_clase_minor ?? 0);
            abort_if($monto <= 0, 422, 'Esta clase requiere pago pero no tiene precio configurado.');
            $reserva = $this->reservas->reservarConPago(
                $sesion,
                $persona,
                $monto,
                'MXN',
                (int) $sesion->sucursal_id,
            );

            return response()->json(['data' => $this->presentarReserva($reserva->load(['sesion.oferta', 'orden']))], 201);
        }

        $reserva = $this->reservas->crear($sesion, $persona, null, (bool) ($validado['esperar'] ?? false));

        return response()->json(['data' => $this->presentarReserva($reserva->load('sesion.oferta'))], 201);
    }

    /**
     * Agenda una CITA desde un hueco de disponibilidad (F-08): elige servicio +
     * proveedor + hora y crea la sesión + la reserva (pago-para-reservar o membresía).
     * El miembro paga la `orden_id` devuelta (si es de pago) para confirmar.
     */
    public function agendarCita(Request $request, AgendarCitaTenant $agendar): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');

        $validado = $request->validate([
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['required', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail();
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();

        $reserva = $agendar->agendar($oferta, $sucursal, $persona, (int) $instructor->getKey(), $inicia, (int) $validado['duracion_minutos']);

        return response()->json(['data' => $this->presentarReserva($reserva->load(['sesion.oferta', 'orden']))], 201);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);

        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        $this->reservas->cancelar($reserva);

        return response()->json(['data' => $this->presentarReserva($reserva->refresh()->load('sesion.oferta'))]);
    }

    public function aceptar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);

        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        $this->reservas->aceptar($reserva);

        return response()->json(['data' => $this->presentarReserva($reserva->refresh()->load('sesion.oferta'))]);
    }

    /**
     * Resuelve la persona del usuario autenticado; enlaza por correo la primera vez.
     */
    private function persona(Request $request): ?PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        if (! $usuario instanceof Usuario) {
            return null;
        }

        $persona = PersonaTenant::query()->where('usuario_id', $usuario->getKey())->first();
        if ($persona instanceof PersonaTenant) {
            return $persona;
        }

        // Enlace diferido: una persona sin usuario con el mismo correo.
        $porCorreo = PersonaTenant::query()
            ->whereNull('usuario_id')
            ->where('email', $usuario->email)
            ->first();
        if ($porCorreo instanceof PersonaTenant) {
            $porCorreo->update(['usuario_id' => $usuario->getKey()]);
        }

        return $porCorreo;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarReserva(ReservaTenant $reserva): array
    {
        return [
            'id' => $reserva->ulid,
            // `sesion_id` + sucursal identifican la clase exacta: dos clases iguales en
            // distinta sucursal ya no se confunden (antes se relacionaban por oferta+hora).
            'sesion_id' => $reserva->sesion?->ulid,
            'estado' => $reserva->estado->value,
            'oferta' => $reserva->sesion?->oferta?->nombre,
            'sucursal' => $reserva->sesion?->sucursal?->nombre,
            'inicia_en' => $reserva->sesion?->inicia_en->toIso8601String(),
            'zona_horaria' => $reserva->sesion?->zona_horaria,
            // Vencimiento de la oferta de lista de espera (si la reserva está ofrecida).
            'oferta_expira_en' => $reserva->oferta_expira_en?->toIso8601String(),
            // Pago-para-reservar (citas): orden a pagar para confirmar (si aplica).
            'orden_id' => $reserva->orden?->ulid,
        ];
    }

    /**
     * Catálogo de productos que el alumno puede comprar desde su portal.
     */
    public function productos(): JsonResponse
    {
        $productos = ProductoTenant::query()->where('archivado', false)->orderBy('precio_minor')->get();

        return response()->json([
            'data' => $productos->map(static fn (ProductoTenant $p): array => [
                'id' => $p->ulid,
                'nombre' => $p->nombre,
                'tipo' => $p->tipo->value,
                'precio_minor' => $p->precio_minor,
                'moneda' => $p->moneda,
                'ilimitado' => $p->ilimitado,
                'creditos_incluidos' => $p->creditos_incluidos,
            ])->all(),
        ]);
    }

    /**
     * Historial de compras del alumno (sus órdenes), para ver pagos/estado.
     */
    public function ordenes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => []]);
        }

        $ordenes = OrdenTenant::query()
            ->where('persona_id', $persona->getKey())
            ->with('lineas.producto')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenTenant $o): array => $this->presentarOrden($o))->all(),
        ]);
    }

    /**
     * El alumno compra para SÍ MISMO: crea una orden pendiente (precio congelado). El
     * fulfillment (créditos) ocurre al pagarla en línea (webhook) o en el estudio.
     */
    public function comprar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');

        $validado = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'codigo_promo' => ['nullable', 'string', 'max:64'],
        ]);

        $items = [];
        foreach ($validado['items'] as $item) {
            $producto = ProductoTenant::query()->where('ulid', $item['producto_id'])->firstOrFail();
            // El alumno compra para sí: beneficiario = comprador (sin beneficiario explícito).
            $items[] = ['producto' => $producto, 'cantidad' => (int) $item['cantidad'], 'beneficiario' => null];
        }

        // Sucursal (R19): la compra del alumno se atribuye a su sede de casa.
        $sucursalId = $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null;
        $orden = $this->ordenes->crear($persona, $items, $validado['codigo_promo'] ?? null, $sucursalId);

        return response()->json(['data' => $this->presentarOrden($orden->refresh())], 201);
    }

    /**
     * El alumno paga EN LÍNEA su propia orden. Solo pasarelas en línea reales: el cobro
     * manual/efectivo es de ventanilla (staff) — un alumno no puede auto-aprobarse
     * créditos gratis. El fulfillment lo confirma el webhook de la pasarela.
     */
    public function cobrar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');

        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        abort_unless((int) $orden->persona_id === (int) $persona->getKey(), 403, 'Esta orden no es tuya.');

        $validado = $request->validate([
            'proveedor' => ['required', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        // El autoservicio solo admite pasarelas en línea (no ventanilla/manual/efectivo).
        if (in_array($validado['proveedor'], ['manual', 'efectivo'], true)) {
            throw ValidationException::withMessages([
                'proveedor' => ['El pago en efectivo o ventanilla se registra en el estudio.'],
            ]);
        }

        $metodo = isset($validado['metodo']) ? MetodoPago::from($validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? $validado['idempotency_key'] : null;

        $pago = $this->cobrarOrden->ejecutar($orden, $validado['proveedor'], $metodo, $key);

        return response()->json(['data' => [
            'pago' => $pago->ulid,
            'proveedor' => $pago->proveedor,
            'estado' => $pago->estado->value,
            'checkout' => $pago->checkout,
            'orden' => $this->presentarOrden($orden->refresh()),
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarOrden(OrdenTenant $orden): array
    {
        $orden->loadMissing('lineas.producto');

        return [
            'id' => $orden->ulid,
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
            'descuento_minor' => (int) ($orden->descuento_minor ?? 0),
            'moneda' => $orden->moneda,
            'metodo_pago' => $orden->metodo_pago,
            'fecha' => $orden->created_at?->toIso8601String(),
            'pagada_en' => $orden->pagada_en?->toIso8601String(),
            'lineas' => $orden->lineas->map(static fn (LineaOrdenTenant $l): array => [
                'producto' => $l->producto?->nombre,
                'cantidad' => $l->cantidad,
                'subtotal_minor' => $l->subtotal_minor,
            ])->all(),
        ];
    }
}
