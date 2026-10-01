<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Contesta a quien le escribió al número de AgendaUno (ADR 0083), fuera del webhook
 * para responderle a Meta al momento. Reintenta un par de veces si Meta falla; se
 * contesta dentro de las 24 horas del mensaje, así que no tiene caso insistir más.
 */
class ResponderWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(
        public readonly string $telefono,
        public readonly bool $baja,
    ) {}

    public function handle(RespuestasWhatsApp $respuestas): void
    {
        $respuestas->responder($this->telefono, $this->baja);
    }
}
