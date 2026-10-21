<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonInterface;

/**
 * Motor de politica de acceso tenant-local (R12): decide si una persona puede entrar
 * en un momento dado y por que (codigo estable). Reglas, en orden:
 * 1. Tiene una reserva CONFIRMADA de una sesion en curso (o por comenzar dentro de la
 *    ventana) en la sucursal -> ACCESS_BY_BOOKING.
 * 2. Tiene una membresia de ACCESO ABIERTO (derecho ilimitado activo y vigente) ->
 *    ACCESS_OPEN (entra sin reserva).
 * 3. En otro caso -> NO_ACCESS.
 * Opera sobre la BD del tenant resuelto.
 */
class EvaluarAccesoTenant
{
    // Minutos antes del inicio en que una reserva ya habilita el acceso (configurable).
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    public function evaluar(PersonaTenant $persona, ?int $sucursalId, CarbonInterface $momento): DecisionAcceso
    {
        $reserva = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('estado', EstadoReserva::Confirmada->value)
            ->whereHas('sesion', function ($q) use ($momento, $sucursalId): void {
                $q->where('estado', EstadoSesionTenant::Programada->value)
                    ->where('inicia_en', '<=', $momento->copy()->addMinutes($this->parametros->entero('acceso.minutos_antes')))
                    ->where('termina_en', '>=', $momento)
                    ->when($sucursalId !== null, fn ($q2) => $q2->where('sucursal_id', $sucursalId));
            })
            ->first();

        if ($reserva instanceof ReservaTenant) {
            return DecisionAcceso::permitir('ACCESS_BY_BOOKING', (int) $reserva->sesion_id);
        }

        if ($this->tieneAccesoAbierto($persona, $sucursalId, $momento)) {
            return DecisionAcceso::permitir('ACCESS_OPEN');
        }

        return DecisionAcceso::denegar('NO_ACCESS');
    }

    /**
     * Un plan ilimitado activo que cubre ese momento en días del negocio (desde su
     * inicio hasta las 23:59 locales de su último día) y, si se sabe la sede, que vale
     * en ella (ADR 0017).
     */
    private function tieneAccesoAbierto(PersonaTenant $persona, ?int $sucursalId, CarbonInterface $momento): bool
    {
        return DerechoTenant::query()
            ->where('ilimitado', true)
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey())->where('estado', EstadoAcuerdo::Activo->value))
            ->get()
            ->contains(fn (DerechoTenant $d): bool => $this->fechas->cubre($d->valido_desde, $d->valido_hasta, $momento)
                && ($sucursalId === null || (
                    ($d->sucursal_id === null || (int) $d->sucursal_id === $sucursalId)
                    && ($d->sucursales_ids === null || in_array($sucursalId, array_map('intval', $d->sucursales_ids), true))
                )));
    }
}
