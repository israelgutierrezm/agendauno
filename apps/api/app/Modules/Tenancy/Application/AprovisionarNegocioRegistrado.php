<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La base de un negocio recién registrado, en la cola (ADR 0222): en producción son
 * cientos de migraciones en MySQL, que no caben en la petición del registro. Al terminar
 * sale el correo de activación. Si falla se reintenta; agotados los intentos, el negocio
 * queda en `provisioning` y el superadmin lo reintenta desde su ficha. Uno a la vez por
 * negocio; con uno ya aprovisionado no hace nada.
 */
class AprovisionarNegocioRegistrado implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(public readonly int $estudioId) {}

    public function uniqueId(): string
    {
        return (string) $this->estudioId;
    }

    public function handle(AprovisionarEstudio $aprovisionar, EnviarActivacionTenant $activacion): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio || $estudio->estado->operativo()) {
            return;
        }
        $aprovisionar->ejecutar($estudio);
        $activacion->enviar($estudio, (string) $estudio->contacto_email);
    }

    /** Agotados los intentos: una alerta que pide atención (se reintenta desde la ficha). */
    public function failed(Throwable $e): void
    {
        Log::error('No se pudo crear la base de un negocio registrado.', ['estudio_id' => $this->estudioId, 'error' => $e->getMessage()]);
        $slug = Estudio::query()->whereKey($this->estudioId)->value('slug');
        app(AlertasPlataforma::class)->registrar('aprovisionamiento_fallido', 'estudio:'.$this->estudioId,
            'No se pudo crear la base del negocio '.($slug ?? $this->estudioId).': '.$e->getMessage().' Reintenta desde su ficha.', is_string($slug) ? $slug : null);
    }
}
