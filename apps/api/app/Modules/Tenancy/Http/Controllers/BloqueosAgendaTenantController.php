<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueos de agenda (fase 2, punto 2.2): comida, vacaciones o ausencia de un
 * profesional; cierre de una sede; mantenimiento de una sala. Por horas
 * (`desde_local`/`hasta_local`) o por días completos (`fecha_desde`/`fecha_hasta`),
 * en la zona de la sede (o la del negocio, para un profesional).
 *
 * Un bloqueo quita ese intervalo del autoservicio y de nuevas reservas internas
 * (el verificador de agenda lo respeta), pero NO cancela lo que ya estaba agendado:
 * antes de crearlo, la vista previa dice qué sesiones afecta, y al crearlo se
 * devuelven para que alguien las atienda.
 */
class BloqueosAgendaTenantController
{
    private const LIMITE = 500;

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $bloqueos = BloqueoAgendaTenant::query()
            ->when(isset($validado['desde']), fn ($q) => $q->where('hasta', '>', CarbonImmutable::parse((string) $validado['desde'])->subDay()))
            ->when(isset($validado['hasta']), fn ($q) => $q->where('desde', '<', CarbonImmutable::parse((string) $validado['hasta'])->addDays(2)))
            ->with(['instructor', 'sucursal', 'recurso', 'autor'])
            ->orderBy('desde')
            ->limit(self::LIMITE)
            ->get();

        return response()->json(['data' => $bloqueos->map(fn (BloqueoAgendaTenant $b): array => $this->presentar($b))->all()]);
    }

    /**
     * Qué sesiones programadas caen en el bloqueo propuesto (no se guarda nada).
     */
    public function previsualizar(Request $request): JsonResponse
    {
        $propuesto = $this->propuesto($request);

        return response()->json(['data' => ['afectadas' => $this->afectadas($propuesto)]]);
    }

    public function crear(Request $request): JsonResponse
    {
        $propuesto = $this->propuesto($request);
        $actor = $request->attributes->get('usuario_tenant');
        $propuesto->creado_por = $actor instanceof Usuario ? (int) $actor->getKey() : null;
        $propuesto->save();

        $this->auditoria->registrar(
            $actor instanceof Usuario ? $actor : null,
            'bloqueo_agenda.creado',
            'bloqueo_agenda',
            (string) $propuesto->ulid,
            null,
            $propuesto->only(['instructor_id', 'sucursal_id', 'recurso_id', 'motivo']) + [
                'desde' => $propuesto->desde->toIso8601String(),
                'hasta' => $propuesto->hasta->toIso8601String(),
            ],
        );

        $propuesto->load(['instructor', 'sucursal', 'recurso', 'autor']);

        return response()->json([
            'data' => $this->presentar($propuesto),
            // Lo que ya estaba agendado en ese intervalo sigue en pie: hay que atenderlo.
            'afectadas' => $this->afectadas($propuesto),
        ], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $bloqueo = BloqueoAgendaTenant::query()->where('ulid', (string) $request->route('bloqueo'))->firstOrFail();
        $eliminaciones->eliminar($bloqueo, 'bloqueo_agenda', $bloqueo->only(['motivo']) + [
            'desde' => $bloqueo->desde->toIso8601String(),
            'hasta' => $bloqueo->hasta->toIso8601String(),
        ]);

        return response()->json(null, 204);
    }

    /**
     * El bloqueo pedido, validado y con sus horas en UTC (sin guardar).
     */
    private function propuesto(Request $request): BloqueoAgendaTenant
    {
        $validado = $request->validate([
            'instructor_id' => ['nullable', 'string'],
            'sucursal_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'desde_local' => ['required_without:fecha_desde', 'nullable', 'date'],
            'hasta_local' => ['required_with:desde_local', 'nullable', 'date', 'after:desde_local'],
            'fecha_desde' => ['required_without:desde_local', 'nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $ambitos = array_filter([
            'instructor_id' => $validado['instructor_id'] ?? null,
            'sucursal_id' => $validado['sucursal_id'] ?? null,
            'recurso_id' => $validado['recurso_id'] ?? null,
        ], static fn ($v): bool => is_string($v) && $v !== '');
        if (count($ambitos) !== 1) {
            throw ValidationException::withMessages(['instructor_id' => ['Elige a quién o qué bloquear: una persona, una sede o una sala.']]);
        }

        $bloqueo = new BloqueoAgendaTenant(['motivo' => $validado['motivo']]);
        $zona = $this->zonaDelNegocio($request);
        if (isset($ambitos['instructor_id'])) {
            $bloqueo->instructor_id = (int) Usuario::query()->where('ulid', $ambitos['instructor_id'])->firstOrFail()->getKey();
        } elseif (isset($ambitos['sucursal_id'])) {
            $sucursal = SucursalTenant::query()->where('ulid', $ambitos['sucursal_id'])->firstOrFail();
            $bloqueo->sucursal_id = (int) $sucursal->getKey();
            $zona = (string) ($sucursal->zona_horaria ?: $zona);
        } else {
            $recurso = RecursoTenant::query()->where('ulid', $ambitos['recurso_id'])->firstOrFail();
            $bloqueo->recurso_id = (int) $recurso->getKey();
            $zona = (string) ($recurso->sucursal->zona_horaria ?? $zona);
        }

        if (($validado['fecha_desde'] ?? null) !== null) {
            // Días completos, de las 00:00 del primero a las 00:00 del siguiente al último.
            $bloqueo->todo_el_dia = true;
            $bloqueo->desde = Carbon::parse((string) $validado['fecha_desde'], $zona)->startOfDay()->utc();
            $bloqueo->hasta = Carbon::parse((string) ($validado['fecha_hasta'] ?? $validado['fecha_desde']), $zona)->addDay()->startOfDay()->utc();
        } else {
            $bloqueo->todo_el_dia = false;
            $bloqueo->desde = Carbon::parse((string) $validado['desde_local'], $zona)->utc();
            $bloqueo->hasta = Carbon::parse((string) $validado['hasta_local'], $zona)->utc();
        }
        $bloqueo->zona_horaria = $zona;

        return $bloqueo;
    }

    /**
     * Sesiones programadas del profesional, la sede o la sala que se cruzan con el
     * bloqueo, con cuántas personas tienen reservado.
     *
     * @return list<array{sesion: string, oferta: string|null, inicia_en: string, zona_horaria: string|null, reservas: int}>
     */
    private function afectadas(BloqueoAgendaTenant $bloqueo): array
    {
        return SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('ocupa_desde', '<', $bloqueo->hasta)
            ->where('ocupa_hasta', '>', $bloqueo->desde)
            ->when($bloqueo->instructor_id !== null, fn (Builder $q) => $q->where('instructor_id', $bloqueo->instructor_id))
            ->when($bloqueo->sucursal_id !== null, fn (Builder $q) => $q->where('sucursal_id', $bloqueo->sucursal_id))
            ->when($bloqueo->recurso_id !== null, fn (Builder $q) => $q->where('recurso_id', $bloqueo->recurso_id))
            ->withCount(['reservas' => fn (Builder $q) => $q->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value,
            ])])
            ->with('oferta')
            ->orderBy('inicia_en')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (SesionTenant $s): array => [
                'sesion' => (string) $s->ulid,
                'oferta' => $s->oferta?->nombre,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'zona_horaria' => $s->zona_horaria,
                'reservas' => (int) $s->getAttribute('reservas_count'),
            ])
            ->values()
            ->all();
    }

    private function zonaDelNegocio(Request $request): string
    {
        $estudio = $request->attributes->get('estudio');

        return $estudio instanceof Estudio && $estudio->zona_horaria !== ''
            ? $estudio->zona_horaria
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(BloqueoAgendaTenant $bloqueo): array
    {
        return [
            'id' => $bloqueo->ulid,
            'ambito' => $bloqueo->instructor_id !== null ? 'profesional' : ($bloqueo->sucursal_id !== null ? 'sede' : 'sala'),
            'instructor_id' => $bloqueo->instructor?->ulid,
            'sucursal_id' => $bloqueo->sucursal?->ulid,
            'recurso_id' => $bloqueo->recurso?->ulid,
            'nombre' => $bloqueo->instructor->name ?? $bloqueo->sucursal->nombre ?? $bloqueo->recurso->nombre ?? null,
            'desde' => $bloqueo->desde->toIso8601String(),
            'hasta' => $bloqueo->hasta->toIso8601String(),
            'todo_el_dia' => $bloqueo->todo_el_dia,
            'zona_horaria' => $bloqueo->zona_horaria,
            'motivo' => $bloqueo->motivo,
            'creado_por' => $bloqueo->autor?->name,
        ];
    }
}
