<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Models\AsistenciaTenant as ModeloAsistenciaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaNoConfirmada;
use Illuminate\Support\Facades\DB;

/**
 * Registra (o corrige) la asistencia tenant-local de una reserva confirmada y liquida
 * su crédito:
 *
 * - La primera vez liquida la retención exactamente una vez: `presente` consume el
 *   crédito (servicio prestado); `ausente` lo pierde (no-show), o lo devuelve si la
 *   política congelada no penaliza el no-show.
 * - Re-marcar lo mismo no hace nada. Corregir (presente ↔ ausente) compensa en el
 *   ledger solo si cambia si se cobra o no, con un movimiento que dice "Corrección de
 *   asistencia" (fase 1, punto 1.4).
 * - Todo bajo el candado de la reserva: una cancelación simultánea no puede colarse
 *   entre la revisión y el registro (y cancelar una reserva con asistencia se rechaza).
 *
 * Un derecho ilimitado no tiene retención y solo registra la asistencia.
 */
class AsistenciaTenant
{
    public function __construct(
        private readonly CreditosTenant $creditos,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    public function marcar(ReservaTenant $reserva, EstadoAsistencia $estado, ?Usuario $actor = null): ModeloAsistenciaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $estado, $actor): ModeloAsistenciaTenant {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoReserva::Confirmada) {
                throw new ReservaNoConfirmada(self::porQueNo($bloqueada->estado));
            }

            $anterior = ModeloAsistenciaTenant::query()->where('reserva_id', $bloqueada->getKey())->first();
            if ($anterior instanceof ModeloAsistenciaTenant && $anterior->estado === $estado) {
                return $anterior;
            }

            $asistencia = ModeloAsistenciaTenant::query()->updateOrCreate(
                ['reserva_id' => $bloqueada->getKey()],
                ['estado' => $estado->value, 'registrada_en' => now()],
            );

            // Evento de dominio (outbox): habilita acumular puntos de lealtad al asistir.
            $this->eventos->registrar('asistencia.marcada', 'asistencia', $asistencia->ulid, [
                'persona_id' => $bloqueada->persona?->ulid,
                'estado' => $estado->value,
                'reserva_id' => $bloqueada->ulid,
            ]);

            $retencion = $bloqueada->retencion;
            if ($retencion === null) {
                return $asistencia;
            }

            // Mover crédito queda trazable: origen (reserva), la reserva y quién marcó.
            $penaliza = $bloqueada->penaliza_no_show ?? true;
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

                return $asistencia;
            }

            // Corrección: se compensa solo si cambia si se cobra o no.
            $cobradoAntes = self::seCobra($anterior->estado, $penaliza);
            $cobrarAhora = self::seCobra($estado, $penaliza);
            $derecho = $retencion->derecho;
            if ($derecho !== null && $cobrarAhora && ! $cobradoAntes) {
                $this->creditos->consumir($derecho, (int) $retencion->unidades, 'Corrección de asistencia', $contexto);
            } elseif ($derecho !== null && ! $cobrarAhora && $cobradoAntes) {
                $this->creditos->devolver($derecho, (int) $retencion->unidades, 'Corrección de asistencia', $contexto);
            }

            return $asistencia;
        });
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
