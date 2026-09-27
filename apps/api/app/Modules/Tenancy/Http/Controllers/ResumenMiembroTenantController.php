<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\ResumenMembresiasTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumen operativo de un miembro para la Recepción (P0): estado de membresía
 * (vigencia), saldo disponible, adeudo (dunning), próxima reserva y una lista de
 * ALERTAS accionables (adeudo / membresía vencida o por vencer / sin acceso). Todo
 * derivado de los datos del tenant; sin cambios de esquema.
 */
class ResumenMiembroTenantController
{
    public function __construct(
        private readonly WaiversTenant $waivers,
        private readonly ResolverAccesoTenant $acceso,
        private readonly ResumenMembresiasTenant $membresias,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Su historial se consulta aunque esté dada de baja.
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        // Alcance por sucursal (R19): un acotado no ve el resumen de un alumno de otra sede.
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless(
            ! $actor instanceof Usuario || $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );

        $ahora = CarbonImmutable::now();
        // Membresía o paquete: la misma regla que las tarjetas del listado.
        $membresia = $this->membresias->deVarias([(int) $persona->getKey()])[(int) $persona->getKey()];
        $estado = $membresia['estado'];
        $saldo = $membresia['saldo_unidades'];

        $adeudo = ProcesoDunningTenant::query()
            ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->exists();

        // Próxima reserva confirmada (la más cercana en el futuro).
        $proxima = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('reservas.persona_id', $persona->getKey())
            ->where('reservas.estado', EstadoReserva::Confirmada->value)
            ->where('sesiones.estado', EstadoSesionTenant::Programada->value)
            ->where('sesiones.inicia_en', '>=', $ahora)
            ->orderBy('sesiones.inicia_en')
            ->select('reservas.*')
            ->with('sesion.oferta')
            ->first();

        $asistencias = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->where('reservas.persona_id', $persona->getKey())
            ->count();

        // Documentos/waivers pendientes de firma (R-waivers).
        $documentosPendientes = $this->waivers->pendientesDe($persona)->count();

        return response()->json(['data' => [
            'id' => $persona->ulid,
            'nombre_completo' => $persona->nombreCompleto(),
            'email' => $persona->email,
            'tipo' => $persona->tipo->value,
            'activo' => $persona->activo,
            'asistencias' => $asistencias,
            'primera_vez' => $asistencias === 0,
            'saldo_creditos' => intdiv($saldo, 1000),
            'saldo_unidades' => $saldo,
            'membresia' => [
                'estado' => $estado,
                'valido_hasta' => $membresia['valido_hasta'],
                'pausada_hasta' => $membresia['pausada_hasta'],
                'plan' => $membresia['plan'],
            ],
            'adeudo' => $adeudo,
            'documentos_pendientes' => $documentosPendientes,
            'proxima_reserva' => $proxima instanceof ReservaTenant ? [
                'clase' => $proxima->sesion?->oferta?->nombre,
                'inicia_en' => $proxima->sesion?->inicia_en->toIso8601String(),
                'zona_horaria' => $proxima->sesion?->zona_horaria,
            ] : null,
            'alertas' => $this->alertas($adeudo, $estado, $documentosPendientes),
        ]]);
    }

    /**
     * Códigos de alerta accionables para la recepción.
     *
     * @return list<string>
     */
    private function alertas(bool $adeudo, string $estadoMembresia, int $documentosPendientes): array
    {
        $alertas = [];
        if ($adeudo) {
            $alertas[] = 'adeudo';
        }
        if ($estadoMembresia === 'vencida') {
            $alertas[] = 'membresia_vencida';
        } elseif ($estadoMembresia === 'por_vencer') {
            $alertas[] = 'membresia_por_vencer';
        } elseif ($estadoMembresia === 'pausada') {
            $alertas[] = 'membresia_pausada';
        } elseif ($estadoMembresia === 'sin') {
            $alertas[] = 'sin_acceso';
        }
        if ($documentosPendientes > 0) {
            $alertas[] = 'documentos';
        }

        return $alertas;
    }
}
