<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Asistencia\EstadoAsistencia;
use App\Modules\Reservas\CanalReserva;
use App\Modules\Reservas\EstadoReserva;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reservas (booking) del estudio, tenant-local: roster de una sesion (confirmadas +
 * lista de espera), crear (motor de booking concurrency-safe) y cancelar (con
 * politica por hold + promocion de lista de espera). Opera SIEMPRE sobre la BD del
 * estudio resuelto.
 */
class ReservasTenantController
{
    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly AccesoSesionTenant $acceso,
        private readonly WaiversTenant $waivers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        // Un instructor solo ve el roster de SUS sesiones asignadas.
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($this->acceso->puedeOperar($sesion, $usuario instanceof Usuario ? $usuario : null), 403);

        $reservas = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            // Las pendientes de pago también ocupan lugar: recepción debe verlas para cobrar.
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            ->with(['persona', 'sesion', 'asistencia'])
            ->orderBy('id')
            ->get();

        // Señales 360° del roster (recepción actúa sin salir del panel), en bloque para
        // evitar N+1: quién ya asistió (primera vez), quién tiene adeudo (dunning) y
        // cuántos documentos/waivers debe cada quién.
        $personaIds = $reservas->pluck('persona_id')->filter()->unique()->map(fn ($v): int => (int) $v)->all();
        $yaAsistieron = $this->personasQueAsistieron($personaIds);
        $conAdeudo = $this->personasConAdeudo($personaIds);
        $docsPendientes = $this->documentosPendientesPorPersona($personaIds);

        return response()->json([
            'data' => $reservas->map(fn (ReservaTenant $reserva): array => $this->presentar($reserva, $yaAsistieron, $conAdeudo, $docsPendientes))->all(),
        ]);
    }

    /**
     * De un conjunto de personas, cuáles tienen un proceso de dunning abierto (adeudo).
     *
     * @param  list<int>  $personaIds
     * @return list<int>
     */
    private function personasConAdeudo(array $personaIds): array
    {
        if ($personaIds === []) {
            return [];
        }

        return ProcesoDunningTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'procesos_dunning.acuerdo_id')
            ->whereIn('procesos_dunning.estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
            ->whereIn('acuerdos.persona_id', $personaIds)
            ->distinct()
            ->pluck('acuerdos.persona_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Documentos/waivers vigentes pendientes por persona (total vigentes − aceptados).
     *
     * @param  list<int>  $personaIds
     * @return array<int, int>
     */
    private function documentosPendientesPorPersona(array $personaIds): array
    {
        $vigentes = $this->waivers->vigentes()->pluck('id')->map(fn ($v): int => (int) $v)->all();
        if ($personaIds === [] || $vigentes === []) {
            return [];
        }

        $aceptados = AceptacionWaiverTenant::query()
            ->whereIn('persona_id', $personaIds)
            ->whereIn('waiver_id', $vigentes)
            ->get(['persona_id', 'waiver_id'])
            ->groupBy('persona_id')
            ->map(fn ($grupo): int => $grupo->pluck('waiver_id')->unique()->count());

        $total = count($vigentes);
        $mapa = [];
        foreach ($personaIds as $pid) {
            $mapa[$pid] = $total - (int) ($aceptados[$pid] ?? 0);
        }

        return $mapa;
    }

    /**
     * De un conjunto de personas, cuáles tienen al menos una asistencia `presente` (R14).
     *
     * @param  list<int>  $personaIds
     * @return list<int>
     */
    private function personasQueAsistieron(array $personaIds): array
    {
        if ($personaIds === []) {
            return [];
        }

        return ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->whereIn('reservas.persona_id', $personaIds)
            ->distinct()
            ->pluck('reservas.persona_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    public function reservar(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'esperar' => ['boolean'],
            'canal' => ['nullable', Rule::enum(CanalReserva::class)],
            'lugar' => ['nullable', 'integer', 'min:1'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        $idempotencyKey = $validado['idempotency_key'] ?? null;

        $reserva = $this->reservas->crear(
            $sesion,
            $persona,
            is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
            (bool) ($validado['esperar'] ?? false),
            null,
            $validado['canal'] ?? CanalReserva::Directo->value,
            isset($validado['lugar']) ? (int) $validado['lugar'] : null,
        );

        return response()->json(['data' => $this->presentar($reserva)], 201);
    }

    /**
     * Evalua una reserva SIN crearla y devuelve la decision estructurada del motor
     * (permitida, reason_code, reglas evaluadas, costo en creditos, derecho a usar,
     * advertencias). Util para mostrar al cliente por que puede/no puede reservar.
     */
    public function preview(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'esperar' => ['boolean'],
            'canal' => ['nullable', Rule::enum(CanalReserva::class)],
            'lugar' => ['nullable', 'integer', 'min:1'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();

        $decision = $this->reservas->evaluar(
            $sesion,
            $persona,
            null,
            (bool) ($validado['esperar'] ?? false),
            $validado['canal'] ?? CanalReserva::Directo->value,
            isset($validado['lugar']) ? (int) $validado['lugar'] : null,
        );

        return response()->json(['data' => $decision->aArreglo()]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();

        $this->reservas->cancelar($reserva);

        return response()->json(['data' => $this->presentar($reserva->refresh())]);
    }

    public function aceptar(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();

        $this->reservas->aceptar($reserva);

        return response()->json(['data' => $this->presentar($reserva->refresh())]);
    }

    /**
     * Smart-fill (R32): promueve la lista de espera de una sesion — ofrece de golpe los
     * cupos libres al inicio de la fila (FIFO). Devuelve cuantas ofertas se emitieron.
     */
    public function promover(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        $ofrecidas = $this->reservas->promoverCupos($sesion);

        return response()->json(['data' => ['ofrecidas' => $ofrecidas]]);
    }

    /**
     * Transfiere (regala) el lugar de una reserva a otra persona (R9).
     */
    public function transferir(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        $validado = $request->validate(['persona_id' => ['required', 'string']]);
        $destino = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();

        $this->reservas->transferir($reserva, $destino);

        return response()->json(['data' => $this->presentar($reserva->refresh())]);
    }

    /**
     * @param  list<int>|null  $yaAsistieron  personas con asistencia previa (roster); null = calcular por persona
     * @param  list<int>  $conAdeudo  personas con dunning abierto (roster 360°)
     * @param  array<int, int>  $docsPendientes  documentos pendientes por persona (roster 360°)
     * @return array<string, mixed>
     */
    private function presentar(ReservaTenant $reserva, ?array $yaAsistieron = null, array $conAdeudo = [], array $docsPendientes = []): array
    {
        $reserva->loadMissing(['persona', 'sesion', 'asistencia']);
        $persona = $reserva->persona;

        $personaId = (int) $reserva->persona_id;
        $primeraVez = $yaAsistieron !== null
            ? ! in_array($personaId, $yaAsistieron, true)
            : $this->personasQueAsistieron([$personaId]) === [];

        return [
            'id' => $reserva->ulid,
            'estado' => $reserva->estado->value,
            'canal' => $reserva->canal,
            'lugar' => $reserva->lugar,
            'persona_id' => $persona?->ulid,
            'persona' => $persona?->nombreCompleto(),
            'primera_vez' => $primeraVez,
            // Señales 360° para la recepción (solo pobladas en el roster).
            'adeudo' => in_array($personaId, $conAdeudo, true),
            'documentos_pendientes' => (int) ($docsPendientes[$personaId] ?? 0),
            'inicia_en' => $reserva->sesion?->inicia_en->toIso8601String(),
            'unidades' => $reserva->unidades,
            'asistencia' => $reserva->asistencia?->estado->value,
        ];
    }
}
