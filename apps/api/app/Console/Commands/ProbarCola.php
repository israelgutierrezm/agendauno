<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\PruebaCola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ¿La cola de esta versión procesa? (ADR 0135). Manda un trabajo de prueba a su propia
 * cola y lo procesa con un worker de un solo uso que corre aunque haya mantenimiento
 * (el de siempre no toma trabajos en mantenimiento): prueba la conexión, la
 * serialización y el código antes de abrir. Sale con error si no se procesó.
 */
class ProbarCola extends Command
{
    protected $signature = 'agendauno:probar-cola';

    protected $description = 'Procesa un trabajo de prueba en la cola (también en mantenimiento)';

    public function handle(): int
    {
        $marca = Str::random(24);
        PruebaCola::dispatch($marca)->onQueue(PruebaCola::COLA);

        $this->callSilently('queue:work', [
            '--queue' => PruebaCola::COLA,
            '--once' => true,
            '--force' => true,
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 60,
        ]);

        if (Cache::pull(PruebaCola::llave($marca)) !== 'ok') {
            $this->error('La cola no procesó el trabajo de prueba.');

            return self::FAILURE;
        }
        $this->info('La cola procesó el trabajo de prueba.');

        return self::SUCCESS;
    }
}
