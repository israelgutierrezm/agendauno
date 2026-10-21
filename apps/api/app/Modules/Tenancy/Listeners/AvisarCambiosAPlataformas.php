<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\SincronizarSesionEnPlataformas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;

/**
 * Una reserva directa ocupa o libera un lugar de una clase publicada en Wellhub o
 * TotalPass: la plataforma lo sabe al momento (Wellhub pide actualizar sus lugares en
 * cada reserva, sea suya o no; ADR 0202). Solo las sesiones que están publicadas.
 */
class AvisarCambiosAPlataformas
{
    /** Los eventos que cambian los lugares de una sesión. */
    private const EVENTOS = [
        'reserva.creada', 'reserva.confirmada', 'reserva.ofrecida', 'reserva.cancelada',
        'reserva.sesion_cancelada', 'reserva.reprogramada',
    ];

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        $sesion = $evento->payload['sesion_id'] ?? null;
        $estudio = $this->gestor->actual();
        if (! in_array($evento->tipo, self::EVENTOS, true) || ! is_string($sesion) || $estudio === null) {
            return;
        }
        $publicada = HorarioPlataformaTenant::query()
            ->where('estado', '!=', HorarioPlataformaTenant::RETIRADO)
            ->whereIn('sesion_id', SesionTenant::query()->select('id')->where('ulid', $sesion))
            ->exists();
        if ($publicada) {
            SincronizarSesionEnPlataformas::dispatch((int) $estudio->getKey(), $sesion)->delay(now()->addSeconds(5));
        }
    }
}
