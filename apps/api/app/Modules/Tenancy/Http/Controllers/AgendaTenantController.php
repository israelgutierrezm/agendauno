<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\VerificarAgendaTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agenda del estudio, tenant-local. Materializa una oferta en una sucursal a una
 * hora concreta; convierte la hora local (en la zona de la sucursal) a UTC y
 * conserva el snapshot de zona. Opera sobre la BD del estudio resuelto.
 */
class AgendaTenantController
{
    private const LIMITE = 200;

    /**
     * Estados de reserva que ocupan un lugar en la sesión.
     */
    private const OCUPAN_LUGAR = [
        EstadoReserva::Confirmada->value,
        EstadoReserva::Ofrecida->value,
        EstadoReserva::PendientePago->value,
    ];

    public function __construct(
        private readonly AccesoSesionTenant $acceso,
        private readonly ReservasTenant $reservas,
        private readonly VerificarAgendaTenant $agenda,
        private readonly ResolverAccesoTenant $resolver,
    ) {}

    /**
     * Alcance por sucursal (R19) sobre una consulta de sesiones: el staff ACOTADO a
     * sedes solo ve las clases de SUS sucursales (los demás, todas).
     *
     * @param  Builder<SesionTenant>  $consulta
     */
    private function scopeSucursal(Builder $consulta, ?Usuario $usuario): void
    {
        if (! $usuario instanceof Usuario) {
            return;
        }
        $permitidas = $this->resolver->sucursalesPermitidas($usuario);
        if ($permitidas !== null) {
            $consulta->whereIn('sucursal_id', $permitidas);
        }
    }

    public function crearSesion(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'capacidad' => ['nullable', 'integer', 'min:1'],
        ]);

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        // Hora local (zona de la sucursal) → UTC; snapshot de zona en la sesión.
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();
        $termina = $inicia->addMinutes((int) $validado['duracion_minutos']);

        $instructorId = $this->resolverInstructor($validado['instructor_id'] ?? null);
        $recurso = ($validado['recurso_id'] ?? '') !== ''
            ? RecursoTenant::query()->where('ulid', $validado['recurso_id'])->firstOrFail()
            : null;

        // Se revalida al GUARDAR, bajo candado del profesional y del recurso: dos
        // solicitudes simultáneas no pueden encimarse.
        $sesion = DB::connection('tenant')->transaction(function () use ($oferta, $sucursal, $instructorId, $recurso, $inicia, $termina, $validado): SesionTenant {
            $this->agenda->bloquear($instructorId, $recurso);
            $this->agenda->exigirSinConflictos($instructorId, $recurso, $inicia, $termina, null, (int) $sucursal->getKey());

            return SesionTenant::query()->create([
                'oferta_id' => $oferta->id,
                'sucursal_id' => $sucursal->id,
                'instructor_id' => $instructorId,
                'recurso_id' => $recurso?->getKey(),
                'inicia_en' => $inicia,
                'termina_en' => $termina,
                'zona_horaria' => $sucursal->zona_horaria,
                'capacidad' => $validado['capacidad'] ?? $oferta->capacidad,
                'estado' => EstadoSesionTenant::Programada->value,
            ]);
        });

        return response()->json(['data' => $this->presentar($sesion->load(['oferta', 'instructor', 'recurso']))], 201);
    }

    /**
     * El NEGOCIO agenda una cita (recepción, teléfono, mostrador) para un cliente: crea
     * la sesión privada con el profesional a esa hora y su reserva CONFIRMADA — en un
     * servicio de pago queda la orden por cobrar en caja; con membresía, consume su
     * crédito. El hueco debe estar libre (el profesional no atiende dos a la vez).
     */
    public function agendarCita(Request $request, AgendarCitaTenant $agendar): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['required', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail();

        // Alcance por sucursal (R19): el staff acotado solo agenda en sus sedes.
        $usuario = $request->attributes->get('usuario_tenant');
        if ($usuario instanceof Usuario) {
            $permitidas = $this->resolver->sucursalesPermitidas($usuario);
            abort_if(
                $permitidas !== null && ! in_array((int) $sucursal->getKey(), $permitidas, true),
                403,
                'No puedes agendar en una sucursal que no te corresponde.',
            );
        }

        $duracion = (int) ($validado['duracion_minutos'] ?? $oferta->duracion_minutos ?? 30);
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();

        $reserva = $agendar->agendar($oferta, $sucursal, $persona, (int) $instructor->getKey(), $inicia, $duracion, porNegocio: true);

        $sesion = SesionTenant::query()->whereKey($reserva->sesion_id)->with(['oferta', 'instructor', 'recurso'])->firstOrFail();
        $reserva->load(['persona', 'asistencia', 'orden']);

        return response()->json(['data' => $this->presentar($sesion, [(int) $sesion->getKey() => $reserva])], 201);
    }

    /**
     * Verifica (sin guardar) los conflictos de una sesión propuesta, para avisar en el
     * formulario antes de crear. Devuelve la lista de conflictos (vacía = sin choques).
     */
    public function verificar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'sesion_id' => ['nullable', 'string'],
        ]);

        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();
        $termina = $inicia->addMinutes((int) $validado['duracion_minutos']);

        $recurso = ($validado['recurso_id'] ?? '') !== ''
            ? RecursoTenant::query()->where('ulid', $validado['recurso_id'])->first()
            : null;
        $excluir = ($validado['sesion_id'] ?? '') !== ''
            ? SesionTenant::query()->where('ulid', $validado['sesion_id'])->value('id')
            : null;

        $conflictos = $this->agenda->conflictos(
            $this->resolverInstructor($validado['instructor_id'] ?? null),
            $recurso,
            $inicia,
            $termina,
            $excluir !== null ? (int) $excluir : null,
            (int) $sucursal->getKey(),
        );

        return response()->json(['data' => ['conflictos' => $conflictos]]);
    }

    public function asignarInstructor(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $validado = $request->validate(['instructor_id' => ['nullable', 'string']]);

        $instructorId = $this->resolverInstructor($validado['instructor_id'] ?? null);
        // Al reasignar, el nuevo instructor no puede chocar con otra clase (excluye
        // esta), revalidado bajo su candado.
        DB::connection('tenant')->transaction(function () use ($sesion, $instructorId): void {
            $this->agenda->bloquear($instructorId, null);
            $this->agenda->exigirSinConflictos($instructorId, null, $sesion->inicia_en, $sesion->termina_en, (int) $sesion->getKey());
            $sesion->update(['instructor_id' => $instructorId]);
        });

        return response()->json(['data' => $this->presentar($sesion->refresh()->load(['oferta', 'instructor', 'recurso']))]);
    }

    private function resolverInstructor(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $usuario = Usuario::query()->where('ulid', $ulid)->first();

        return $usuario instanceof Usuario ? (int) $usuario->getKey() : null;
    }

    public function sesiones(Request $request): JsonResponse
    {
        $consulta = SesionTenant::query()
            ->with(['oferta', 'sucursal', 'instructor', 'recurso'])
            // Ocupacion = reservas que toman un lugar (confirmadas, ofrecidas y
            // pendientes de pago); mas cuantos esperan (estado "lista de espera").
            ->withCount([
                'reservas as ocupados' => fn ($q) => $q->whereIn('estado', self::OCUPAN_LUGAR),
                'reservas as en_espera' => fn ($q) => $q->where('estado', EstadoReserva::EnEspera->value),
            ])
            ->orderBy('inicia_en');

        // Un instructor solo ve SUS sesiones asignadas.
        $usuario = $request->attributes->get('usuario_tenant');
        $usuario = $usuario instanceof Usuario ? $usuario : null;
        if ($this->acceso->esInstructorAcotado($usuario)) {
            $consulta->where('instructor_id', $usuario?->getKey());
        }
        // Alcance por sucursal (R19): el staff acotado solo ve las clases de sus sedes.
        $this->scopeSucursal($consulta, $usuario);

        if (is_string($request->query('sucursal_id')) && $request->query('sucursal_id') !== '') {
            $sucursal = SucursalTenant::query()->where('ulid', $request->query('sucursal_id'))->first();
            $consulta->where('sucursal_id', $sucursal instanceof SucursalTenant ? $sucursal->getKey() : 0);
        }

        if (is_string($request->query('instructor_id')) && $request->query('instructor_id') !== '') {
            $instructor = Usuario::query()->where('ulid', $request->query('instructor_id'))->first();
            $consulta->where('instructor_id', $instructor instanceof Usuario ? $instructor->getKey() : 0);
        }

        // La ventana se ensancha 1 dia por lado: `desde`/`hasta` llegan como fechas
        // (dia local del estudio) pero `inicia_en` se guarda en UTC. Como el desfase
        // de zona es < 24h, este superconjunto garantiza incluir las clases del borde
        // (p. ej. domingo por la tarde en Mexico = lunes UTC); el frontend agrupa cada
        // sesion por su fecha LOCAL, asi que descarta con precision lo que sobra.
        if (is_string($request->query('desde')) && $request->query('desde') !== '') {
            $consulta->where('inicia_en', '>=', CarbonImmutable::parse((string) $request->query('desde'))->subDay()->utc());
        }
        if (is_string($request->query('hasta')) && $request->query('hasta') !== '') {
            $consulta->where('inicia_en', '<', CarbonImmutable::parse((string) $request->query('hasta'))->addDays(2)->utc());
        }

        $sesiones = $consulta->limit(self::LIMITE)->get();
        $titulares = $this->titularesDeCitas($sesiones);

        return response()->json([
            'data' => $sesiones->map(fn (SesionTenant $sesion): array => $this->presentar($sesion, $titulares))->all(),
        ]);
    }

    /**
     * Titular (reserva activa) de cada CITA de la lista, en una sola consulta: la agenda
     * del staff muestra a quién atiende cada cita y en qué estado va (p. ej. pendiente
     * de pago). Las clases no lo necesitan (su lista está en el roster).
     *
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array<int, ReservaTenant>
     */
    private function titularesDeCitas(Collection $sesiones): array
    {
        $citas = $sesiones->filter(fn (SesionTenant $s): bool => $s->esCita())->pluck('id');
        if ($citas->isEmpty()) {
            return [];
        }

        return ReservaTenant::query()
            ->whereIn('sesion_id', $citas)
            ->whereIn('estado', self::OCUPAN_LUGAR)
            ->with(['persona', 'asistencia', 'orden'])
            ->get()
            ->keyBy(fn (ReservaTenant $r): int => (int) $r->sesion_id)
            ->all();
    }

    /**
     * Smart-fill (R32): clases PROXIMAS con lugares libres (oportunidades de llenado).
     * Devuelve, por sesion, el cupo libre, la ocupacion y cuantas personas esperan
     * (promovibles ya con `promover`). Un instructor acotado solo ve sus sesiones.
     */
    public function oportunidades(Request $request): JsonResponse
    {
        $dias = max(1, min($request->integer('dias', 14), 60));
        $ahora = CarbonImmutable::now();

        $consulta = SesionTenant::query()
            ->with(['oferta.actividad', 'sucursal', 'instructor'])
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->whereNotNull('capacidad')
            // Una cita no es una oportunidad de llenado: es de una sola persona.
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('inicia_en', '>=', $ahora)
            ->where('inicia_en', '<', $ahora->addDays($dias))
            // Cupo ocupado (confirmadas, ofrecidas y pendientes de pago) y cuantos esperan.
            ->withCount([
                'reservas as ocupados' => fn ($q) => $q->whereIn('estado', self::OCUPAN_LUGAR),
                'reservas as en_espera' => fn ($q) => $q->where('estado', EstadoReserva::EnEspera->value),
            ])
            ->orderBy('inicia_en');

        $usuario = $request->attributes->get('usuario_tenant');
        $usuario = $usuario instanceof Usuario ? $usuario : null;
        if ($this->acceso->esInstructorAcotado($usuario)) {
            $consulta->where('instructor_id', $usuario?->getKey());
        }
        // Alcance por sucursal (R19): el staff acotado solo ve oportunidades de sus sedes.
        $this->scopeSucursal($consulta, $usuario);

        if (is_string($request->query('sucursal_id')) && $request->query('sucursal_id') !== '') {
            $sucursal = SucursalTenant::query()->where('ulid', $request->query('sucursal_id'))->first();
            $consulta->where('sucursal_id', $sucursal instanceof SucursalTenant ? $sucursal->getKey() : 0);
        }

        // Solo las que de verdad tienen lugares libres (cupo - ocupados > 0).
        $oportunidades = $consulta->limit(self::LIMITE)->get()
            ->filter(fn (SesionTenant $s): bool => (int) $s->capacidad - (int) $s->getAttribute('ocupados') > 0)
            ->map(fn (SesionTenant $s): array => $this->presentarOportunidad($s))
            ->values()
            ->all();

        return response()->json(['data' => $oportunidades]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        // Cancela la sesion Y sus reservas activas, liberando los holds (el credito
        // retenido vuelve al miembro). Antes solo marcaba la sesion y dejaba holds colgados.
        $this->reservas->cancelarSesion($sesion);

        return response()->json(['data' => $this->presentar($sesion->refresh()->load('oferta'))]);
    }

    /**
     * @param  array<int, ReservaTenant>  $titulares  titular de cada cita, por id de sesión
     * @return array<string, mixed>
     */
    private function presentar(SesionTenant $sesion, array $titulares = []): array
    {
        $titular = $titulares[(int) $sesion->getKey()] ?? null;

        return [
            'id' => $sesion->ulid,
            'tipo' => $sesion->tipo->value,
            'oferta' => $sesion->oferta?->nombre,
            'oferta_id' => $sesion->oferta?->ulid,
            'oferta_lugares' => $sesion->oferta !== null ? $sesion->oferta->lugares : 0,
            'oferta_precio_clase' => $sesion->oferta?->precio_clase_minor,
            'instructor' => $sesion->instructor?->name,
            'instructor_id' => $sesion->instructor?->ulid,
            'sala' => $sesion->recurso?->nombre,
            'recurso_id' => $sesion->recurso?->ulid,
            'inicia_en' => $sesion->inicia_en->toIso8601String(),
            'termina_en' => $sesion->termina_en->toIso8601String(),
            'zona_horaria' => $sesion->zona_horaria,
            'capacidad' => $sesion->capacidad,
            'ocupados' => (int) ($sesion->getAttribute('ocupados') ?? 0),
            'en_espera' => (int) ($sesion->getAttribute('en_espera') ?? 0),
            'estado' => $sesion->estado->value,
            // Solo en citas: a quién se atiende y el estado de su reserva.
            'cita' => $titular instanceof ReservaTenant ? [
                'reserva_id' => $titular->ulid,
                'cliente' => $titular->persona?->nombreCompleto(),
                'estado' => $titular->estado->value,
                // Llegó (presente) / no asistió (ausente); null = aún sin marcar.
                'asistencia' => $titular->asistencia?->estado->value,
                // Orden de la cita (servicio de pago) y si falta cobrarla en caja.
                'orden_id' => $titular->orden?->ulid,
                'por_cobrar' => $titular->orden !== null && $titular->orden->estado === EstadoOrden::Pendiente,
            ] : null,
        ];
    }

    /**
     * Presenta una oportunidad de smart-fill (R32): cupo libre, ocupacion y personas
     * en espera de la sesion.
     *
     * @return array<string, mixed>
     */
    private function presentarOportunidad(SesionTenant $sesion): array
    {
        $capacidad = (int) $sesion->capacidad;
        $ocupados = (int) $sesion->getAttribute('ocupados');
        $enEspera = (int) $sesion->getAttribute('en_espera');

        return [
            'id' => $sesion->ulid,
            'oferta' => $sesion->oferta?->nombre,
            'actividad' => $sesion->oferta?->actividad?->nombre,
            'sucursal' => $sesion->sucursal?->nombre,
            'instructor' => $sesion->instructor?->name,
            'inicia_en' => $sesion->inicia_en->toIso8601String(),
            'zona_horaria' => $sesion->zona_horaria,
            'capacidad' => $capacidad,
            'ocupados' => $ocupados,
            'libres' => max(0, $capacidad - $ocupados),
            'en_espera' => $enEspera,
            'ocupacion_pct' => $capacidad > 0 ? (int) round($ocupados / $capacidad * 100) : null,
        ];
    }
}
