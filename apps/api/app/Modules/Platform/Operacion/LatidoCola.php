<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trabajo mínimo que encola el programador cada minuto: si un worker lo procesa,
 * la cola está viva. No reintenta (el siguiente minuto trae otro).
 */
class LatidoCola implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(LatidoOperacion $latido): void
    {
        $latido->marcar(LatidoOperacion::COLA);
    }
}
