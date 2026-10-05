<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Carbon\CarbonImmutable;

/**
 * Rentabilidad por clase (R30): por cada oferta impartida en un periodo, cruza el
 * INGRESO (asistentes × precio de clase si el estudio lo configuró; si no, aproximado
 * por los créditos consumidos × precio por crédito del pack) contra el COSTO de quien
 * la trabajó (nómina por clase/asistente/hora, R17; incluye al profesional de la
 * sesión, ADR 0081). El margen es ingreso − costo. `sin_costo_unitario` cuenta a los
 * asistentes sin valor por crédito (membresías ilimitadas o cortesías) para no engañar
 * con el número. El valor de cada sesión sale de {@see ValorDeSesionesTenant}. Montos
 * en minor (entero).
 */
class CalcularRentabilidadTenant
{
    public function __construct(private readonly ValorDeSesionesTenant $valor) {}

    /**
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicio = CarbonImmutable::parse($desde.' 00:00:00', $zona)->utc();
        $fin = CarbonImmutable::parse($hasta.' 00:00:00', $zona)->addDay()->utc();
        $moneda = (string) (SucursalTenant::query()->value('moneda') ?? app(ParametrosTenant::class)->moneda());

        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->with('oferta')
            ->get();
        $valores = $this->valor->calcular($sesiones);

        /** @var array<string, array<string, mixed>> $ofertas */
        $ofertas = [];

        foreach ($sesiones as $sesion) {
            $oferta = $sesion->oferta;
            $clave = $oferta->ulid;
            $valor = $valores[(int) $sesion->id];

            if (! isset($ofertas[$clave])) {
                $ofertas[$clave] = [
                    'id' => $oferta->ulid,
                    'oferta' => $oferta->nombre,
                    'precio_clase_minor' => $oferta->precio_clase_minor,
                    'sesiones' => 0, 'asistentes' => 0,
                    'ingreso_minor' => 0, 'costo_minor' => 0, 'sin_costo_unitario' => 0,
                ];
            }
            $ofertas[$clave]['sesiones']++;
            $ofertas[$clave]['asistentes'] += $valor['presentes'];
            $ofertas[$clave]['ingreso_minor'] += $valor['ingreso'];
            $ofertas[$clave]['costo_minor'] += array_sum($valor['pagos']);
            $ofertas[$clave]['sin_costo_unitario'] += $valor['sin_costo'];
        }

        $lista = array_map(static function (array $o): array {
            $o['margen_minor'] = $o['ingreso_minor'] - $o['costo_minor'];

            return $o;
        }, array_values($ofertas));
        // Menos rentables primero (accionable).
        usort($lista, static fn (array $a, array $b): int => $a['margen_minor'] <=> $b['margen_minor']);

        $totales = ['sesiones' => 0, 'asistentes' => 0, 'ingreso_minor' => 0, 'costo_minor' => 0, 'sin_costo_unitario' => 0];
        foreach ($lista as $o) {
            $totales['sesiones'] += $o['sesiones'];
            $totales['asistentes'] += $o['asistentes'];
            $totales['ingreso_minor'] += $o['ingreso_minor'];
            $totales['costo_minor'] += $o['costo_minor'];
            $totales['sin_costo_unitario'] += $o['sin_costo_unitario'];
        }
        $totales['margen_minor'] = $totales['ingreso_minor'] - $totales['costo_minor'];

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'moneda' => $moneda,
            'totales' => $totales,
            'ofertas' => $lista,
        ];
    }
}
