<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Calendarios;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La pasada de una conexión cuando Google o Microsoft avisan que cambió su calendario
 * (ADR 0199). Lleva el negocio: corre en su base. Una sola en espera por conexión:
 * varios avisos seguidos (Google avisa de cada evento) hacen una pasada.
 */
class SincronizarCalendarioExterno implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Segundos que agrupa los avisos de la misma conexión. */
    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $estudioId,
        public readonly int $calendarioId,
    ) {}

    public function uniqueId(): string
    {
        return $this->estudioId.':'.$this->calendarioId;
    }

    public function handle(GestorDeConexionTenant $gestor, CalendariosExternosTenant $calendarios): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio) {
            return;
        }
        $gestor->ejecutarEn($estudio, function () use ($calendarios): void {
            $calendario = CalendarioExternoTenant::query()->where('estado', CalendariosExternosTenant::ACTIVO)->find($this->calendarioId);
            if ($calendario instanceof CalendarioExternoTenant) {
                $calendarios->sincronizar($calendario);
            }
        });
    }
}
