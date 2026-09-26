<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Agenda una CITA desde un hueco de disponibilidad (F-08): materializa la sesión del
 * servicio con el proveedor a la hora elegida (cupo 1, individual) y encadena la
 * reserva según la política de la oferta — `pago` crea reserva pendiente + orden por la
 * sesión (a pagar para confirmar); `entitlement` consume la membresía. Todo atómico.
 *
 * El servidor no confía en lo que ofreció la pantalla: el servicio debe agendarse
 * como cita, el profesional debe serlo, la duración es la del servicio y, cuando
 * agenda el cliente (página pública o su cuenta), la hora debe ser futura y caer en
 * la atención del profesional en esa sede, en un día abierto. El negocio (recepción)
 * puede agendar fuera de horario, pero nunca encimado.
 *
 * Concurrencia: lo PRIMERO de la transacción es una escritura sin cambios sobre la
 * fila del profesional. En MySQL eso toma su bloqueo exclusivo; en SQLite, el de
 * escritura de la base. Así dos solicitudes para el mismo profesional se atienden en
 * fila y la segunda, al continuar, ya ve la cita de la primera. El choque se busca
 * por intervalo (cualquier solapamiento, no solo la misma hora de inicio). Si aun así
 * la base reporta un choque de concurrencia, se responde que el horario ya no está
 * disponible.
 */
class AgendarCitaTenant
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly ReservasTenant $reservas,
        private readonly CalcularDisponibilidadTenant $disponibilidad,
        private readonly ElegirRecursoTenant $recursos,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function agendar(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        ?int $instructorId,
        CarbonImmutable $inicia,
        int $duracionMin,
        bool $porNegocio = false,
    ): ReservaTenant {
        if ($oferta->politica_reserva !== PoliticaReservaTenant::Pago
            && ! in_array($oferta->modalidad, [ModalidadOfertaTenant::Individual, ModalidadOfertaTenant::Privada], true)) {
            throw new SesionNoReservable('Ese servicio no se agenda como cita.');
        }

        // La duración la fija el servicio; la que manda la pantalla solo cuenta si el
        // servicio no tiene una.
        $duracion = $oferta->duracion_minutos !== null && $oferta->duracion_minutos > 0
            ? (int) $oferta->duracion_minutos
            : ($duracionMin > 0 ? $duracionMin : $this->parametros->entero('citas.duracion_defecto'));
        $termina = $inicia->addMinutes($duracion);

        if (! $porNegocio) {
            if (! $inicia->isFuture()) {
                throw new SesionNoReservable('Ese horario ya pasó.');
            }
            if ($instructorId === null || ! $this->disponibilidad->cabeEnHorario($instructorId, $sucursal, $inicia, $termina)) {
                throw new SesionNoReservable('Ese horario está fuera de la atención del profesional.');
            }
        }

        try {
            return $this->agendarEnTransaccion($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio);
        } catch (QueryException $e) {
            if ($this->causedByConcurrencyError($e)) {
                throw new SesionNoReservable('Ese horario ya no está disponible.');
            }

            throw $e;
        }
    }

    private function agendarEnTransaccion(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        ?int $instructorId,
        CarbonImmutable $inicia,
        CarbonImmutable $termina,
        bool $porNegocio,
    ): ReservaTenant {
        return DB::connection('tenant')->transaction(function () use ($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio): ReservaTenant {
            // Punto de serialización por profesional (ver arriba). Debe ir antes de
            // leer su agenda para que la lectura vea lo que otra solicitud guardó.
            $this->agenda->bloquear($instructorId, null);
            $profesional = $instructorId !== null ? Usuario::query()->find($instructorId) : null;
            // Un profesional invitado que aún no activa su cuenta sí atiende; uno dado
            // de baja ya no se encuentra.
            if (! $profesional instanceof Usuario || ! in_array('instructor', $profesional->rolesEfectivos(), true)) {
                throw new SesionNoReservable('Esa persona no atiende citas.');
            }

            // El hueco debe seguir libre (el proveedor no puede tener dos cosas a la vez),
            // con la preparación y la limpieza del servicio.
            if ($this->agenda->conflictos($instructorId, null, $inicia, $termina, sucursalId: (int) $sucursal->getKey(), margenes: MargenesServicio::de($oferta)) !== []) {
                throw new SesionNoReservable('Ese horario ya no está disponible.');
            }

            // Si el servicio requiere cabina o equipo (2.4), toma uno libre de la sede
            // bajo su candado: dos profesionales no se quedan con la misma cabina.
            $recurso = null;
            if ($this->recursos->requiere($oferta)) {
                $recurso = $this->recursos->libre($oferta, (int) $sucursal->getKey(), $inicia, $termina, MargenesServicio::de($oferta), bloquear: true);
                if (! $recurso instanceof RecursoTenant) {
                    throw new SesionNoReservable('No hay un espacio libre para ese servicio en ese horario.');
                }
            }

            $sesion = SesionTenant::query()->create([
                'oferta_id' => $oferta->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'instructor_id' => $instructorId,
                'recurso_id' => $recurso?->getKey(),
                'inicia_en' => $inicia,
                'termina_en' => $termina,
                'zona_horaria' => $sucursal->zona_horaria,
                'capacidad' => 1,
                'estado' => EstadoSesionTenant::Programada->value,
                'tipo' => TipoSesionTenant::Cita->value,
            ]);

            if ($oferta->politica_reserva === PoliticaReservaTenant::Pago) {
                // El negocio agenda y cobra en caja (confirmada); el cliente en línea
                // paga para confirmar (pendiente de pago, expira si no paga).
                if ($porNegocio) {
                    return $this->reservas->reservarPorNegocio(
                        $sesion,
                        $persona,
                        (int) ($oferta->precio_clase_minor ?? 0),
                        'MXN',
                        (int) $sucursal->getKey(),
                    );
                }

                return $this->reservas->reservarConPago(
                    $sesion,
                    $persona,
                    (int) ($oferta->precio_clase_minor ?? 0),
                    'MXN',
                    (int) $sucursal->getKey(),
                );
            }

            return $this->reservas->crear($sesion, $persona);
        }, 3);
    }
}
