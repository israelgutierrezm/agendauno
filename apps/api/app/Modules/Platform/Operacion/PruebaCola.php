<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Trabajo de prueba del despliegue (ADR 0135): si se procesa, deja su marca. Va a su
 * propia cola (`despliegue`), así no se mezcla con el trabajo del negocio.
 */
class PruebaCola implements ShouldQueue
{
    use Queueable;

    public const COLA = 'despliegue';

    public int $tries = 1;

    public function __construct(public readonly string $marca) {}

    public static function llave(string $marca): string
    {
        return "operacion:prueba-cola:{$marca}";
    }

    public function handle(): void
    {
        Cache::put(self::llave($this->marca), 'ok', 600);
    }
}
