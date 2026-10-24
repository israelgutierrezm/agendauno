<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Una pasada del envío de mensajes de un negocio, en la cola (ADR 0205). Los avisos
 * (operación, recordatorios) van en la cola `mensajes` y las campañas en `campanas`:
 * el trabajador atiende primero `default`, luego `mensajes` y al final `campanas`, así
 * que una campaña grande no retrasa un recordatorio ni las tareas del programador.
 *
 * Cada pasada tiene un tope de mensajes y de segundos ({@see EnviarMensajesTenant}): lo
 * que no alcanza sale en la siguiente (el programador encola una por minuto). Una sola
 * a la vez por negocio y tipo. No se reintenta el trabajo: cada mensaje lleva sus
 * propios intentos.
 */
class EnviarMensajesDelNegocio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Menos que el `retry_after` de la cola (90 s): no se toma dos veces. */
    public int $timeout = 75;

    public bool $failOnTimeout = true;

    /** Si una pasada se cae sin soltar el candado, a los 5 minutos se libera. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $estudioId,
        public readonly string $tipo,
    ) {
        $this->onQueue($tipo === ColaDeMensajes::CAMPANAS ? 'campanas' : 'mensajes');
    }

    public function uniqueId(): string
    {
        return $this->estudioId.':'.$this->tipo;
    }

    public function handle(GestorDeConexionTenant $gestor, EnviarMensajesTenant $relay, ColaDeMensajes $cola): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio || ! $gestor->baseDeDatosExiste($estudio)) {
            return;
        }
        $gestor->ejecutarEn($estudio, function () use ($relay, $cola, $estudio): void {
            $relay->ejecutar($this->tipo);
            $cola->anotar($estudio, $this->tipo);
        });
    }
}
