<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaYaAtendida;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\Reservas\Recordatorio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reprogramar sin cancelar y volver a capturar (fase 2, punto 2.1):
 *
 * - una CITA se mueve a otra hora (y, si se pide, con otro profesional): es la misma
 *   reserva, con su orden y sus pagos, así que no se vuelve a cobrar;
 * - un ALUMNO se mueve a otra fecha de la MISMA clase (conserva su crédito apartado);
 * - una CLASE completa cambia de horario y todas sus reservas la siguen.
 *
 * Todo se revalida al guardar con las reglas únicas de agenda (ADR 0033: choques,
 * márgenes, bloqueos) y dentro de una transacción: si el nuevo horario ya no está
 * libre, lo original queda intacto. Lo que tiene asistencia ya ocurrió y no se mueve.
 *
 * Avisos (2.7): cada persona afectada recibe `reserva.reprogramada` con el horario
 * anterior y el nuevo (también el profesional de la cita); los recordatorios del
 * horario anterior que aún no salen se descartan y los del nuevo se mandan a su hora.
 */
class ReprogramarTenant
{
    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly ReservasTenant $reservas,
        private readonly RegistrarEventoTenant $eventos,
        private readonly RegistrarAuditoria $auditoria,
        private readonly ElegirRecursoTenant $recursos,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function moverCita(ReservaTenant $reserva, CarbonImmutable $inicia, ?int $instructorId, ?Usuario $actor): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $inicia, $instructorId, $actor): ReservaTenant {
            // La sesión y luego la reserva: el orden único del motor (ADR 0230).
            [$sesion, $bloqueada] = $this->reservas->bloquearConSesion($reserva);
            $this->exigirMovible($bloqueada);
            if (! $sesion->esCita()) {
                throw new SesionNoReservable('Esta reserva es de una clase: muévela a otra fecha de la clase.');
            }
            // Con otro profesional: quien atiende citas y da ese servicio (ADR 0217).
            if ($instructorId !== null && $instructorId !== (int) $sesion->instructor_id) {
                $this->exigirQuienDaElServicio($sesion, $instructorId);
            }
            // El profesional antes que el recurso, como al agendar (ADR 0230): al revés, una
            // cita que se agenda y otra que se mueve se bloquearían entre sí.
            $this->agenda->bloquear($instructorId ?? ($sesion->instructor_id !== null ? (int) $sesion->instructor_id : null), null);

            // Cabina o equipo (2.4): uno libre a la nueva hora (la misma si sigue libre).
            $oferta = $sesion->oferta;
            if ($oferta !== null && $this->recursos->requiere($oferta)) {
                $termina = $inicia->addMinutes((int) $sesion->inicia_en->diffInMinutes($sesion->termina_en, true));
                $recurso = $this->recursos->libre($oferta, (int) $sesion->sucursal_id, $inicia, $termina, MargenesServicio::deSesion($sesion), (int) $sesion->getKey(), true);
                if ($recurso === null) {
                    throw new SesionNoReservable('No hay un espacio libre para ese servicio en ese horario.');
                }
                $sesion->recurso_id = (int) $recurso->getKey();
                $sesion->unsetRelation('recurso');
            }

            $this->moverSesion($sesion, $inicia, $instructorId ?? ($sesion->instructor_id !== null ? (int) $sesion->instructor_id : null), [$bloqueada], $actor);

            return $bloqueada->refresh();
        }, 3);
    }

    /**
     * Pasa una cita a otro profesional, a la misma hora (`PUT /sesiones/{sesion}/instructor`):
     * con el candado de la sesión, a quien atiende citas y da ese servicio (ADR 0217), sin
     * encimarse. Si aún no ocurre, quien la tiene recibe el aviso con su profesional nuevo.
     */
    public function pasarCitaA(SesionTenant $sesion, ?int $instructorId, ?Usuario $actor): SesionTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sesion, $instructorId, $actor): SesionTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoSesionTenant::Programada) {
                throw new SesionNoReservable('Esta cita ya no está programada.');
            }
            if ($instructorId === null) {
                throw new SesionNoReservable('Una cita siempre tiene quién la atienda: elige a otro profesional.');
            }
            if ($instructorId === (int) $bloqueada->instructor_id) {
                return $bloqueada;
            }
            $this->exigirQuienDaElServicio($bloqueada, $instructorId);
            $reservas = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->getKey())
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
                ->lockForUpdate()
                ->get()
                ->all();

            $this->agenda->bloquear($instructorId, null);
            $this->agenda->exigirSinConflictos($instructorId, null, $bloqueada->inicia_en, $bloqueada->termina_en, (int) $bloqueada->getKey(), margenes: MargenesServicio::deSesion($bloqueada));

            $antes = DatosDeSesion::para($bloqueada);
            $bloqueada->update(['instructor_id' => $instructorId]);
            $bloqueada->unsetRelation('instructor');
            if ($bloqueada->inicia_en->isFuture()) {
                foreach ($reservas as $reserva) {
                    $this->avisar($reserva, $bloqueada, $antes);
                }
            }
            $this->anotarEnBitacora($bloqueada, $reservas, $antes, $actor);

            return $bloqueada;
        }, 3);
    }

    public function moverAClase(ReservaTenant $reserva, SesionTenant $destino, ?Usuario $actor): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $destino, $actor): ReservaTenant {
            // Las dos sesiones y luego la reserva: el orden único del motor (ADR 0230).
            SesionTenant::query()
                ->whereKey([ReservaTenant::query()->whereKey($reserva->getKey())->value('sesion_id'), $destino->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
            $this->exigirMovible($bloqueada);
            $origen = SesionTenant::query()->whereKey($bloqueada->sesion_id)->firstOrFail();
            $antes = DatosDeSesion::para($origen);

            $this->reservas->moverA($bloqueada, $destino);

            $bloqueada->refresh();
            $nueva = SesionTenant::query()->whereKey($bloqueada->sesion_id)->firstOrFail();
            $this->recordatoriosDelNuevoHorario($bloqueada, $nueva);
            $this->avisar($bloqueada, $nueva, $antes);
            $this->auditoria->registrar($actor, 'reserva.reprogramada', 'reserva', (string) $bloqueada->ulid, $antes, DatosDeSesion::para($nueva));

            return $bloqueada;
        }, 3);
    }

    public function moverClase(SesionTenant $sesion, CarbonImmutable $inicia, ?int $instructorId, ?Usuario $actor): SesionTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sesion, $inicia, $instructorId, $actor): SesionTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoSesionTenant::Programada) {
                throw new SesionNoReservable('Esta sesión ya no está programada.');
            }
            if (ReservaTenant::query()->where('sesion_id', $bloqueada->getKey())->whereHas('asistencia')->exists()) {
                throw new ReservaYaAtendida('Esta clase ya tiene asistencia registrada; no se puede mover.');
            }
            // Una cita pasa a otro profesional con las mismas reglas por cualquier camino.
            if ($bloqueada->esCita() && $instructorId !== null && $instructorId !== (int) $bloqueada->instructor_id) {
                $this->exigirQuienDaElServicio($bloqueada, $instructorId);
            }

            $reservas = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->getKey())
                ->whereIn('estado', [
                    EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                    EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
                ])
                ->lockForUpdate()
                ->get()
                ->all();

            $this->moverSesion($bloqueada, $inicia, $instructorId ?? ($bloqueada->instructor_id !== null ? (int) $bloqueada->instructor_id : null), $reservas, $actor);
            // "Solo esta sesión": un cambio posterior a su serie la respeta (2.5).
            if ($bloqueada->serie_id !== null) {
                $bloqueada->forceFill(['editada_en' => now()])->save();
            }

            return $bloqueada->refresh();
        }, 3);
    }

    /**
     * Mueve la sesión (con su duración) bajo candado y avisa a sus reservas.
     *
     * @param  list<ReservaTenant>  $reservas
     */
    private function moverSesion(SesionTenant $sesion, CarbonImmutable $inicia, ?int $instructorId, array $reservas, ?Usuario $actor): void
    {
        if (! $inicia->isFuture()) {
            throw new SesionNoReservable('El nuevo horario ya pasó.');
        }

        $duracion = (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en, true);
        $termina = $inicia->addMinutes($duracion);
        $recurso = $sesion->recurso;

        $this->agenda->bloquear($instructorId, $recurso);
        $this->agenda->exigirSinConflictos(
            $instructorId,
            $recurso,
            $inicia,
            $termina,
            (int) $sesion->getKey(),
            (int) $sesion->sucursal_id,
            MargenesServicio::deSesion($sesion),
        );

        $antes = DatosDeSesion::para($sesion);
        $sesion->update(['inicia_en' => $inicia, 'termina_en' => $termina, 'instructor_id' => $instructorId]);
        $sesion->unsetRelation('instructor');

        foreach ($reservas as $reserva) {
            $this->recordatoriosDelNuevoHorario($reserva, $sesion);
            if ($reserva->estado !== EstadoReserva::EnEspera) {
                $this->avisar($reserva, $sesion, $antes);
            }
        }

        $this->anotarEnBitacora($sesion, $reservas, $antes, $actor);
    }

    /**
     * Bitácora: en una cita se reprogramó la reserva; en una clase, la sesión.
     *
     * @param  list<ReservaTenant>  $reservas
     * @param  array<string, string>  $antes
     */
    private function anotarEnBitacora(SesionTenant $sesion, array $reservas, array $antes, ?Usuario $actor): void
    {
        if ($sesion->esCita() && $reservas !== []) {
            $this->auditoria->registrar($actor, 'reserva.reprogramada', 'reserva', (string) $reservas[0]->ulid, $antes, DatosDeSesion::para($sesion));
        } else {
            $this->auditoria->registrar($actor, 'sesion.reprogramada', 'sesion', (string) $sesion->ulid, $antes, DatosDeSesion::para($sesion));
        }
    }

    /**
     * Mueve una sesión de una serie al aplicar un cambio de la serie (2.5), con las
     * mismas reglas que reprogramar. Si choca, devuelve el motivo y no la toca; si
     * cambió su hora, sus reservas reciben el aviso y sus recordatorios se rehacen.
     */
    public function moverInstancia(SesionTenant $sesion, CarbonImmutable $inicia, CarbonImmutable $termina, ?int $instructorId, ?int $recursoId): ?string
    {
        $recurso = $recursoId !== null ? RecursoTenant::query()->find($recursoId) : null;
        $this->agenda->bloquear($instructorId, $recurso);
        $conflictos = $this->agenda->conflictos($instructorId, $recurso, $inicia, $termina, (int) $sesion->getKey(), (int) $sesion->sucursal_id, null, MargenesServicio::deSesion($sesion));
        if ($conflictos !== []) {
            return $conflictos[0]['mensaje'];
        }

        $antes = DatosDeSesion::para($sesion);
        $cambiaHora = ! $sesion->inicia_en->equalTo($inicia);
        $sesion->update(['inicia_en' => $inicia, 'termina_en' => $termina, 'instructor_id' => $instructorId, 'recurso_id' => $recursoId]);
        $sesion->unsetRelation('instructor');

        if ($cambiaHora) {
            $reservas = ReservaTenant::query()
                ->where('sesion_id', $sesion->getKey())
                ->whereIn('estado', [
                    EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                    EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
                ])
                ->lockForUpdate()
                ->get();
            foreach ($reservas as $reserva) {
                $this->recordatoriosDelNuevoHorario($reserva, $sesion);
                if ($reserva->estado !== EstadoReserva::EnEspera) {
                    $this->avisar($reserva, $sesion, $antes);
                }
            }
        }

        return null;
    }

    /**
     * Su sede cambió de zona horaria (ADR 0230): la sesión conserva su hora de reloj. Se
     * recalcula su instante, sus recordatorios se rehacen y quien tiene reserva recibe el
     * aviso, que dice las dos zonas porque la hora de reloj es la misma. Idempotente: la
     * zona copiada dice si ya se re-ancló. Con el mismo instante (dos zonas con la misma
     * hora ese día) no cambia nada para nadie: solo se anota la zona, sin aviso.
     */
    public function reanclarZona(SesionTenant $sesion, string $zona): bool
    {
        return DB::connection('tenant')->transaction(function () use ($sesion, $zona): bool {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->zona_horaria === $zona || $bloqueada->estado !== EstadoSesionTenant::Programada || ! $bloqueada->inicia_en->isFuture()) {
                return false;
            }
            $reservas = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->getKey())
                ->whereIn('estado', [
                    EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                    EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
                ])
                ->lockForUpdate()
                ->get();

            $anterior = (string) $bloqueada->zona_horaria;
            $inicia = ReanclarZonaDeSedeTenant::mismaHoraDeReloj(CarbonImmutable::instance($bloqueada->inicia_en), $anterior, $zona);
            $mismoInstante = $bloqueada->inicia_en->equalTo($inicia);
            $duracion = (int) $bloqueada->inicia_en->diffInMinutes($bloqueada->termina_en, true);
            $antes = DatosDeSesion::para($bloqueada);
            $antes['hora'] .= self::horaDe($anterior);

            $bloqueada->update(['inicia_en' => $inicia, 'termina_en' => $inicia->addMinutes($duracion), 'zona_horaria' => $zona]);
            if ($mismoInstante) {
                return true;
            }

            foreach ($reservas as $reserva) {
                $this->recordatoriosDelNuevoHorario($reserva, $bloqueada);
                if ($reserva->estado !== EstadoReserva::EnEspera) {
                    $this->avisar($reserva, $bloqueada, $antes, ['hora' => DatosDeSesion::para($bloqueada)['hora'].self::horaDe($zona)]);
                }
            }

            return true;
        }, 3);
    }

    /** « (hora de Tijuana)»: dice de qué zona es una hora de reloj en un aviso. */
    private static function horaDe(string $zona): string
    {
        return ' (hora de '.str_replace('_', ' ', (string) last(explode('/', $zona))).')';
    }

    /**
     * La reserva, ya bajo candado, debe seguir activa y sin asistencia.
     */
    private function exigirMovible(ReservaTenant $bloqueada): void
    {
        if (! in_array($bloqueada->estado, [EstadoReserva::Confirmada, EstadoReserva::PendientePago], true)) {
            throw new SesionNoReservable('Solo se reprograma una reserva confirmada o por pagar.');
        }
        if ($bloqueada->asistencia()->exists()) {
            throw new ReservaYaAtendida('Ya se registró la asistencia de esta reserva; no se puede mover.');
        }
    }

    /**
     * Una cita solo pasa a quien atiende citas (uno dado de baja ya no se encuentra) y da
     * ese servicio (ADR 0217), lo mismo que al agendarla.
     */
    public function exigirQuienDaElServicio(SesionTenant $sesion, int $instructorId): void
    {
        $profesional = Usuario::query()->find($instructorId);
        if (! $profesional instanceof Usuario || ! $profesional->esProfesional()) {
            throw new SesionNoReservable('Esa persona no atiende citas.');
        }
        if ($sesion->oferta !== null && ! $sesion->oferta->loDa($instructorId)) {
            throw new SesionNoReservable('Ese profesional no da este servicio. Elige a otro.');
        }
    }

    /**
     * Los recordatorios del horario anterior que aún no salen se descartan (evento sin
     * publicar o mensaje sin enviar). Los del nuevo se mandan a su hora; si su momento
     * ya pasó, se dan por enviados (ya tiene el aviso del cambio).
     */
    private function recordatoriosDelNuevoHorario(ReservaTenant $reserva, SesionTenant $sesion): void
    {
        $motivo = 'Descartado: la reserva cambió de horario.';
        $eventos = EventoOutboxTenant::query()
            ->where('agregado_tipo', 'reserva')
            ->where('agregado_id', $reserva->ulid)
            ->whereIn('tipo', array_map(static fn (Recordatorio $r): string => $r->evento(), Recordatorio::cases()))
            ->get(['id', 'ulid', 'publicado_en']);

        EventoOutboxTenant::query()
            ->whereIn('id', $eventos->whereNull('publicado_en')->pluck('id')->all())
            ->update(['publicado_en' => now(), 'ultimo_error' => $motivo]);
        MensajeTenant::query()
            ->whereIn('evento_ulid', $eventos->pluck('ulid')->all())
            ->where('estado', EstadoMensaje::Encolado->value)
            ->update(['estado' => EstadoMensaje::Descartado->value, 'ultimo_error' => $motivo]);

        $marcas = [];
        foreach (Recordatorio::cases() as $recordatorio) {
            $momento = CarbonImmutable::instance($sesion->inicia_en)->subMinutes($recordatorio->minutos($this->parametros));
            $marcas[$recordatorio->columna()] = $momento->isPast() ? now() : null;
        }
        $reserva->forceFill($marcas)->save();
    }

    /**
     * @param  array<string, string>  $antes
     * @param  array<string, string>  $ajustes  lo que cambia del horario nuevo en el aviso
     */
    private function avisar(ReservaTenant $reserva, SesionTenant $sesion, array $antes, array $ajustes = []): void
    {
        $reserva->loadMissing('persona');
        $this->eventos->registrar('reserva.reprogramada', 'reserva', (string) $reserva->ulid, [
            'persona_id' => (string) $reserva->persona?->ulid,
            ...DatosDeSesion::para($sesion),
            ...$ajustes,
            'antes_fecha' => $antes['fecha'],
            'antes_hora' => $antes['hora'],
            'antes_inicia_en' => $antes['inicia_en'],
        ]);
    }
}
