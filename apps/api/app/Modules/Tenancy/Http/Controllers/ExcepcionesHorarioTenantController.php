<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CancelarFechasTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Excepciones de horario del estudio (R5): fechas (feriados/cierres) en las que la
 * generacion recurrente NO crea sesiones. Idempotente por fecha y sede. Opera SIEMPRE
 * sobre la BD del estudio resuelto.
 *
 * Cada día es de una sede o de todo el negocio (ADR 0230). Crear o quitar uno de todo el
 * negocio es solo de quien ve todas las sedes; uno de una sede, de quien puede operarla.
 */
class ExcepcionesHorarioTenantController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly AccesoSesionTenant $accesoSesion,
    ) {}

    /**
     * Los días cerrados, en orden. Con `desde` (AAAA-MM-DD), solo de ese día en
     * adelante: los pasados se juntan año con año y la pantalla no los usa. Quien está
     * acotado a algunas sedes ve los de sus sedes y los de todo el negocio.
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d']]);
        $actor = $this->actor($request);
        $sedes = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
        $excepciones = ExcepcionHorarioTenant::query()
            ->with('sucursal')
            ->when($validado['desde'] ?? null, fn ($q, string $desde) => $q->whereDate('fecha', '>=', $desde))
            ->when($sedes !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('sucursal_id')->orWhereIn('sucursal_id', $sedes)))
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $excepciones->map(fn (ExcepcionHorarioTenant $e): array => [
                ...$this->presentar($e),
                // Si quien pregunta lo puede quitar (lo de todo el negocio no, si está acotado).
                'editable' => $this->puedeCambiar($actor, $e->sucursal_id !== null ? (int) $e->sucursal_id : null),
            ])->all(),
        ]);
    }

    /**
     * Antes de cerrar un día (ADR 0223): cuántas clases o citas programadas tiene y
     * cuántas personas con lugar, de esa sede o de todo el negocio.
     */
    public function afectadas(Request $request, CancelarFechasTenant $cancelar): JsonResponse
    {
        $validado = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'sucursal_id' => ['nullable', 'string'],
        ]);
        $sede = $this->sede($validado['sucursal_id'] ?? null);
        $this->exigirAlcance($request, $sede);

        return response()->json(['data' => $cancelar->resumen($cancelar->delDia((string) $validado['fecha'], $sede))]);
    }

    /**
     * Cierra un día (en una sede o en todo el negocio): ya no se generan fechas ahí. Con
     * `cancelar_sesiones`, también se cancelan las que ya estaban programadas en lo que
     * se cierra (cada una devuelve créditos y avisa).
     */
    public function crear(Request $request, EliminacionesTenant $eliminaciones, CancelarFechasTenant $cancelar): JsonResponse
    {
        $validado = $request->validate([
            'fecha' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'cancelar_sesiones' => ['sometimes', 'boolean'],
            'sucursal_id' => ['nullable', 'string'],
        ]);
        $sede = $this->sede($validado['sucursal_id'] ?? null);
        $this->exigirAlcance($request, $sede);

        // Un día cerrado por fecha y sede (la columna guarda también la hora: se compara el
        // día). Si ya existe se actualiza; si estaba eliminado, se restaura.
        $fecha = Carbon::parse((string) $validado['fecha'])->toDateString();
        $excepcion = ExcepcionHorarioTenant::withTrashed()
            ->whereDate('fecha', $fecha)
            ->when($sede !== null, static fn ($q) => $q->where('sucursal_id', $sede), static fn ($q) => $q->whereNull('sucursal_id'))
            ->first();
        if ($excepcion instanceof ExcepcionHorarioTenant) {
            $excepcion->update(['motivo' => $validado['motivo'] ?? null]);
            if ($excepcion->trashed()) {
                $excepcion->restore();
                $eliminaciones->restaurado($excepcion, 'excepcion_horario', $this->paraBitacora($excepcion));
            }
        } else {
            $excepcion = ExcepcionHorarioTenant::query()->create(['fecha' => $fecha, 'motivo' => $validado['motivo'] ?? null, 'sucursal_id' => $sede]);
        }

        $cancelacion = null;
        if ((bool) ($validado['cancelar_sesiones'] ?? false)) {
            $cancelacion = $cancelar->cancelar($cancelar->delDia($fecha, $sede), $this->actor($request));
        }

        return response()->json(['data' => $this->presentar($excepcion), 'meta' => ['cancelacion' => $cancelacion]], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $excepcion = ExcepcionHorarioTenant::query()->where('ulid', (string) $request->route('excepcion'))->firstOrFail();
        $this->exigirAlcance($request, $excepcion->sucursal_id !== null ? (int) $excepcion->sucursal_id : null);
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($excepcion, 'excepcion_horario', $this->paraBitacora($excepcion));

        return response()->json(status: 204);
    }

    /**
     * @return array{id: string, fecha: string, motivo: string|null, sucursal_id: string|null, sucursal: string|null}
     */
    private function presentar(ExcepcionHorarioTenant $excepcion): array
    {
        return [
            'id' => (string) $excepcion->ulid,
            'fecha' => $excepcion->fecha->toDateString(),
            'motivo' => $excepcion->motivo,
            // Sin sede: es de todo el negocio.
            'sucursal_id' => $excepcion->sucursal?->ulid,
            'sucursal' => $excepcion->sucursal?->nombre,
        ];
    }

    /**
     * Lo que queda en la bitácora: qué día y de qué sede (por su nombre).
     *
     * @return array<string, mixed>
     */
    private function paraBitacora(ExcepcionHorarioTenant $excepcion): array
    {
        return $excepcion->only(['fecha', 'motivo']) + ['sucursal' => $excepcion->sucursal?->nombre];
    }

    /** La sede pedida, por su ULID; sin ella, todo el negocio. */
    private function sede(?string $ulid): ?int
    {
        return $ulid === null || $ulid === '' ? null : (int) SucursalTenant::query()->where('ulid', $ulid)->firstOrFail()->getKey();
    }

    private function exigirAlcance(Request $request, ?int $sede): void
    {
        abort_unless($this->puedeCambiar($this->actor($request), $sede), 403, $sede === null
            ? 'Un día de todo el negocio lo cierra quien ve todas las sucursales: elige una de las tuyas.'
            : 'Esa sucursal no es de las tuyas.');
    }

    /**
     * Lo de una sede, quien puede operarla; lo de todo el negocio, quien ve todas las sedes
     * (y no está acotado a sus propias clases).
     */
    private function puedeCambiar(?Usuario $actor, ?int $sede): bool
    {
        if (! $actor instanceof Usuario) {
            return false;
        }
        if ($sede !== null) {
            return $this->accesoSesion->puedeOperarEn($sede, null, $actor);
        }

        return $this->acceso->sucursalesPermitidas($actor) === null && ! $this->accesoSesion->esInstructorAcotado($actor);
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
