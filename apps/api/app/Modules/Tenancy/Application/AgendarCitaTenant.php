<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Agenda una CITA desde un hueco de disponibilidad (F-08): materializa la sesión del
 * servicio con el proveedor a la hora elegida (cupo 1, individual) y encadena la
 * reserva según la política de la oferta — `pago` crea reserva pendiente + orden por la
 * sesión (a pagar para confirmar); `entitlement` consume la membresía. Todo atómico.
 * Reusa {@see VerificarAgendaTenant} (el hueco debe seguir libre) y {@see ReservasTenant}.
 * NOTA de concurrencia: dos clientes que tomen EXACTAMENTE el mismo hueco a la vez
 * pasan ambos el chequeo (no hay lock sobre una fila inexistente); es una ventana breve
 * — mitigar con índice único (instructor_id, inicia_en) o lock si se vuelve un problema.
 */
class AgendarCitaTenant
{
    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly ReservasTenant $reservas,
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
        $termina = $inicia->addMinutes($duracionMin);

        return DB::connection('tenant')->transaction(function () use ($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio): ReservaTenant {
            // El hueco debe seguir libre (el proveedor no puede tener dos cosas a la vez).
            if ($this->agenda->conflictos($instructorId, null, $inicia, $termina) !== []) {
                throw new SesionNoReservable('Ese horario ya no está disponible.');
            }

            $sesion = SesionTenant::query()->create([
                'oferta_id' => $oferta->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'instructor_id' => $instructorId,
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
        });
    }
}
