<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historial de clases y citas del miembro (lo que ya pasó, más lo que canceló o se
 * le venció aunque fuera para después): si asistió, faltó, canceló (él o el negocio)
 * o se quedó en lista de espera, si cambió de horario, su reseña o si aún puede
 * calificarla, y lo necesario para volver a reservar lo mismo. Paginado y con
 * filtro de fechas del calendario del negocio.
 */
class MiHistorialTenantController
{
    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->attributes->get('usuario_tenant');
        $persona = $usuario instanceof Usuario ? $this->personas->buscar($usuario) : null;
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');

        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        // Las fechas del filtro son del calendario del negocio (su zona).
        $zona = app(FechasNegocioTenant::class)->zona();
        $ahora = CarbonImmutable::now();

        $consulta = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('sesion', function ($q) use ($filtros, $zona): void {
                if (isset($filtros['desde'])) {
                    $q->where('inicia_en', '>=', CarbonImmutable::parse($filtros['desde'], $zona)->startOfDay()->utc());
                }
                if (isset($filtros['hasta'])) {
                    $q->where('inicia_en', '<', CarbonImmutable::parse($filtros['hasta'], $zona)->addDay()->startOfDay()->utc());
                }
            })
            // Lo que ya pasó, y lo cancelado o vencido aunque fuera para después.
            ->where(fn ($q) => $q
                ->whereIn('estado', [EstadoReserva::Cancelada->value, EstadoReserva::Expirada->value])
                ->orWhereHas('sesion', fn ($s) => $s->where('inicia_en', '<', $ahora)))
            ->with(['sesion.oferta', 'sesion.sucursal', 'sesion.instructor', 'asistencia'])
            ->orderByDesc(SesionTenant::query()->select('inicia_en')->whereColumn('sesiones.id', 'reservas.sesion_id'))
            ->orderByDesc('id');

        $porPagina = (int) ($filtros['per_page'] ?? 10);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);
        $reservas = $consulta->forPage($pagina, $porPagina)->get();

        $ids = $reservas->map(fn (ReservaTenant $r): int => (int) $r->getKey())->all();
        $resenas = ResenaTenant::query()->whereIn('reserva_id', $ids)->get()->keyBy('reserva_id');
        $reprogramadas = AuditoriaTenant::query()
            ->where('accion', 'reserva.reprogramada')
            ->whereIn('entidad_id', $reservas->map(fn (ReservaTenant $r): string => (string) $r->ulid)->all())
            ->pluck('entidad_id')->flip();
        $calificarDesde = $ahora->subDays($this->parametros->entero('resenas.dias_para_calificar'));

        return response()->json([
            'data' => $reservas->map(function (ReservaTenant $r) use ($resenas, $reprogramadas, $calificarDesde): array {
                $sesion = $r->sesion;
                $resena = $resenas->get($r->getKey());
                $asistio = $r->asistencia?->estado === EstadoAsistencia::Presente;

                return [
                    'id' => $r->ulid,
                    'sesion_id' => $sesion?->ulid,
                    'tipo' => $sesion?->tipo->value,
                    'oferta' => $sesion?->oferta?->nombre,
                    // Para volver a reservar lo mismo (misma clase o servicio).
                    'oferta_id' => $sesion?->oferta?->ulid,
                    'sucursal' => $sesion?->sucursal?->nombre,
                    'sucursal_id' => $sesion?->sucursal?->ulid,
                    'instructor' => $sesion?->instructor?->name,
                    'instructor_id' => $sesion?->instructor?->ulid,
                    'inicia_en' => $sesion?->inicia_en->toIso8601String(),
                    'zona_horaria' => $sesion?->zona_horaria,
                    'estado' => $this->estado($r),
                    // Asistió, pero llegó tarde.
                    'retardo' => (bool) $r->asistencia?->retardo,
                    'cancelada_por' => $r->estado === EstadoReserva::Cancelada ? $r->cancelada_por : null,
                    'cancelada_en' => $r->cancelada_en?->toIso8601String(),
                    'reprogramada' => $reprogramadas->has((string) $r->ulid),
                    'resena' => $resena instanceof ResenaTenant
                        ? ['calificacion' => (int) $resena->calificacion, 'comentario' => $resena->comentario]
                        : null,
                    // Asistió, aún está a tiempo y no la ha calificado.
                    'calificable' => $asistio && ! $resena instanceof ResenaTenant
                        && $sesion !== null && $sesion->inicia_en->greaterThanOrEqualTo($calificarDesde),
                ];
            })->values()->all(),
            'meta' => ['page' => $pagina, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $porPagina],
        ]);
    }

    /**
     * Qué pasó con la reserva, en palabras del miembro: asistió, faltó, la canceló
     * (o el negocio), se venció la oferta de lugar, se quedó en lista de espera o
     * quedó sin pagar; sin pase de lista, «tomada».
     */
    private function estado(ReservaTenant $reserva): string
    {
        return match (true) {
            $reserva->estado === EstadoReserva::Cancelada => 'cancelada',
            $reserva->estado === EstadoReserva::Expirada => 'expirada',
            $reserva->estado === EstadoReserva::EnEspera => 'sin_lugar',
            $reserva->estado === EstadoReserva::PendientePago => 'sin_pagar',
            $reserva->asistencia?->estado === EstadoAsistencia::Presente => 'asistio',
            $reserva->asistencia?->estado === EstadoAsistencia::Ausente => 'no_asistio',
            default => 'tomada',
        };
    }
}
