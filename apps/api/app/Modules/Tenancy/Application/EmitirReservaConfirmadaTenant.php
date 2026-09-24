<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;

/**
 * Asienta `reserva.confirmada` cuando una reserva queda confirmada, por cualquier
 * camino: al reservar con lugar, al aceptar un lugar de la lista de espera, al pagar
 * una cita o al recibir una transferencia. Lleva los datos de la clase o cita para el
 * correo de confirmación. Debe llamarse dentro de la transacción del cambio.
 */
class EmitirReservaConfirmadaTenant
{
    public function __construct(private readonly RegistrarEventoTenant $eventos) {}

    public function emitir(ReservaTenant $reserva): void
    {
        $reserva->loadMissing(['sesion', 'persona']);
        $sesion = $reserva->sesion;
        $persona = $reserva->persona;
        if (! $sesion instanceof SesionTenant || ! $persona instanceof PersonaTenant) {
            return;
        }

        $this->eventos->registrar('reserva.confirmada', 'reserva', (string) $reserva->ulid, [
            'persona_id' => (string) $persona->ulid,
            ...DatosDeSesion::para($sesion),
        ]);
    }
}
