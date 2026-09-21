<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Asistencia\EstadoAsistencia;
use App\Modules\Reservas\EstadoReserva;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
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
    // Días de antelación para avisar que la membresía está por vencer.
    private const DIAS_POR_VENCER = 10;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly WaiversTenant $waivers,
        private readonly ResolverAccesoTenant $acceso,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        // Alcance por sucursal (R19): un acotado no ve el resumen de un alumno de otra sede.
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless(
            ! $actor instanceof Usuario || $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );

        $hoy = CarbonImmutable::now()->startOfDay();
        $ahora = CarbonImmutable::now();

        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->get();

        $tieneAcceso = false;
        $maxVigencia = null;
        $saldo = 0;
        foreach ($derechos as $derecho) {
            $vence = $derecho->valido_hasta;
            $vigente = $vence === null || $vence->gte($hoy);
            $disponible = $derecho->ilimitado ? 0 : $this->libro->disponible($derecho);

            if ($vigente && ($derecho->ilimitado || $disponible > 0)) {
                $tieneAcceso = true;
            }
            if (! $derecho->ilimitado && $vigente) {
                $saldo += max(0, $disponible);
            }
            if ($vence !== null && ($maxVigencia === null || $vence->gt($maxVigencia))) {
                $maxVigencia = $vence;
            }
        }

        // Estado de membresía (explicable): sin / vigente / por_vencer / vencida.
        $estado = 'sin';
        if ($derechos->isNotEmpty()) {
            if ($tieneAcceso) {
                $estado = $maxVigencia !== null && $maxVigencia->lte($hoy->addDays(self::DIAS_POR_VENCER)) ? 'por_vencer' : 'vigente';
            } elseif ($maxVigencia !== null && $maxVigencia->lt($hoy)) {
                $estado = 'vencida';
            }
        }

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
                'valido_hasta' => $maxVigencia?->toDateString(),
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
        } elseif ($estadoMembresia === 'sin') {
            $alertas[] = 'sin_acceso';
        }
        if ($documentosPendientes > 0) {
            $alertas[] = 'documentos';
        }

        return $alertas;
    }
}
