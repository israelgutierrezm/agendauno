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
 * completa, los últimos respaldos (con su estado según esa misma verificación), el
 * detalle del último simulacro y las alertas recientes con los conteos reales del
 * historial. Solo lee.
 */
class PlataformaOperacionController
{
    /** Alertas más recientes que se muestran (el historial guarda 30 días). */
    private const ALERTAS = 50;

    /** La sección de los respaldos en {@see VerificacionProduccion} (la prueba lo cuida). */
    private const SECCION_RESPALDOS = 'Respaldos';

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
        $puntos = $verificacion->revisar();

        return response()->json(['data' => [
            'version' => $latido->version(),
            'entorno' => app()->environment(),
            'mantenimiento' => app()->isDownForMaintenance(),
            'procesos' => [
                'programador' => $proceso(LatidoOperacion::PROGRAMADOR),
                'cola' => $proceso(LatidoOperacion::COLA),
            ],
            'verificacion' => $puntos,
            'respaldos' => [
                // El mismo juicio que la verificación (antigüedad de cada respaldo,
                // simulacro vigente, copias fuera, cifrado, cada negocio), no solo «hay
                // o no hay»: el panel no repite umbrales ni lee el nombre de la sección.
                'estado' => self::peorEstado($puntos, self::SECCION_RESPALDOS),
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
            // La lista llega recortada a las más recientes; estos son los conteos de
            // todo el historial (30 días; lo que falta avisar se queda hasta avisarse).
            'alertas_total' => AlertaPlataforma::query()->count(),
            'alertas_por_avisar' => AlertaPlataforma::query()->where('pendiente', true)->count(),
            'revisado_en' => CarbonImmutable::now()->toIso8601String(),
        ]]);
    }

    /**
     * El peor estado de una sección de la verificación: `falta` si algún punto falta,
     * `aviso` si alguno avisa, `ok` si todo está en orden.
     *
     * @param  list<array{seccion: string, punto: string, estado: string, detalle: string}>  $puntos
     */
    private static function peorEstado(array $puntos, string $seccion): string
    {
        $estados = array_column(array_filter($puntos, fn (array $p): bool => $p['seccion'] === $seccion), 'estado');

        return in_array('falta', $estados, true) ? 'falta' : (in_array('aviso', $estados, true) ? 'aviso' : 'ok');
    }
}
