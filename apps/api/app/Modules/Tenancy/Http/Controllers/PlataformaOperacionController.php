<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Platform\Operacion\VerificacionProduccion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * El estado de la operación para el superadmin, lo mismo que dicen la consola y los
 * correos: versión en marcha, programador y cola, la verificación de producción
 * completa, los últimos respaldos, el detalle del último simulacro y las alertas
 * recientes. Solo lee.
 */
class PlataformaOperacionController
{
    /** Alertas más recientes que se muestran (el historial guarda 30 días). */
    private const ALERTAS = 50;

    public function __invoke(
        VerificacionProduccion $verificacion,
        LatidoOperacion $latido,
        RespaldosPlataforma $respaldos,
    ): JsonResponse {
        $proceso = fn (string $nombre): array => [
            'estado' => $latido->enMarcha($nombre),
            'ultimo' => $latido->ultimo($nombre)?->toIso8601String(),
        ];
        $fecha = function (string $sub) use ($respaldos): ?string {
            try {
                return $respaldos->fechaUltimo($sub)?->toIso8601String();
            } catch (Throwable) {
                return null; // Disco de respaldos inalcanzable: lo dice la verificación.
            }
        };

        return response()->json(['data' => [
            'version' => $latido->version(),
            'entorno' => app()->environment(),
            'mantenimiento' => app()->isDownForMaintenance(),
            'procesos' => [
                'programador' => $proceso(LatidoOperacion::PROGRAMADOR),
                'cola' => $proceso(LatidoOperacion::COLA),
            ],
            'verificacion' => $verificacion->revisar(),
            'respaldos' => [
                'plataforma' => $fecha(RespaldosPlataforma::PLATAFORMA),
                'archivos' => $fecha(RespaldosPlataforma::ARCHIVOS),
                'simulacro' => $respaldos->detalleUltimoSimulacro(),
            ],
            'alertas' => AlertaPlataforma::query()
                ->orderByDesc('ultima_en')
                ->limit(self::ALERTAS)
                ->get()
                ->map(fn (AlertaPlataforma $a): array => [
                    'tipo' => $a->tipo,
                    'clave' => $a->clave,
                    'estudio' => $a->estudio,
                    'mensaje' => $a->mensaje,
                    'veces' => $a->veces,
                    'primera_en' => $a->primera_en->toIso8601String(),
                    'ultima_en' => $a->ultima_en->toIso8601String(),
                    'avisada' => ! $a->pendiente,
                ])->all(),
            'revisado_en' => CarbonImmutable::now()->toIso8601String(),
        ]]);
    }
}
