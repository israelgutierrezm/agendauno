<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Front desk (R13): vista de un DIA en una sucursal con las sesiones y sus metricas
 * (cupo, confirmadas, ofrecidas, en espera, presentes, ausentes) mas los totales del
 * dia (ocupacion, no-shows). Base para la operacion de recepcion. Opera SIEMPRE sobre
 * la BD del estudio resuelto.
 */
class FrontDeskTenantController
{
    public function __construct(
        private readonly AccesoSesionTenant $acceso,
        private readonly ResolverAccesoTenant $resolver,
    ) {}

    public function dia(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'fecha' => ['required', 'date'],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $sucursal = null;
        if (($validado['sucursal_id'] ?? '') !== '') {
            $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
            $zona = (string) $sucursal->zona_horaria;
        } else {
            // "Todas": interpreta el día en la zona de una sucursal del estudio (no UTC),
            // para no dejar fuera las clases de la tarde cuya hora en UTC cae al día siguiente.
            $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        }

        // El dia se interpreta en la zona de la sucursal (o del sistema) y se acota en UTC.
        $inicioDia = CarbonImmutable::parse($validado['fecha'].' 00:00:00', $zona)->utc();
        $finDia = $inicioDia->addDay();

        $usuario = $request->attributes->get('usuario_tenant');
        $usuario = $usuario instanceof Usuario ? $usuario : null;
        // Alcance por sucursal (R19): el staff acotado solo ve el día de SUS sedes.
        $permitidas = $usuario !== null ? $this->resolver->sucursalesPermitidas($usuario) : null;

        $sesiones = SesionTenant::query()
            ->with(['oferta', 'sucursal', 'instructor'])
            ->where('inicia_en', '>=', $inicioDia)
            ->where('inicia_en', '<', $finDia)
            ->when($sucursal !== null, fn ($q) => $q->where('sucursal_id', $sucursal->getKey()))
            // Un instructor solo ve sus sesiones asignadas.
            ->when($this->acceso->esInstructorAcotado($usuario), fn ($q) => $q->where('instructor_id', $usuario?->getKey()))
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->orderBy('inicia_en')
            ->get();

        /** @var Collection<int, Collection<int, ReservaTenant>> $reservasPorSesion */
        $reservasPorSesion = ReservaTenant::query()
            ->whereIn('sesion_id', $sesiones->pluck('id'))
            ->with('asistencia')
            ->get()
            ->groupBy('sesion_id');

        $filas = $sesiones->map(function (SesionTenant $sesion) use ($reservasPorSesion): array {
            $reservas = $reservasPorSesion->get($sesion->getKey()) ?? collect();

            return $this->metricasSesion($sesion, $reservas);
        });

        return response()->json([
            'fecha' => $inicioDia->setTimezone($zona)->toDateString(),
            'sucursal' => $sucursal?->nombre,
            'metricas' => $this->totales($filas),
            'sesiones' => $filas->all(),
        ]);
    }

    /**
     * @param  Collection<int, ReservaTenant>  $reservas
     * @return array<string, mixed>
     */
    private function metricasSesion(SesionTenant $sesion, Collection $reservas): array
    {
        $porEstado = fn (EstadoReserva $estado): int => $reservas->where('estado', $estado)->count();
        $asistencia = fn (EstadoAsistencia $estado): int => $reservas->filter(
            fn (ReservaTenant $r): bool => $r->asistencia?->estado === $estado,
        )->count();

        return [
            'id' => $sesion->ulid,
            'oferta' => $sesion->oferta?->nombre,
            // Para mover a un alumno a otra fecha de la misma clase (2.1).
            'oferta_id' => $sesion->oferta?->ulid,
            'sucursal' => $sesion->sucursal?->nombre,
            'instructor' => $sesion->instructor?->name,
            'inicia_en' => $sesion->inicia_en->toIso8601String(),
            'zona_horaria' => $sesion->zona_horaria,
            'estado' => $sesion->estado->value,
            'tipo' => $sesion->tipo->value,
            'capacidad' => $sesion->capacidad,
            'confirmadas' => $porEstado(EstadoReserva::Confirmada),
            'pendientes_pago' => $porEstado(EstadoReserva::PendientePago),
            'ofrecidas' => $porEstado(EstadoReserva::Ofrecida),
            'en_espera' => $porEstado(EstadoReserva::EnEspera),
            'presentes' => $asistencia(EstadoAsistencia::Presente),
            'ausentes' => $asistencia(EstadoAsistencia::Ausente),
        ];
    }

    /**
     * Los totales del día cuentan solo lo que se va a dar: una clase cancelada sigue
     * en la lista (con su estado), pero no suma sesiones, cupo ni ocupación.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function totales(Collection $filas): array
    {
        $canceladas = $filas->filter(fn (array $f): bool => $f['estado'] === EstadoSesionTenant::Cancelada->value)->count();
        $filas = $filas->reject(fn (array $f): bool => $f['estado'] === EstadoSesionTenant::Cancelada->value);
        $capacidad = (int) $filas->sum(fn (array $f): int => (int) ($f['capacidad'] ?? 0));
        $confirmadas = (int) $filas->sum('confirmadas');

        return [
            'sesiones' => $filas->count(),
            'canceladas' => $canceladas,
            'capacidad_total' => $capacidad,
            'confirmadas' => $confirmadas,
            'en_espera' => (int) $filas->sum('en_espera'),
            'presentes' => (int) $filas->sum('presentes'),
            'ausentes' => (int) $filas->sum('ausentes'),
            'ocupacion_pct' => $capacidad > 0 ? (int) round($confirmadas / $capacidad * 100) : null,
        ];
    }
}
