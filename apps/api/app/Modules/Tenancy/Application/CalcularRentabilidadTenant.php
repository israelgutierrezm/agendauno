<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Nomina\TipoPago;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rentabilidad por clase (R30): por cada oferta impartida en un periodo, cruza el
 * INGRESO (asistentes × precio de clase si el estudio lo configuró; si no, aproximado
 * por los créditos consumidos × precio por crédito del pack) contra el COSTO del
 * instructor (nómina por clase/asistente/hora, R17). El margen es ingreso − costo.
 * `sin_costo_unitario` cuenta a los asistentes sin valor por crédito (membresías
 * ilimitadas o cortesías) para no engañar con el número. Montos en minor (entero).
 */
class CalcularRentabilidadTenant
{
    /**
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicio = CarbonImmutable::parse($desde.' 00:00:00', $zona)->utc();
        $fin = CarbonImmutable::parse($hasta.' 00:00:00', $zona)->addDay()->utc();
        $moneda = (string) (SucursalTenant::query()->value('moneda') ?? 'MXN');

        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->with('oferta')
            ->get();
        $sesionIds = $sesiones->pluck('id')->all();

        // Esquemas de pago activos por usuario (para el costo del instructor).
        $esquemas = EsquemaPagoTenant::query()->where('activo', true)->get()->keyBy('usuario_id');
        // Asignaciones de staff por sesión.
        $asignaciones = AsignacionSesionTenant::query()->whereIn('sesion_id', $sesionIds)->get()->groupBy('sesion_id');
        // Reservas ASISTIDAS (presente) con la cadena de producto para el ingreso por créditos.
        $presentesPorSesion = ReservaTenant::query()
            ->whereIn('sesion_id', $sesionIds)
            ->whereHas('asistencia', fn ($q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->with('derecho.acuerdo.producto')
            ->get()
            ->groupBy('sesion_id');

        /** @var array<string, array<string, mixed>> $ofertas */
        $ofertas = [];

        foreach ($sesiones as $sesion) {
            $oferta = $sesion->oferta;
            $clave = $oferta->ulid;
            /** @var Collection<int, ReservaTenant> $presentes */
            $presentes = $presentesPorSesion->get($sesion->id) ?? collect();
            $numPresentes = $presentes->count();

            [$ingreso, $sinCosto] = $this->ingresoDe($oferta->precio_clase_minor, $numPresentes, $presentes);
            $costo = $this->costoDe($sesion, $numPresentes, $asignaciones->get($sesion->id) ?? collect(), $esquemas);

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
            $ofertas[$clave]['asistentes'] += $numPresentes;
            $ofertas[$clave]['ingreso_minor'] += $ingreso;
            $ofertas[$clave]['costo_minor'] += $costo;
            $ofertas[$clave]['sin_costo_unitario'] += $sinCosto;
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

    /**
     * Ingreso de una sesión y cuántos asistentes quedaron sin valor unitario.
     *
     * @param  Collection<int, ReservaTenant>  $presentes
     * @return array{0: int, 1: int} [ingreso_minor, sin_costo_unitario]
     */
    private function ingresoDe(?int $precioClase, int $numPresentes, Collection $presentes): array
    {
        if ($precioClase !== null && $precioClase > 0) {
            return [$numPresentes * $precioClase, 0];
        }

        $ingreso = 0;
        $sinCosto = 0;
        foreach ($presentes as $reserva) {
            $producto = $reserva->derecho?->acuerdo?->producto;
            $precioMinor = (int) ($producto->precio_minor ?? 0);
            $creditos = (int) ($producto->creditos_incluidos ?? 0);
            if ($precioMinor > 0 && $creditos > 0) {
                $ingreso += intdiv($precioMinor * (int) $reserva->costo_unidades, $creditos);
            } else {
                $sinCosto++;
            }
        }

        return [$ingreso, $sinCosto];
    }

    /**
     * Costo del instructor de una sesión (suma de los esquemas de pago de su staff).
     *
     * @param  Collection<int, AsignacionSesionTenant>  $asignaciones
     * @param  Collection<int, EsquemaPagoTenant>  $esquemas  keyBy usuario_id
     */
    private function costoDe(SesionTenant $sesion, int $numPresentes, Collection $asignaciones, Collection $esquemas): int
    {
        $costo = 0;
        foreach ($asignaciones as $asignacion) {
            $esquema = $esquemas->get($asignacion->usuario_id);
            if (! $esquema instanceof EsquemaPagoTenant) {
                continue;
            }
            $monto = (int) $esquema->monto_minor;
            $costo += match ($esquema->tipo) {
                TipoPago::PorClase => $monto,
                TipoPago::PorAsistente => $monto * $numPresentes,
                TipoPago::PorHora => intdiv($monto * (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en), 60),
            };
        }

        return $costo;
    }
}
