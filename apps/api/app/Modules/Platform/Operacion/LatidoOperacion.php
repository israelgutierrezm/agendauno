<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Latidos de los procesos de fondo: prueban que el programador de tareas y la cola
 * siguen vivos. Cada minuto el programador deja su marca y encola un trabajo
 * mínimo que deja la de la cola; si alguna se queda atrás, el proceso está caído o
 * atorado (lo ven el chequeo de salud, el de Docker y la verificación de producción).
 *
 * Vive en la caché (Redis en producción), compartida por todos los contenedores.
 */
class LatidoOperacion
{
    public const PROGRAMADOR = 'programador';

    public const COLA = 'cola';

    /** Más de esto sin latido = atrasado. */
    public const MINUTOS_TOLERANCIA = 5;

    public function marcar(string $proceso): void
    {
        Cache::put($this->llave($proceso), CarbonImmutable::now()->toIso8601String(), now()->addDay());
    }

    public function ultimo(string $proceso): ?CarbonImmutable
    {
        $valor = Cache::get($this->llave($proceso));

        return is_string($valor) ? CarbonImmutable::parse($valor) : null;
    }

    /**
     * ok | atrasado | sin_datos
     */
    public function estado(string $proceso, int $minutos = self::MINUTOS_TOLERANCIA): string
    {
        $ultimo = $this->ultimo($proceso);
        if ($ultimo === null) {
            return 'sin_datos';
        }

        return $ultimo->greaterThanOrEqualTo(CarbonImmutable::now()->subMinutes($minutos)) ? 'ok' : 'atrasado';
    }

    private function llave(string $proceso): string
    {
        return "operacion:latido:{$proceso}";
    }
}
