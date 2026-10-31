<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Un trabajo de hasta 30 minutos va en la cola `pesados`, con su worker (ADR 0232). En
 * Redis, además, con su conexión (`retry_after` mayor que su duración). Con otra cola
 * (sync en pruebas, database en desarrollo) se queda en la de la instalación.
 */
trait EnColaPesada
{
    private function aColaPesada(): void
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-pesados');
        }
        $this->onQueue('pesados');
    }
}
