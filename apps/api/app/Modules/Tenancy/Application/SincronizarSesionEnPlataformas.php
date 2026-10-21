<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Una sesión publicada en Wellhub o TotalPass cambió (una reserva, una cancelación, se
 * canceló o se movió): sus lugares se mandan al momento, sin esperar la vuelta de cada
 * cinco minutos (ADR 0202). Lo que pasa en la misma sesión en unos segundos se manda una
 * vez.
 */
class SincronizarSesionEnPlataformas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Segundos que agrupa los cambios de la misma sesión. */
    public int $uniqueFor = 15;

    public function __construct(
        public readonly int $estudioId,
        public readonly string $sesionUlid,
    ) {}

    public function uniqueId(): string
    {
        return $this->estudioId.':'.$this->sesionUlid;
    }

    public function handle(GestorDeConexionTenant $gestor, PublicarEnPlataformasTenant $publicar): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio || ! $gestor->baseDeDatosExiste($estudio)) {
            return;
        }
        $gestor->ejecutarEn($estudio, function () use ($publicar): void {
            $sesion = SesionTenant::query()->where('ulid', $this->sesionUlid)->first();
            if ($sesion instanceof SesionTenant) {
                $publicar->alCambiarSesion($sesion);
            }
        });
    }
}
