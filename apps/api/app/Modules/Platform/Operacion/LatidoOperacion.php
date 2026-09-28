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
 * En mantenimiento (al publicar una versión) el programador sigue latiendo, pero la
 * cola no toma trabajos, ni siquiera el del latido. Para saber que la cola nueva está
 * en marcha sin abrirla, el worker deja al arrancar su marca de arranque. Cada marca
 * lleva la versión del proceso que la dejó: solo cuentan las de la versión en marcha.
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
        $this->guardar($this->llave($proceso));
    }

    /** El worker arrancó (aunque en mantenimiento no tome trabajos). */
    public function marcarArranque(string $proceso): void
    {
        $this->guardar($this->llaveArranque($proceso));
    }

    public function ultimo(string $proceso): ?CarbonImmutable
    {
        return $this->leer($this->llave($proceso))['en'];
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

    /**
     * ¿El proceso de ESTA versión está en marcha? ok | atrasado | sin_datos | otra_version.
     *
     * - Programador: latió hace poco, con esta versión (también late en mantenimiento).
     * - Cola abierta: procesó hace poco el trabajo del latido, con esta versión.
     * - Cola en mantenimiento: su worker arrancó con esta versión (no toma trabajos
     *   hasta abrir; no se reactiva la cola para comprobarlo).
     */
    public function enMarcha(string $proceso, int $minutos = self::MINUTOS_TOLERANCIA): string
    {
        if ($proceso === self::COLA && app()->isDownForMaintenance()) {
            $arranque = $this->leer($this->llaveArranque($proceso));
            if ($arranque['en'] === null) {
                return 'sin_datos';
            }

            return $this->mismaVersion($arranque['version']) ? 'ok' : 'otra_version';
        }

        $estado = $this->estado($proceso, $minutos);
        if ($estado !== 'ok') {
            return $estado;
        }

        return $this->mismaVersion($this->leer($this->llave($proceso))['version']) ? 'ok' : 'otra_version';
    }

    public function version(): string
    {
        return (string) config('app.version');
    }

    private function guardar(string $llave): void
    {
        Cache::put($llave, ['en' => CarbonImmutable::now()->toIso8601String(), 'version' => $this->version()], now()->addDay());
    }

    /**
     * @return array{en: CarbonImmutable|null, version: string|null}
     */
    private function leer(string $llave): array
    {
        $valor = Cache::get($llave);
        // Antes de llevar versión, la marca era solo la fecha.
        if (is_string($valor)) {
            return ['en' => CarbonImmutable::parse($valor), 'version' => null];
        }
        if (is_array($valor) && is_string($valor['en'] ?? null)) {
            return [
                'en' => CarbonImmutable::parse($valor['en']),
                'version' => is_string($valor['version'] ?? null) ? $valor['version'] : null,
            ];
        }

        return ['en' => null, 'version' => null];
    }

    /** Una marca sin versión (anterior a este cambio) no se descarta. */
    private function mismaVersion(?string $version): bool
    {
        return $version === null || $version === $this->version();
    }

    private function llave(string $proceso): string
    {
        return "operacion:latido:{$proceso}";
    }

    private function llaveArranque(string $proceso): string
    {
        return "operacion:arranque:{$proceso}";
    }
}
