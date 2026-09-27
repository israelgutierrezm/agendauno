<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Platform\Operacion\LatidoOperacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness/readiness probe for /api/v1/health.
 *
 * `database` and `cache` are required for the app to function and drive the
 * overall status. `redis` is reported for visibility but is optional in local
 * development until the Docker Redis service is started, so it does not fail
 * the endpoint on its own. `programador` and `cola` report the background
 * processes' heartbeat (ok | atrasado | sin_datos), also informational.
 */
class HealthController
{
    /** @var list<string> */
    private array $critical = ['database', 'cache'];

    public function __invoke(LatidoOperacion $latido): JsonResponse
    {
        $checks = [
            'database' => $this->probe(function (): bool {
                DB::connection()->getPdo();

                return true;
            }),
            'cache' => $this->probe(function (): bool {
                Cache::put('health:ping', '1', 5);

                return Cache::get('health:ping') === '1';
            }),
            'redis' => $this->probe(fn (): bool => (bool) Redis::connection()->ping()),
            'programador' => ['status' => $this->latido($latido, LatidoOperacion::PROGRAMADOR)],
            'cola' => ['status' => $this->latido($latido, LatidoOperacion::COLA)],
        ];

        $healthy = collect($checks)
            ->only($this->critical)
            ->every(fn (array $check): bool => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'version' => config('app.version', '0.1.0'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function latido(LatidoOperacion $latido, string $proceso): string
    {
        try {
            return $latido->estado($proceso);
        } catch (Throwable) {
            return 'sin_datos';
        }
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function probe(callable $check): array
    {
        try {
            return ['status' => $check() ? 'ok' : 'down'];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => class_basename($e)];
        }
    }
}
