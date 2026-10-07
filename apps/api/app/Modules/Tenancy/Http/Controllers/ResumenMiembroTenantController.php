<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\ResumenMembresiasTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
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
        private readonly AlcanceClientesTenant $alcance,
        private readonly ResumenMembresiasTenant $membresias,
        private readonly WhatsAppTenant $whatsapp,
        private readonly RegionNegocioTenant $region,
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
        // Quien imparte solo abre a sus clientes (quienes reservaron sus sesiones).
        abort_unless($this->alcance->puedeVer($persona, $actor instanceof Usuario ? $actor : null), 403);

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

        // Su última visita (la más reciente a la que llegó).
        $ultima = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->where('reservas.persona_id', $persona->getKey())
            ->orderByDesc('sesiones.inicia_en')
            ->select('reservas.*')
            ->with(['sesion.oferta', 'sesion.instructor'])
            ->first();

        $asistencias = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->where('reservas.persona_id', $persona->getKey())
            ->count();

        // Lo que hizo en el periodo (`?dias=`, 30 por omisión): sesiones que ya pasaron.
        $dias = min(max((int) $request->query('dias', 30), 1), 366);
        $enPeriodo = fn () => ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('reservas.persona_id', $persona->getKey())
            ->whereBetween('sesiones.inicia_en', [$ahora->subDays($dias), $ahora]);
        $conAsistencia = fn (EstadoAsistencia $estado): int => $enPeriodo()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', $estado->value)
            ->count();
        $estadisticas = [
            'dias' => $dias,
            'asistencias' => $conAsistencia(EstadoAsistencia::Presente),
            // Reservadas: las que tomaron un lugar (las canceladas cuentan; la lista de
            // espera que no se concretó, no).
            'reservadas' => $enPeriodo()
                ->whereNotIn('reservas.estado', [EstadoReserva::EnEspera->value, EstadoReserva::Ofrecida->value, EstadoReserva::Expirada->value])
                ->count(),
            'canceladas' => $enPeriodo()->where('reservas.estado', EstadoReserva::Cancelada->value)->count(),
            'no_asistio' => $conAsistencia(EstadoAsistencia::Ausente),
        ];

        // Documentos/waivers pendientes de firma (R-waivers).
        $documentosPendientes = $this->waivers->pendientesDe($persona)->count();

        return response()->json(['data' => [
            'id' => $persona->ulid,
            'nombre_completo' => $persona->nombreCompleto(),
            'email' => $persona->email,
            // Para nombrarla con el término del negocio en su género (Alumno/Alumna).
            'genero' => $persona->genero?->value,
            // Cómo conoció al negocio (ADR 0067).
            'como_nos_conocio' => $persona->como_nos_conocio,
            // Avisos por WhatsApp (ADR 0069): solo si el negocio los usa.
            'whatsapp' => [
                'disponible' => $this->whatsapp->enUso(),
                'acepta' => $persona->whatsapp_aceptado_en !== null,
                'con_celular' => TelefonoWhatsApp::normalizar($persona->celular, $this->region->lada()) !== null,
            ],
            'tipo' => $persona->tipo->value,
            'activo' => $persona->activo,
            'asistencias' => $asistencias,
            'primera_vez' => $asistencias === 0,
            'estadisticas' => $estadisticas,
            // Lo que puede usar de sus paquetes vigentes; con una membresía ilimitada
            // vigente, `ilimitado` (y el saldo no es lo que manda).
            'ilimitado' => (bool) $membresia['ilimitado'],
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
            'ultima_visita' => $ultima instanceof ReservaTenant ? [
                'clase' => $ultima->sesion?->oferta?->nombre,
                'profesional' => $ultima->sesion?->instructor?->name,
                'inicia_en' => $ultima->sesion?->inicia_en->toIso8601String(),
                'zona_horaria' => $ultima->sesion?->zona_horaria,
            ] : null,
            // En citas, quien paga cada servicio no está «sin acceso»: esa alerta no aplica.
            'alertas' => $this->alertas($adeudo, $estado, $documentosPendientes, $this->esCitas($request)),
        ]]);
    }

    private function esCitas(Request $request): bool
    {
        $estudio = $request->attributes->get('estudio');

        return $estudio instanceof Estudio && $estudio->modalidad() === ModalidadServicio::Citas;
    }

    /**
     * Códigos de alerta accionables para la recepción.
     *
     * @return list<string>
     */
    private function alertas(bool $adeudo, string $estadoMembresia, int $documentosPendientes, bool $citas): array
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
        } elseif ($estadoMembresia === 'sin' && ! $citas) {
            $alertas[] = 'sin_acceso';
        }
        if ($documentosPendientes > 0) {
            $alertas[] = 'documentos';
        }

        return $alertas;
    }
}
