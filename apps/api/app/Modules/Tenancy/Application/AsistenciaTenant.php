<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant as ModeloAsistenciaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\AsistenciaAunNoAbre;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaNoConfirmada;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra (o corrige) la asistencia tenant-local de una reserva confirmada y liquida
 * su crédito (y guarda si lo cobró, `credito_cobrado`):
 *
 * - La primera vez liquida la retención exactamente una vez: `presente` consume el
 *   crédito (servicio prestado); `ausente` lo pierde (no-show), o lo devuelve si la
 *   política congelada no penaliza el no-show o si es una de las faltas toleradas
 *   (`tolerancia_no_show` en los últimos `ventana_no_show_dias`, ADR 0043).
 * - Re-marcar lo mismo no hace nada. Corregir (presente ↔ ausente) compensa en el
 *   ledger solo si cambia si se cobra o no, con un movimiento que dice "Corrección de
 *   asistencia" (fase 1, punto 1.4).
 * - Todo bajo el candado de la reserva: una cancelación simultánea no puede colarse
 *   entre la revisión y el registro (y cancelar una reserva con asistencia se rechaza).
 *
 * Un derecho ilimitado no tiene retención y solo registra la asistencia.
 *
 * ADR 0101: la asistencia se registra desde `asistencia.minutos_antes` antes de que
 * empiece (la que marca el sistema al terminar, siempre); «presente» puede ser con
 * retardo (cuenta como asistencia: mismo crédito); y quien no tiene registro al
 * terminar la clase o cita, o cuando se termina de pasar lista, «no se presentó».
 */
class AsistenciaTenant
{
    public function __construct(
        private readonly CreditosTenant $creditos,
        private readonly RegistrarEventoTenant $eventos,
        private readonly ResolverPoliticaCancelacionTenant $politicas,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @param  bool  $retardo  llegó tarde (solo con «presente»; cuenta como asistencia)
     * @param  bool  $automatica  la marca el sistema (al terminar sin registro): sin ventana
     */
    public function marcar(
        ReservaTenant $reserva,
        EstadoAsistencia $estado,
        ?Usuario $actor = null,
        bool $retardo = false,
        bool $automatica = false,
    ): ModeloAsistenciaTenant {
        $retardo = $retardo && $estado === EstadoAsistencia::Presente;
        if (! $automatica) {
            $this->exigirVentana($reserva);
        }

        return DB::connection('tenant')->transaction(function () use ($reserva, $estado, $actor, $retardo, $automatica): ModeloAsistenciaTenant {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoReserva::Confirmada) {
                throw new ReservaNoConfirmada(self::porQueNo($bloqueada->estado));
            }

            $anterior = ModeloAsistenciaTenant::query()->where('reserva_id', $bloqueada->getKey())->first();
            // La que ya registró alguien no la cambia el sistema.
            if ($automatica && $anterior instanceof ModeloAsistenciaTenant) {
                return $anterior;
            }
            if ($anterior instanceof ModeloAsistenciaTenant && $anterior->estado === $estado) {
                // Mismo estado: a lo más cambia si llegó tarde (no mueve créditos).
                if ((bool) $anterior->retardo !== $retardo) {
                    $anterior->update(['retardo' => $retardo, 'automatica' => false]);
                }

                return $anterior;
            }

            $asistencia = ModeloAsistenciaTenant::query()->updateOrCreate(
                ['reserva_id' => $bloqueada->getKey()],
                ['estado' => $estado->value, 'retardo' => $retardo, 'automatica' => $automatica, 'registrada_en' => now()],
            );

            // Evento de dominio (outbox): habilita acumular puntos de lealtad al asistir.
            $this->eventos->registrar('asistencia.marcada', 'asistencia', $asistencia->ulid, [
                'persona_id' => $bloqueada->persona?->ulid,
                'estado' => $estado->value,
                'retardo' => $retardo,
                'automatica' => $automatica,
                'reserva_id' => $bloqueada->ulid,
            ]);

            $retencion = $bloqueada->retencion;
            if ($retencion === null) {
                return $asistencia;
            }

            // Mover crédito queda trazable: origen (reserva), la reserva y quién marcó.
            // Una falta tolerada no se cobra aunque la política penalice el no-show.
            $penaliza = ($bloqueada->penaliza_no_show ?? true)
                && ($estado !== EstadoAsistencia::Ausente || ! $this->tolerada($bloqueada));
            $contexto = ContextoMovimiento::para(
                OrigenMovimiento::Reserva,
                'reserva',
                $bloqueada->ulid,
                $actor,
                $anterior === null ? ['asistencia' => $estado->value] : ['asistencia' => $estado->value, 'correccion' => true],
            );

            if (! $anterior instanceof ModeloAsistenciaTenant) {
                if ($estado === EstadoAsistencia::Presente) {
                    $this->creditos->confirmar($retencion, $contexto);
                } elseif ($penaliza) {
                    $this->creditos->perder($retencion, $contexto);
                } else {
                    $this->creditos->liberar($retencion);
                }
                $asistencia->update(['credito_cobrado' => self::seCobra($estado, $penaliza)]);

                return $asistencia;
            }

            // Corrección: se compensa solo si cambia si se cobra o no.
            // Lo que realmente pasó antes (o, en registros viejos, lo que dictaba la política).
            $cobradoAntes = $anterior->credito_cobrado ?? self::seCobra($anterior->estado, $bloqueada->penaliza_no_show ?? true);
            $cobrarAhora = self::seCobra($estado, $penaliza);
            $derecho = $retencion->derecho;
            if ($derecho !== null && $cobrarAhora && ! $cobradoAntes) {
                $this->creditos->consumir($derecho, (int) $retencion->unidades, 'Corrección de asistencia', $contexto);
            } elseif ($derecho !== null && ! $cobrarAhora && $cobradoAntes) {
                $this->creditos->devolver($derecho, (int) $retencion->unidades, 'Corrección de asistencia', $contexto);
            }
            $asistencia->update(['credito_cobrado' => $cobrarAhora]);

            return $asistencia;
        });
    }

    /**
     * Terminar de pasar lista: quien sigue sin registro «no se presentó». Desde que
     * empieza la clase o cita (antes, la lista aún no se termina).
     *
     * @return int cuántas quedaron como «no se presentó»
     */
    public function terminarLista(SesionTenant $sesion, ?Usuario $actor = null): int
    {
        if (CarbonImmutable::now()->lessThan($sesion->inicia_en)) {
            throw new AsistenciaAunNoAbre('La lista se termina cuando ya empezó.');
        }

        return $this->sinRegistro($sesion)
            ->reduce(function (int $cuantas, ReservaTenant $reserva) use ($actor): int {
                $this->marcar($reserva, EstadoAsistencia::Ausente, $actor, automatica: false);

                return $cuantas + 1;
            }, 0);
    }

    /**
     * Al terminar una clase o cita, quien no tiene registro «no se presentó» (si el
     * negocio lo tiene encendido). Solo las que terminaron en el último día: lo de
     * antes no se toca de golpe.
     *
     * @return int cuántas se marcaron
     */
    public function marcarInasistenciasAlTerminar(): int
    {
        if (! $this->parametros->siNo('asistencia.no_asistio_al_terminar')) {
            return 0;
        }
        $ahora = CarbonImmutable::now();
        $marcadas = 0;
        SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('termina_en', '<=', $ahora)
            ->where('termina_en', '>', $ahora->subDay())
            ->whereHas('reservas', fn ($q) => $q->where('estado', EstadoReserva::Confirmada->value)->whereDoesntHave('asistencia'))
            ->get()
            ->each(function (SesionTenant $sesion) use (&$marcadas): void {
                foreach ($this->sinRegistro($sesion) as $reserva) {
                    $this->marcar($reserva, EstadoAsistencia::Ausente, null, automatica: true);
                    $marcadas++;
                }
            });

        return $marcadas;
    }

    /**
     * Reservas confirmadas de la sesión que aún no tienen asistencia.
     *
     * @return Collection<int, ReservaTenant>
     */
    private function sinRegistro(SesionTenant $sesion): Collection
    {
        return ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', EstadoReserva::Confirmada->value)
            ->whereDoesntHave('asistencia')
            ->get()
            ->toBase();
    }

    /**
     * La asistencia se registra desde `asistencia.minutos_antes` antes de que empiece.
     */
    private function exigirVentana(ReservaTenant $reserva): void
    {
        $sesion = $reserva->sesion;
        if (! $sesion instanceof SesionTenant) {
            return;
        }
        $abre = CarbonImmutable::instance($sesion->inicia_en)->subMinutes($this->parametros->entero('asistencia.minutos_antes'));
        if (CarbonImmutable::now()->lessThan($abre)) {
            $hora = $abre->setTimezone((string) ($sesion->zona_horaria ?: app(FechasNegocioTenant::class)->zona()))->format('H:i');

            throw new AsistenciaAunNoAbre("La asistencia se registra desde las {$hora}.");
        }
    }

    /**
     * ¿Es una falta tolerada? Lo es si la persona lleva menos faltas que la tolerancia
     * de su política en la ventana de días (sin contar esta reserva).
     */
    private function tolerada(ReservaTenant $reserva): bool
    {
        $sesion = $reserva->sesion;
        if ($sesion === null) {
            return false;
        }
        $politica = $this->politicas->paraSesion($sesion);
        if ($politica->toleranciaNoShow <= 0) {
            return false;
        }

        $previas = ModeloAsistenciaTenant::query()
            ->where('estado', EstadoAsistencia::Ausente->value)
            ->where('reserva_id', '!=', $reserva->getKey())
            ->whereHas('reserva', fn ($q) => $q->where('persona_id', $reserva->persona_id)
                ->whereHas('sesion', fn ($s) => $s->where('inicia_en', '>=', now()->subDays($politica->ventanaNoShowDias))))
            ->count();

        return $previas < $politica->toleranciaNoShow;
    }

    private static function seCobra(EstadoAsistencia $estado, bool $penalizaNoShow): bool
    {
        return $estado === EstadoAsistencia::Presente || $penalizaNoShow;
    }

    private static function porQueNo(EstadoReserva $estado): string
    {
        return match ($estado) {
            EstadoReserva::Cancelada => 'Esta reserva está cancelada; no se puede marcar asistencia.',
            EstadoReserva::EnEspera, EstadoReserva::Ofrecida => 'Esta persona sigue en lista de espera; no se puede marcar asistencia.',
            EstadoReserva::PendientePago => 'Esta reserva sigue pendiente de pago.',
            default => 'Solo se registra asistencia de reservas confirmadas.',
        };
    }
}
