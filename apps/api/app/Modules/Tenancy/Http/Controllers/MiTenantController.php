<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\AntesDeReservarTenant;
use App\Modules\Tenancy\Application\AtribucionTenant;
use App\Modules\Tenancy\Application\CalcularDisponibilidadTenant;
use App\Modules\Tenancy\Application\ClimaTenant;
use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\CorteDePlanesTenant;
use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\CuotasTenant;
use App\Modules\Tenancy\Application\CupoDeSesionTenant;
use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\FormulariosDePersonaTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasPorModalidadTenant;
use App\Modules\Tenancy\Application\ModalidadNegocioTenant;
use App\Modules\Tenancy\Application\OpcionesCitaTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Application\PoliticaCancelacion;
use App\Modules\Tenancy\Application\PortalDelClienteTenant;
use App\Modules\Tenancy\Application\PresentarMovimientosCreditoTenant;
use App\Modules\Tenancy\Application\PrimeraVezTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ResolverDerechoTenant;
use App\Modules\Tenancy\Application\VentanaDeReservaTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use App\Modules\Tenancy\Http\SesionTenantPresenter;
use App\Modules\Tenancy\Medicion\Atribucion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Membresias\CondicionesDePlan;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\Models\ConfiguracionCuotasTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WaiverTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\CanalReserva;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\Reservas\QuienCancela;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
    // Movimientos de créditos que ve el alumno (los más recientes).
    /** Planes vencidos que acompañan a los vigentes en el perfil (el resto, en «Mis planes»). */
    private const VENCIDOS_EN_PERFIL = 3;

    private const LIMITE_MOVIMIENTOS = 200;

    /** Días que abarca como máximo un periodo de la agenda del alumno (un mes y algo). */
    private const DIAS_AGENDA = 62;

    /** Tope de clases de un periodo: si se llena, la respuesta lo dice (`truncado`). */
    private const LIMITE_AGENDA = 500;

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly LibroMayorTenant $libro,
        private readonly WaiversTenant $waivers,
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrarOrden,
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly RegistroDePasarelasTenant $pasarelas,
        private readonly FormulariosDePersonaTenant $formularios,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly PortalDelClienteTenant $portal,
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    /**
     * Los formularios que le tocan al alumno, con lo que ya respondió. Los responde
     * él mismo (POST /mi/formularios/{formulario}/respuestas).
     */
    public function formularios(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return response()->json(['data' => [
            'persona_id' => $persona->ulid,
            'formularios' => $this->formularios->de($persona),
        ]]);
    }

    /**
     * Llena una ficha desde su cuenta: la suya o, con `X-Participante`, la de una persona
     * a su cargo (B05). La persona sale de la cuenta en uso, nunca del cuerpo.
     */
    public function responderFormulario(Request $request, RespuestasFormularioController $respuestas): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');
        $formulario = Formulario::query()
            ->where('ulid', (string) $request->route('formulario'))
            ->where('activo', true)
            ->firstOrFail();

        /** @var array<string, mixed> $entrada */
        $entrada = is_array($request->input('valores')) ? $request->input('valores') : [];
        $respuesta = $respuestas->responder($formulario, $persona, $entrada);

        return response()->json(['data' => ['id' => $respuesta->ulid, 'valores' => $respuesta->valores]], 201);
    }

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

    /**
     * Su cuenta: sus planes con su estado EFECTIVO (vigente, por empezar, en pausa,
     * suspendido, vencido…: el Inicio no lo deduce solo por el vencimiento), sus
     * próximas reservas, la política de cancelación y qué partes de su cuenta usa.
     */
    public function perfil(Request $request, CorteDePlanesTenant $corte): JsonResponse
    {
        $persona = $this->persona($request);

        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => ['persona' => null, 'derechos' => [], 'reservas' => []]]);
        }

        $hoy = $corte->hoy();
        // Lo que sirve hoy (vigentes, en pausa, por empezar) y los últimos vencidos: su
        // historial completo está en «Mis planes». Una persona con años de paquetes no
        // carga (ni calcula el saldo de) todos.
        $deLaPersona = fn () => DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->with(['acuerdo.producto', 'acuerdo.pausaAbierta']);
        $derechos = $deLaPersona()
            ->where(fn ($q) => $q->whereNull('valido_hasta')->orWhere('valido_hasta', '>=', $hoy))
            ->get()
            ->concat($deLaPersona()
                ->whereNotNull('valido_hasta')->where('valido_hasta', '<', $hoy)
                ->orderByDesc('valido_hasta')->orderByDesc('id')
                ->limit(self::VENCIDOS_EN_PERFIL)->get())
            ->map(function (DerechoTenant $d) use ($corte, $hoy): array {
                $saldo = $d->ilimitado ? null : $this->libro->saldo($d);
                $disponible = $d->ilimitado ? null : $this->libro->disponible($d);

                return [
                    'id' => $d->ulid,
                    'producto' => $d->acuerdo?->producto?->nombre,
                    ...$d->coberturaSucursales(),
                    'pausa_hasta' => $d->acuerdo?->pausaAbierta?->hasta->toDateString(),
                    'ilimitado' => $d->ilimitado,
                    'saldo' => $saldo,
                    'disponible' => $disponible,
                    'estado' => $corte->estadoEfectivo($d, $hoy, (int) $disponible, (int) $saldo - (int) $disponible),
                    // Desde y hasta cuándo se puede usar (null = desde ya / no vence).
                    'desde' => $d->valido_desde?->toDateString(),
                    'vence' => $d->valido_hasta?->toDateString(),
                ];
            })->all();

        $reservas = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            // Incluye las citas pendientes de pago: el miembro debe verlas para pagarlas.
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            // Con los conteos de su sesión, su asistencia y su orden: el contrato de la
            // agenda (`clase`, `cita`, `ocupacion`) sin una consulta por reserva.
            // Solo las que aún no empiezan: las pasadas siguen «confirmadas» al asistir y
            // con los años serían cientos.
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now()))
            ->with([
                'sesion' => fn ($q) => $q->withCount(SesionTenantPresenter::conteos()),
                'sesion.oferta', 'sesion.sucursal', 'sesion.instructor', 'orden', 'asistencia',
                'derecho.acuerdo.producto',
            ])
            ->get()
            ->filter(fn (ReservaTenant $r): bool => $r->sesion !== null && ! $r->sesion->inicia_en->isPast())
            ->map(fn (ReservaTenant $r): array => $this->presentarReserva($r))
            ->values()->all();

        // Política de cancelación global (para mostrar las reglas al miembro).
        $politica = PoliticaCancelacionTenant::query()->whereNull('actividad_id')->first();

        return response()->json(['data' => [
            'persona' => ['nombre' => $persona->nombreCompleto(), 'email' => $persona->email],
            // Si el estudio cobra en línea, el alumno puede pagar aquí sus compras.
            'pago_en_linea' => $this->pasarelas->enLinea() !== null,
            // La pasarela está en pruebas: se le dice que no se cobra de verdad (M07).
            'pago_en_linea_prueba' => $this->pasarelas->enPrueba(),
            // La pasarela admite pago automático (domiciliar membresías al pagarlas). En
            // citas no: no hay nada que se renueve (ADR 0130).
            'pago_automatico' => $this->domiciliaciones->proveedor() !== null
                && ! app(MembresiasPorModalidadTenant::class)->enCitas(),
            'derechos' => $derechos,
            'reservas' => $reservas,
            // Qué partes de su cuenta le sirven y su asistencia reciente (ADR 0091).
            'portal' => $this->portal->capacidades($this->estudioDe($request), $persona),
            'asistencias_30_dias' => $this->portal->asistencias($persona),
            // La global o, si el negocio no configuró una, la de la plataforma (M05).
            'politica_cancelacion' => $politica instanceof PoliticaCancelacionTenant ? [
                'horas_limite' => $politica->horas_limite,
                'penaliza_tarde' => (bool) $politica->penaliza_tarde,
                'penaliza_no_show' => (bool) $politica->penaliza_no_show,
            ] : $this->politicaPorDefecto(),
        ]]);
    }

    /**
     * Pase de entrada (QR) del alumno: vence en minutos; la pantalla lo renueva.
     */
    public function pase(Request $request, PaseAccesoTenant $pases): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 404);

        $pase = $pases->emitir($persona);

        return response()->json(['data' => [
            'codigo' => $pase['codigo'],
            'vence_en' => $pase['vence_en']->toIso8601String(),
            'nombre' => $persona->nombreCompleto(),
        ]]);
    }

    /**
     * Las clases a las que puede entrar, por PERIODO (fechas locales de cada sede,
     * fin incluido) y, si la elige, por sucursal: el calendario pide lo que ve y lo
     * vuelve a pedir al moverse, así ninguna semana se ve vacía porque las primeras
     * N clases del negocio eran de otras fechas o sedes. La sucursal se filtra en la
     * consulta (antes del tope); si aun así se llena el tope, `meta.truncado` lo dice.
     * Sin fechas: desde hoy y 30 días (lo que ve la lista).
     *
     * Cada clase dice si su plan la cubre (`cobertura`: incluida, solo con membresía,
     * no incluida y por qué, o de pago por clase), con la misma regla que al
     * reservar: la persona lo ve antes de intentarlo.
     */
    public function agenda(Request $request, ResolverDerechoTenant $resolver): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $zona = app(FechasNegocioTenant::class)->zona();
        $desde = CarbonImmutable::parse($validado['desde'] ?? CarbonImmutable::now($zona)->toDateString(), 'UTC');
        $hasta = isset($validado['hasta']) ? CarbonImmutable::parse($validado['hasta'], 'UTC') : $desde->addDays(30);
        if ($desde->diffInDays($hasta) > self::DIAS_AGENDA) {
            throw ValidationException::withMessages([
                'hasta' => 'El periodo puede abarcar hasta '.self::DIAS_AGENDA.' días.',
            ]);
        }

        $sucursalId = null;
        if (($validado['sucursal_id'] ?? '') !== '') {
            $sucursalId = (int) (SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->value('id') ?? 0);
        }

        // Un día de holgura por lado (las horas se guardan en UTC y cada sede tiene
        // su zona); luego se queda solo lo que cae en el periodo en la fecha LOCAL.
        $inicio = $desde->subDay()->startOfDay();
        $ahora = CarbonImmutable::now();
        // Solo clases abiertas, y solo en un negocio de clases: en uno de citas cada
        // cita es privada de su titular y no hay nada que listar (ADR 0104).
        $sesiones = ! $this->modalidad->esClases() ? new Collection : SesionTenant::query()
            ->where('estado', 'programada')
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('inicia_en', '>=', $inicio->greaterThan($ahora) ? $inicio : $ahora)
            ->where('inicia_en', '<', $hasta->addDays(2)->startOfDay())
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->with(['oferta.actividad', 'sucursal', 'instructor'])
            // Cupo ocupado = reservas que toman lugar (confirmadas, ofrecidas y
            // pendientes de pago, que retienen el cupo mientras se pagan) y cuántos esperan.
            ->withCount(SesionTenantPresenter::conteos())
            ->orderBy('inicia_en')
            ->limit(self::LIMITE_AGENDA + 1)
            ->get();
        $truncado = $sesiones->count() > self::LIMITE_AGENDA;
        // Lo que otros canales apartan, para todo el lote: los libres son los del motor.
        app(CupoDeSesionTenant::class)->cargar($sesiones);

        $enPeriodo = $sesiones->take(self::LIMITE_AGENDA)->filter(function (SesionTenant $s) use ($desde, $hasta): bool {
            $dia = $s->inicia_en->copy()->setTimezone((string) $s->zona_horaria)->toDateString();

            return $dia >= $desde->toDateString() && $dia <= $hasta->toDateString();
        });

        // Qué le cubre su plan (sin perfil de miembro, no se dice).
        $persona = $this->persona($request);
        $cobertura = $persona instanceof PersonaTenant
            ? $resolver->coberturaDeSesiones($persona, $enPeriodo, ReservasTenant::UNIDADES_POR_SESION)
            : [];

        return response()->json([
            'data' => $enPeriodo->map(fn (SesionTenant $s): array => [
                'id' => $s->ulid,
                'oferta' => $s->oferta?->nombre,
                // Para filtrar por clase, actividad e instructor.
                'oferta_id' => $s->oferta?->ulid,
                'actividad' => $s->oferta?->actividad?->nombre,
                'actividad_id' => $s->oferta?->actividad?->ulid,
                'sucursal' => $s->sucursal?->nombre,
                'sucursal_id' => $s->sucursal?->ulid,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'termina_en' => $s->termina_en->toIso8601String(),
                'instructor' => $s->instructor?->name,
                'instructor_id' => $s->instructor?->ulid,
                'zona_horaria' => $s->zona_horaria,
                'capacidad' => $s->capacidad,
                'ocupados' => CupoDeSesionTenant::ocupadosCargados($s),
                'cobertura' => $this->coberturaDe($s, $cobertura),
                // Contrato de la agenda (ADR 0104): aquí siempre son clases.
                'tipo' => $s->tipo->value,
                'clase' => SesionTenantPresenter::clase($s),
                'cita' => null,
                'ocupacion' => SesionTenantPresenter::ocupacion($s),
            ])->values()->all(),
            'meta' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'truncado' => $truncado,
                // Para elegir sede en el calendario (solo si hay más de una).
                'sucursales' => SucursalTenant::query()->orderBy('nombre')->get()
                    ->map(fn (SucursalTenant $x): array => ['id' => $x->ulid, 'nombre' => $x->nombre])->all(),
            ],
        ]);
    }

    /**
     * Cómo entra a esa clase: de pago por clase (con su precio) o con su plan. La
     * política de la oferta solo dice cómo se habilita la reserva, nunca si es cita.
     *
     * @param  array<int, array{estado: string, motivo: string|null}>  $cobertura
     * @return array{estado: string, motivo: string|null, precio_minor?: int, moneda?: string}|null
     */
    private function coberturaDe(SesionTenant $sesion, array $cobertura): ?array
    {
        if ($sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago) {
            return ['estado' => 'de_pago', 'motivo' => null, 'precio_minor' => (int) $sesion->oferta->precio_clase_minor, 'moneda' => app(ParametrosTenant::class)->monedaDe($sesion->sucursal)];
        }

        return $cobertura[(int) $sesion->getKey()] ?? null;
    }

    /**
     * Antes de reservar una clase (M05): si queda reservado, en lista de espera o no se
     * puede (motivo y acción), el plan que usa y lo que consume, y hasta cuándo cancela
     * sin costo. Misma regla que reservar; no aparta nada.
     */
    public function antesDeReservar(Request $request, AntesDeReservarTenant $antes): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->with(['oferta', 'sucursal'])->firstOrFail();
        if ($sesion->esCita()) {
            throw new SesionNoReservable('Esta cita es privada.');
        }

        return response()->json(['data' => $antes->deClase($sesion, $persona)]);
    }

    public function reservar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $validado = $request->validate([
            'sesion_id' => ['required', 'string'],
            'esperar' => ['boolean'],
            // El lugar numerado que eligió (B06); el motor lo valida bajo el candado.
            'lugar' => ['nullable', 'integer', 'min:1'],
        ]);

        $sesion = SesionTenant::query()->where('ulid', $validado['sesion_id'])->with('oferta')->firstOrFail();

        // Aquí solo se reservan clases abiertas. En un negocio de citas cada cita es de
        // su titular: nadie más puede reservarla ni esperar su lugar (ADR 0104).
        if (! $this->modalidad->esClases() || $sesion->esCita()) {
            throw new SesionNoReservable('Esta cita es privada.');
        }
        // Cuándo abre y cierra la reserva de la clase para el cliente (lo decide el negocio).
        app(VentanaDeReservaTenant::class)->exigirParaClase($sesion);

        // Cómo se habilita la reserva de la clase: si es de pago por clase, se crea una
        // reserva pendiente (retiene el cupo) + una orden por la sesión, y el miembro
        // paga esa orden (checkout con las pasarelas) para CONFIRMAR. Si no, con su plan.
        if ($sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago) {
            $monto = (int) ($sesion->oferta->precio_clase_minor ?? 0);
            abort_if($monto <= 0, 422, 'Esta clase requiere pago pero no tiene precio configurado.');
            $reserva = $this->reservas->reservarConPago(
                $sesion,
                $persona,
                $monto,
                app(ParametrosTenant::class)->monedaDe($sesion->sucursal),
                (int) $sesion->sucursal_id,
            );

            return response()->json(['data' => $this->presentarReserva($reserva->load(['sesion.oferta', 'orden']))], 201);
        }

        $reserva = $this->reservas->crear(
            $sesion,
            $persona,
            null,
            (bool) ($validado['esperar'] ?? false),
            null,
            CanalReserva::Directo->value,
            isset($validado['lugar']) ? (int) $validado['lugar'] : null,
        );

        return response()->json(['data' => $this->presentarReserva($reserva->load('sesion.oferta'))], 201);
    }

    /**
     * Los lugares numerados de una clase (B06): cuántos hay, cuáles están tomados (sin
     * decir por quién) y el suyo, si tiene. Null en `data` si la clase no los numera.
     */
    public function lugares(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $lugares = $this->reservas->lugaresDeSesion($sesion);
        if ($lugares === null) {
            return response()->json(['data' => null]);
        }
        $mio = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', CupoDeSesionTenant::OCUPAN_LUGAR)
            ->value('lugar');

        return response()->json(['data' => [...$lugares, 'mio' => $mio === null ? null : (int) $mio]]);
    }

    /**
     * Elige o cambia el lugar de su reserva (B06): por ejemplo, quien llegó desde la
     * lista de espera. Si ya lo tomó otra persona, 422 SPOT_UNAVAILABLE.
     */
    public function elegirLugar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);
        $validado = $request->validate(['lugar' => ['required', 'integer', 'min:1']]);

        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        $actor = $request->attributes->get('usuario_tenant');
        $reserva = $this->reservas->cambiarLugar($reserva, (int) $validado['lugar'], $actor instanceof Usuario ? $actor : null);

        return response()->json(['data' => $this->presentarReserva($reserva->load('sesion.oferta'))]);
    }

    /**
     * Agenda una CITA desde un hueco de disponibilidad (F-08): elige servicio +
     * proveedor + hora y crea la sesión + la reserva (pago-para-reservar o membresía).
     * El miembro paga la `orden_id` devuelta (si es de pago) para confirmar.
     */
    /**
     * Servicios, sedes y profesionales para agendar una cita desde la cuenta (también
     * en negocios que no están en el directorio), con los que puede tomar con su bono
     * o membresía (ADR 0091).
     */
    public function opcionesCita(Request $request, OpcionesCitaTenant $opciones): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return response()->json(['data' => $opciones->listar($persona)]);
    }

    /**
     * Días en que se puede agendar en la sede (desde hoy), para el calendario de la
     * cuenta y la app: los que ya pasaron, en que nadie atiende o que el negocio
     * cerró van como no disponibles (ADR 0065). Con `instructor_id`, los de esa
     * persona. También en negocios que no están en el directorio.
     */
    public function diasCita(Request $request, CalcularDisponibilidadTenant $disponibilidad): JsonResponse
    {
        abort_unless($this->persona($request) instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:62'],
            'instructor_id' => ['nullable', 'string'],
        ]);
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = ($validado['instructor_id'] ?? '') !== '' ? Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail() : null;

        return response()->json(['data' => $disponibilidad->paraCliente()->diasConAtencion(
            $sucursal,
            $validado['desde'],
            (int) ($validado['dias'] ?? 14),
            $instructor instanceof Usuario ? (int) $instructor->getKey() : null,
        )]);
    }

    /**
     * Horarios libres de un profesional en una fecha, para elegir la hora de la cita.
     * Sin profesional («cualquier profesional disponible»), los de todo el equipo de
     * la sede.
     */
    public function disponibilidadCita(Request $request, CalcularDisponibilidadTenant $disponibilidad): JsonResponse
    {
        abort_unless($this->persona($request) instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $validado = $request->validate([
            'instructor_id' => ['nullable', 'string'],
            'sucursal_id' => ['required', 'string'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            // Con el servicio, su duración y sus márgenes (2.3); sin él, la duración.
            'oferta_id' => ['nullable', 'string'],
            'duracion_minutos' => ['required_without:oferta_id', 'nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $instructor = ($validado['instructor_id'] ?? '') !== '' ? Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail() : null;
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $oferta = ($validado['oferta_id'] ?? '') !== '' ? OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail() : null;
        // Quien agenda por su cuenta no decide cuánto dura: la del servicio o, sin ella, la
        // del negocio (`citas.duracion_defecto`); no la que mande la pantalla.
        [$duracion, $margenes] = $disponibilidad->duracionYMargenes($oferta, null);

        return response()->json(['data' => [
            'fecha' => $validado['fecha'],
            // Cada horario trae `inicia_local` en esta zona (la de la sede): la app lo
            // muestra y lo manda tal cual, aunque el teléfono esté en otra zona.
            'zona_horaria' => $disponibilidad->zona($sucursal),
            'slots' => $instructor instanceof Usuario
                ? $disponibilidad->paraCliente()->paraFecha((int) $instructor->getKey(), $sucursal, $validado['fecha'], $duracion, null, $margenes, $oferta)
                : $disponibilidad->paraCliente()->paraCualquiera($sucursal, $validado['fecha'], $duracion, null, $margenes, $oferta),
        ]]);
    }

    public function agendarCita(Request $request, AgendarCitaTenant $agendar, WhatsAppTenant $whatsapp): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $validado = $request->validate([
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:1440'],
            // Nota para el negocio (ADR 0067) y, si es para otra persona, quién asiste
            // (ADR 0068).
            'nota' => ['nullable', 'string', 'max:500'],
            'asiste' => ['nullable', 'string', 'max:120'],
            // Pidió los avisos por WhatsApp al agendar (ADR 0069); sin marcar no cambia nada.
            'acepta_whatsapp' => ['boolean'],
        ]);
        if (($validado['acepta_whatsapp'] ?? false) && $whatsapp->enUso()) {
            $whatsapp->aceptar($persona, true);
        }

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        // Sin profesional («cualquier profesional disponible»), se asigna uno libre.
        $instructor = ($validado['instructor_id'] ?? '') !== '' ? Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail() : null;
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();

        $reserva = $instructor instanceof Usuario
            ? $agendar->agendar($oferta, $sucursal, $persona, (int) $instructor->getKey(), $inicia, 0)
            : $agendar->agendarConCualquiera($oferta, $sucursal, $persona, $inicia, 0);
        $cambios = array_filter([
            'nota_cliente' => trim((string) ($validado['nota'] ?? '')),
            'asiste' => trim((string) ($validado['asiste'] ?? '')),
        ], static fn (string $v): bool => $v !== '');
        if ($cambios !== []) {
            $reserva->update($cambios);
        }
        $reserva->load(['sesion.oferta', 'sesion.instructor', 'orden']);
        $profesional = $reserva->sesion?->instructor;

        return response()->json(['data' => [
            ...$this->presentarReserva($reserva),
            // Quién atenderá: el elegido o el que se asignó.
            'profesional' => $profesional instanceof Usuario ? ['id' => $profesional->ulid, 'nombre' => (string) $profesional->name] : null,
        ]], 201);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);

        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        $usuario = $request->attributes->get('usuario_tenant');
        $this->reservas->cancelar($reserva, QuienCancela::Cliente, $usuario instanceof Usuario ? $usuario : null);

        return response()->json(['data' => $this->presentarReserva($reserva->refresh()->load('sesion.oferta'))]);
    }

    /**
     * Vista previa de cancelar una reserva propia: qué pasará con su crédito.
     */
    public function previsualizarCancelacion(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403);

        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        return response()->json(['data' => $this->reservas->efectoDeCancelar($reserva, QuienCancela::Cliente)->toArray()]);
    }

    /**
     * El clima de su Inicio: el pronóstico para su próxima clase o cita en esa
     * sucursal o, si no tiene, el de ahora (por su IP). Null si no se pudo saber:
     * el Inicio no depende de un servicio externo.
     */
    public function clima(Request $request, ClimaTenant $clima): JsonResponse
    {
        $persona = $this->persona($request);

        return response()->json(['data' => $clima->paraMiembro($persona instanceof PersonaTenant ? $persona : null, $request->ip())]);
    }

    /**
     * Corte de sus planes: qué incluía cada paquete o membresía, en qué clases lo usó,
     * sus clases extra, lo que le queda y lo que venció.
     */
    public function planes(Request $request, CorteDePlanesTenant $corte): JsonResponse
    {
        $persona = $this->persona($request);

        return response()->json(['data' => $persona instanceof PersonaTenant ? $corte->dePersona($persona) : []]);
    }

    /**
     * En qué clases usó uno de sus planes, por páginas. Un plan de otra persona no
     * existe para él (404).
     */
    public function usosPlan(Request $request, CorteDePlanesTenant $corte): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 404);
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

    /**
     * Movimientos de créditos de un plan propio: por qué cambió su saldo (1.4). Un plan
     * de otra persona no existe para él (404).
     */
    public function movimientosDerecho(Request $request, PresentarMovimientosCreditoTenant $movimientos): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 404);

        $derecho = DerechoTenant::query()
            ->where('ulid', (string) $request->route('derecho'))
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->firstOrFail();

        // Con `page`, por páginas y con su total; sin ella, los últimos 200 (versiones
        // anteriores de la app).
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $consulta = MovimientoCreditoTenant::query()->where('derecho_id', $derecho->getKey())->orderByDesc('id');
        $pagina = isset($validado['page'])
            ? $consulta->paginate((int) ($validado['per_page'] ?? 30), ['*'], 'page', (int) $validado['page'])
            : null;
        $lista = $pagina !== null ? collect($pagina->items()) : $consulta->limit(self::LIMITE_MOVIMIENTOS)->get();

        return response()->json([
            'data' => $movimientos->presentar($lista),
            'saldo' => $derecho->ilimitado ? null : $this->libro->saldo($derecho),
            'disponible' => $derecho->ilimitado ? null : $this->libro->disponible($derecho),
            ...($pagina !== null ? ['meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ]] : []),
        ]);
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
    /**
     * Por quién actúa la cuenta: ella misma o, con `X-Participante`, una persona a su
     * cargo con la autorización vigente (B05, {@see CuentaEnUsoTenant}).
     */
    private function persona(Request $request): ?PersonaTenant
    {
        return $this->cuenta->persona($request);
    }

    /**
     * Sus órdenes: las que compró y, si la cuenta actúa por una persona a su cargo
     * (B05), también las que se compraron para ella.
     *
     * @return Builder<OrdenTenant>
     */
    private function ordenesDe(Request $request, PersonaTenant $persona): Builder
    {
        $paraOtro = $this->cuenta->tutela($request) !== null;

        return OrdenTenant::query()->where(fn (Builder $q) => $q
            ->where('persona_id', $persona->getKey())
            ->when($paraOtro, fn (Builder $q) => $q->orWhereHas('lineas', fn (Builder $l) => $l->where('beneficiario_id', $persona->getKey()))));
    }

    /**
     * Su reserva con el contrato de la agenda (ADR 0104): los campos de siempre más
     * `tipo`, `clase` (o null), `cita` (o null) y `ocupacion` (o null). Ver docs/API.md.
     *
     * @return array<string, mixed>
     */
    private function presentarReserva(ReservaTenant $reserva): array
    {
        $sesion = $reserva->sesion;

        return [
            'id' => $reserva->ulid,
            // `sesion_id` + sucursal identifican la clase exacta: dos clases iguales en
            // distinta sucursal ya no se confunden (antes se relacionaban por oferta+hora).
            'sesion_id' => $reserva->sesion?->ulid,
            // Clase o cita: la pantalla la nombra por lo que es ("Tu próxima cita"),
            // no por el término general del negocio.
            'tipo' => $reserva->sesion?->tipo->value,
            'estado' => $reserva->estado->value,
            'oferta' => $reserva->sesion?->oferta?->nombre,
            'sucursal' => $reserva->sesion?->sucursal?->nombre,
            // Cómo llegar a la sucursal (ADR 0091).
            'mapa_url' => $reserva->sesion?->sucursal?->enlaceMapa(),
            'inicia_en' => $reserva->sesion?->inicia_en->toIso8601String(),
            // Fin y profesional: para verla en su calendario y agregarla al del teléfono.
            'termina_en' => $reserva->sesion?->termina_en->toIso8601String(),
            'instructor' => $reserva->sesion?->instructor?->name,
            // Si la agendó para otra persona: quién asiste (ADR 0068).
            'asiste' => $reserva->asiste,
            'zona_horaria' => $reserva->sesion?->zona_horaria,
            // Vencimiento de la oferta de lista de espera (si la reserva está ofrecida).
            'oferta_expira_en' => $reserva->oferta_expira_en?->toIso8601String(),
            // Lo que consume y de qué plan; hasta cuándo cancela sin costo, con la política
            // que se congeló al reservar (M05).
            'plan' => $reserva->derecho?->acuerdo?->producto?->nombre,
            'unidades' => (int) $reserva->unidades,
            'cancelacion' => $this->cancelacionDeReserva($reserva),
            // Para llegar preparado (ADR 0118).
            'que_llevar' => $reserva->sesion?->oferta?->que_llevar,
            // Su lugar numerado y cuántos tiene la clase (0 = no numera; B06).
            'lugar' => $reserva->lugar === null ? null : (int) $reserva->lugar,
            'lugares' => (int) ($reserva->sesion?->oferta->lugares ?? 0),
            // Orden a pagar para confirmar (cita o clase de pago por clase), si aplica.
            'orden_id' => $reserva->orden?->ulid,
            // Lo de su clase (cupo, lista de espera, pago por clase); null si es cita.
            'clase' => $sesion instanceof SesionTenant ? SesionTenantPresenter::clase($sesion) : null,
            // Su cita y en qué va (atención y pago, calculados aquí); null si es clase.
            'cita' => $sesion instanceof SesionTenant && $sesion->esCita() ? $this->citaDeReserva($reserva, $sesion) : null,
            // La ocupación de su clase que se muestra (0–100); null en citas.
            'ocupacion' => $sesion instanceof SesionTenant ? SesionTenantPresenter::ocupacion($sesion) : null,
        ];
    }

    /**
     * Hasta cuándo cancela sin costo y qué pasa después, con la política congelada en la
     * reserva (R8; las anteriores al snapshot usan el parámetro). Null en lista de espera
     * (no consume) o sin clase.
     *
     * @return array{limite: string, horas_limite: int, penaliza_tarde: bool}|null
     */
    private function cancelacionDeReserva(ReservaTenant $reserva): ?array
    {
        $sesion = $reserva->sesion;
        if (! $sesion instanceof SesionTenant || $reserva->estado === EstadoReserva::EnEspera) {
            return null;
        }
        $horas = (int) ($reserva->horas_limite ?? app(ParametrosTenant::class)->entero('cancelacion.horas_limite'));

        return [
            'limite' => $sesion->inicia_en->copy()->subHours($horas)->toIso8601String(),
            'horas_limite' => $horas,
            'penaliza_tarde' => (bool) ($reserva->penaliza_tarde ?? true),
        ];
    }

    /**
     * La política de la plataforma cuando el negocio no configuró una (ADR 0042).
     *
     * @return array{horas_limite: int, penaliza_tarde: bool, penaliza_no_show: bool}
     */
    private function politicaPorDefecto(): array
    {
        $politica = PoliticaCancelacion::porDefecto(app(ParametrosTenant::class));

        return [
            'horas_limite' => $politica->horasLimite,
            'penaliza_tarde' => $politica->penalizaTarde,
            'penaliza_no_show' => $politica->penalizaNoShow,
        ];
    }

    /**
     * Su cita: el estado de su reserva, si ya llegó, la orden y en qué va.
     *
     * @return array<string, mixed>
     */
    private function citaDeReserva(ReservaTenant $reserva, SesionTenant $sesion): array
    {
        return [
            'reserva_id' => $reserva->ulid,
            'estado' => $reserva->estado->value,
            // Llegó (presente) / no asistió (ausente); null = aún sin marcar.
            'asistencia' => $reserva->asistencia?->estado->value,
            'orden_id' => $reserva->orden?->ulid,
            // Lo que pidió que supieran al agendar y, si es para otra persona, quién asiste.
            'nota' => $reserva->nota_cliente,
            'asiste' => $reserva->asiste,
            ...SesionTenantPresenter::estadoCita($sesion, $reserva, CarbonImmutable::now()),
        ];
    }

    /**
     * Catálogo de productos que el alumno puede comprar desde su portal. Vacío si el
     * plan del negocio no incluye la venta en línea (ADR 0107).
     */
    public function productos(Request $request): JsonResponse
    {
        if (! app(FuncionesPlan::class)->tiene($this->estudioDe($request), 'venta_en_linea')) {
            return response()->json(['data' => []]);
        }
        // En citas, sin membresías (ADR 0130).
        $productos = app(MembresiasPorModalidadTenant::class)
            ->vendibles(ProductoTenant::query()->with('actividad')->where('archivado', false))
            ->orderBy('precio_minor')->get();
        // La clase de prueba solo se ofrece a quien nunca vino (B03).
        $persona = $this->persona($request);
        $primeraVez = $persona instanceof PersonaTenant && app(PrimeraVezTenant::class)->elegible($persona);
        // Las fechas exactas antes de pagar (la vigencia corre desde el pago; M03).
        $hoy = app(FechasNegocioTenant::class)->hoy();
        // La inscripción que se sumaría al comprar un plan que la cobra (B04).
        $inscripcion = $persona instanceof PersonaTenant && app(CuotasTenant::class)->debeInscripcion($persona)
            ? ConfiguracionCuotasTenant::actual()->inscripcion_minor
            : 0;

        return response()->json([
            'data' => $productos->map(static fn (ProductoTenant $p): array => [
                'id' => $p->ulid,
                'nombre' => $p->nombre,
                'tipo' => $p->tipo->value,
                'precio_minor' => $p->precio_minor,
                'moneda' => $p->moneda,
                'ilimitado' => $p->ilimitado,
                'creditos_incluidos' => $p->creditos_incluidos,
                ...$p->coberturaSucursales(),
                // Cuánto dura lo que se compra (p. ej. 1 mes, o hasta fin de mes).
                'vigencia_tipo' => $p->vigencia_tipo?->value,
                'vigencia_cantidad' => $p->vigencia_cantidad,
                'vence_si_compra_hoy' => VigenciaProducto::de($p)?->hasta($hoy),
                'descripcion' => $p->descripcion,
                'destacado' => $p->destacado,
                'primera_vez' => $p->primera_vez,
                'actividad' => $p->actividad?->nombre,
                // ¿Lo puede comprar? (la clase de prueba, solo la primera vez).
                'disponible' => ! $p->primera_vez || $primeraVez,
                // Lo que se suma de inscripción si lo compra hoy (0 = nada).
                'inscripcion_minor' => $p->cobra_inscripcion ? $inscripcion : 0,
                // Permanencia (B04): las condiciones que acepta al comprarlo.
                'permanencia_meses' => $p->permanencia_meses,
                'condiciones' => CondicionesDePlan::texto($p),
            ])->all(),
        ]);
    }

    /**
     * Historial de compras del alumno (sus órdenes), paginado. Con
     * `excluir_pendientes`, solo lo pagado, cancelado o devuelto: lo que debe se pide
     * aparte (`ordenesPendientes`), completo.
     */
    public function ordenes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => [], 'meta' => ['page' => 1, 'ultima_pagina' => 1, 'total' => 0, 'per_page' => 50]]);
        }
        $filtros = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'excluir_pendientes' => ['sometimes', 'boolean'],
        ]);

        $consulta = $this->ordenesDe($request, $persona)
            ->when($request->boolean('excluir_pendientes'), fn ($q) => $q->where('estado', '!=', EstadoOrden::Pendiente->value))
            ->orderByDesc('id');
        $porPagina = (int) ($filtros['per_page'] ?? 50);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);
        $ordenes = $consulta->with(['lineas.producto', 'lineas.beneficiario', 'sesion.oferta', 'sesion.instructor', 'sesion.sucursal'])
            ->forPage($pagina, $porPagina)->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenTenant $o): array => $this->presentarOrden($o))->all(),
            'meta' => ['page' => $pagina, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $porPagina],
        ]);
    }

    /**
     * Todo lo que tiene por pagar, sin tope: no sale de la primera página del
     * historial, así un adeudo antiguo no deja de verse.
     */
    public function ordenesPendientes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => []]);
        }

        $ordenes = $this->ordenesDe($request, $persona)
            ->where('estado', EstadoOrden::Pendiente->value)
            ->with(['lineas.producto', 'lineas.beneficiario', 'sesion.oferta', 'sesion.instructor', 'sesion.sucursal'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenTenant $o): array => $this->presentarOrden($o))->all(),
        ]);
    }

    /**
     * El alumno compra para SÍ MISMO: crea una orden pendiente (precio congelado). El
     * fulfillment (créditos) ocurre al pagarla en línea (webhook) o en el estudio.
     */
    public function comprar(Request $request, AtribucionTenant $atribucion): JsonResponse
    {
        $persona = $this->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $validado = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'codigo_promo' => ['nullable', 'string', 'max:64'],
            // Acepta las condiciones de un plan con permanencia (B04).
            'acepta_condiciones' => ['sometimes', 'boolean'],
            // De dónde llegó (ADR 0142): se guarda con la orden.
            ...Atribucion::reglas(),
            ...IdentificadoresNavegador::reglas(),
        ]);

        // Para una persona a su cargo (B05): paga y acepta el responsable; los planes, sus
        // créditos y sus reservas son del participante.
        $titular = $this->cuenta->titular($request) ?? $persona;
        $paraOtro = ! $titular->is($persona);

        $items = [];
        foreach ($validado['items'] as $item) {
            $producto = ProductoTenant::query()->where('ulid', $item['producto_id'])->firstOrFail();
            // Para sí: beneficiario = comprador (sin beneficiario explícito).
            $items[] = ['producto' => $producto, 'cantidad' => (int) $item['cantidad'], 'beneficiario' => $paraOtro ? $persona : null];
        }

        // Sucursal (R19): la compra se atribuye a la sede de casa de quien la usa.
        $sede = $persona->sucursal_id ?? $titular->sucursal_id;
        $sucursalId = $sede !== null ? (int) $sede : null;
        $actor = $request->attributes->get('usuario_tenant');
        $orden = $this->ordenes->crear(
            $titular,
            $items,
            $validado['codigo_promo'] ?? null,
            $sucursalId,
            aceptacion: ($validado['acepta_condiciones'] ?? false) === true
                ? ['via' => 'cliente', 'usuario' => $actor instanceof Usuario ? $actor : null, 'ip' => $request->ip()]
                : null,
        );

        $contexto = $atribucion->registrar($validado['atribucion'] ?? null, $titular, $validado['medicion'] ?? null, $request->ip(), $request->userAgent());
        if ($contexto !== null) {
            $orden->update(['contexto_medicion_id' => $contexto->getKey()]);
        }

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
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        abort_unless($this->ordenesDe($request, $persona)->whereKey($orden->getKey())->exists(), 403, 'Esta orden no es tuya.');

        $validado = $request->validate([
            'proveedor' => ['nullable', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'domiciliar' => ['sometimes', 'boolean'],
        ]);
        // Sin proveedor, la pasarela en línea con la que cobra el estudio.
        $validado['proveedor'] = ($validado['proveedor'] ?? '') !== '' ? $validado['proveedor'] : $this->pasarelas->enLinea();
        if ($validado['proveedor'] === null) {
            throw ValidationException::withMessages([
                'proveedor' => ['Este negocio todavía no cobra en línea; paga en el negocio.'],
            ]);
        }

        // El autoservicio solo admite pasarelas en línea (no ventanilla/manual/efectivo).
        if (in_array($validado['proveedor'], ['manual', 'efectivo'], true)) {
            throw ValidationException::withMessages([
                'proveedor' => ['El pago en efectivo o ventanilla se registra en el negocio.'],
            ]);
        }

        // Pago automático: al pagar se autoriza la tarjeta para cobrar sola la membresía
        // cada periodo. Solo para lo que se renueva y con una pasarela que lo admite.
        // Por una persona a cargo, el responsable la paga con su tarjeta: es el comprador
        // y la pasarela la guarda a su nombre (ADR 0129).
        $domiciliar = $request->boolean('domiciliar');
        // En citas no hay nada que se renueve (ADR 0130).
        if ($domiciliar && ($this->domiciliaciones->proveedor() !== $validado['proveedor'] || ! self::seRenueva($orden)
            || app(MembresiasPorModalidadTenant::class)->enCitas())) {
            throw ValidationException::withMessages([
                'domiciliar' => ['Esta compra no admite pago automático.'],
            ]);
        }
        if ($orden->estado === EstadoOrden::Pendiente && $orden->domiciliar !== $domiciliar) {
            $orden->update(['domiciliar' => $domiciliar]);
        }

        $metodo = isset($validado['metodo']) ? MetodoPago::from($validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? $validado['idempotency_key'] : null;

        // El propio alumno paga en línea: queda como quien registró el cobro.
        $alumno = $request->attributes->get('usuario_tenant');
        $pago = $this->cobrarOrden->ejecutar($orden, $validado['proveedor'], $metodo, $key, '/mi-cuenta', $alumno instanceof Usuario ? $alumno : null);

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
        $orden->loadMissing(['lineas.producto', 'lineas.beneficiario', 'sesion.oferta', 'sesion.instructor', 'sesion.sucursal']);
        $sesion = $orden->sesion;
        $productos = $orden->lineas->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->values();

        return [
            'id' => $orden->ulid,
            // Qué se pagó, en una línea: sus productos o, si es una cita, el servicio.
            'concepto' => $productos->isNotEmpty() ? $productos->implode(', ') : $sesion?->oferta?->nombre,
            // Si es el pago de una cita o clase: cuál, con quién, cuándo y dónde.
            'sesion' => $sesion instanceof SesionTenant ? [
                'tipo' => $sesion->tipo->value,
                'servicio' => $sesion->oferta?->nombre,
                'profesional' => $sesion->instructor?->name,
                'inicia_en' => $sesion->inicia_en->toIso8601String(),
                'zona_horaria' => $sesion->zona_horaria,
                'sucursal' => $sesion->sucursal?->nombre,
            ] : null,
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
            'descuento_minor' => (int) ($orden->descuento_minor ?? 0),
            'moneda' => $orden->moneda,
            'metodo_pago' => $orden->metodo_pago,
            'fecha' => $orden->created_at?->toIso8601String(),
            'pagada_en' => $orden->pagada_en?->toIso8601String(),
            // Se renueva (membresía o su renovación): se puede pagar con pago automático.
            'recurrente' => self::seRenueva($orden),
            // Ya inició un pago en línea que espera confirmación (p. ej. OXXO): se dice
            // para no pagar dos veces (M07).
            'pago_en_proceso' => $orden->estado === EstadoOrden::Pendiente ? self::pagoEnProceso($orden) : null,
            'lineas' => $orden->lineas->map(static fn (LineaOrdenTenant $l): array => [
                'producto' => $l->nombre(),
                // Una cuota (inscripción, anualidad, penalización; B04) no es un producto.
                'concepto' => $l->concepto?->value,
                'cantidad' => $l->cantidad,
                'subtotal_minor' => $l->subtotal_minor,
                // Para quién es, si no es para quien compró (una persona a su cargo; B05).
                'para' => $l->beneficiario_id !== null && (int) $l->beneficiario_id !== (int) $orden->persona_id
                    ? $l->beneficiario?->nombreCompleto()
                    : null,
            ])->all(),
        ];
    }

    /**
     * El intento de pago en línea que sigue abierto, si lo hay: con qué medio y desde
     * cuándo.
     *
     * @return array{metodo: string|null, iniciado_en: string|null}|null
     */
    private static function pagoEnProceso(OrdenTenant $orden): ?array
    {
        $pago = PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('estado', EstadoPago::Pendiente->value)
            ->whereNotIn('proveedor', ['manual', 'efectivo', 'ventanilla'])
            ->latest('id')
            ->first();

        return $pago instanceof PagoTenant
            ? ['metodo' => $pago->metodo?->value, 'iniciado_en' => $pago->created_at?->toIso8601String()]
            : null;
    }

    /**
     * ¿La orden es de algo que se renueva (una membresía o su renovación)?
     */
    private static function seRenueva(OrdenTenant $orden): bool
    {
        if ($orden->renueva_acuerdo_id !== null) {
            return true;
        }
        $orden->loadMissing('lineas.producto');

        return $orden->lineas->contains(
            static fn (LineaOrdenTenant $l): bool => ($l->producto->politica_reset ?? PoliticaReset::Ninguno) !== PoliticaReset::Ninguno,
        );
    }

    private function estudioDe(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
