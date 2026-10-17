<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * El embudo del negocio (plan de mejoras C08): de la visita a su página hasta que su
 * cliente renueva, con la cantidad y su denominador (nunca un porcentaje sin contexto).
 * Todo sale de los registros del servidor; nada de datos personales.
 *
 * - **Sitio → solicitud:** solicitudes llegadas por la página o el widget / visitas
 *   medidas. Solo eso es comparable: las de un enlace o QR, un formulario incrustado o
 *   las que registra el equipo no pasan por una visita medida y van aparte (`otras`).
 * - **Solicitud → primera compra:** de esas solicitudes, cuántas se volvieron clientes con
 *   un pago confirmado.
 * - **Compra → primera asistencia:** de quienes compraron por primera vez en el periodo,
 *   cuántos ya asistieron.
 * - **Renovación:** de las renovaciones que tocaron en el periodo, cuántas se pagaron.
 * - **Tiempo de respuesta:** de la solicitud al primer contacto registrado (mediana en
 *   horas) y cuántas siguen sin atender.
 *
 * Fechas en la zona del negocio; un pago cuenta una vez (por orden pagada).
 */
class EmbudoNegocioTenant
{
    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly VisitasPaginaTenant $visitas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = $this->fechas->zona();
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        $solicitudes = ProspectoTenant::query()
            ->where('origen', OrigenProspecto::Formulario->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->get(['id', 'persona_id', 'convertido_en', 'created_at', 'primer_contacto_en', 'canal']);
        $porCanal = $solicitudes->countBy(static fn (ProspectoTenant $p): string => (string) ($p->canal ?? ''));
        $conVisita = array_sum(array_map(static fn (string $c): int => (int) $porCanal->get($c, 0), CaptacionTenant::CANALES_CON_VISITA));

        // De las solicitudes, quienes ya son clientes con un pago confirmado después.
        $compraron = $solicitudes->filter(fn (ProspectoTenant $p): bool => $p->persona_id !== null
            && OrdenTenant::query()
                ->where('persona_id', $p->persona_id)
                ->where('estado', EstadoOrden::Pagada->value)
                ->where('pagada_en', '>=', $p->created_at)
                ->exists())->count();

        // Tiempo de respuesta: horas de la solicitud al primer contacto.
        $horas = $solicitudes
            ->filter(fn (ProspectoTenant $p): bool => $p->primer_contacto_en !== null)
            ->map(fn (ProspectoTenant $p): float => round(abs((float) $p->created_at->diffInMinutes($p->primer_contacto_en)) / 60, 1))
            ->sort()
            ->values();

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'sitio_a_solicitud' => [
                'visitas' => $this->visitas->entre($desde, $hasta),
                'solicitudes' => $conVisita,
                // Sin comparar con las visitas: no pasan por una visita medida.
                'otras' => [
                    'enlace' => (int) $porCanal->get('enlace', 0),
                    'embebido' => (int) $porCanal->get('embebido', 0),
                    'sin_canal' => (int) $porCanal->get('', 0),
                    'equipo' => ProspectoTenant::query()
                        ->where('origen', OrigenProspecto::Manual->value)
                        ->whereBetween('created_at', [$inicio, $fin])
                        ->count(),
                ],
            ],
            'solicitud_a_compra' => [
                'solicitudes' => $solicitudes->count(),
                'compraron' => $compraron,
            ],
            'compra_a_asistencia' => $this->compraAAsistencia($inicio, $fin),
            'renovacion' => [
                'por_renovar' => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->whereBetween('created_at', [$inicio, $fin])->count(),
                'renovadas' => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->whereBetween('created_at', [$inicio, $fin])
                    ->where('estado', EstadoOrden::Pagada->value)->count(),
            ],
            'tiempo_respuesta' => [
                'solicitudes' => $solicitudes->count(),
                'atendidas' => $horas->count(),
                'mediana_horas' => self::mediana($horas->all()),
            ],
        ];
    }

    /**
     * Quienes compraron un plan por primera vez en el periodo y cuántos ya asistieron.
     *
     * @return array{compradores_nuevos: int, asistieron: int}
     */
    private function compraAAsistencia(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        // La primera compra pagada de un plan (no cuotas ni citas) de cada persona.
        $primeras = LineaOrdenTenant::query()
            ->join('ordenes', 'ordenes.id', '=', 'lineas_orden.orden_id')
            ->where('ordenes.estado', EstadoOrden::Pagada->value)
            ->whereNotNull('lineas_orden.producto_comercial_id')
            ->whereNull('ordenes.renueva_acuerdo_id')
            ->selectRaw('COALESCE(lineas_orden.beneficiario_id, ordenes.persona_id) as pid, MIN(ordenes.pagada_en) as primera')
            ->groupByRaw('COALESCE(lineas_orden.beneficiario_id, ordenes.persona_id)')
            ->get()
            ->filter(fn ($f): bool => CarbonImmutable::parse((string) $f->getAttribute('primera'))->betweenIncluded($inicio, $fin));

        $asistieron = $primeras->filter(fn ($f): bool => ReservaTenant::query()
            ->where('persona_id', (int) $f->getAttribute('pid'))
            ->whereHas('asistencia', fn (Builder $q) => $q->where('estado', EstadoAsistencia::Presente->value)
                ->where('registrada_en', '>=', (string) $f->getAttribute('primera')))
            ->exists())->count();

        return ['compradores_nuevos' => $primeras->count(), 'asistieron' => $asistieron];
    }

    /**
     * @param  list<float>  $valores  ordenados
     */
    private static function mediana(array $valores): ?float
    {
        $n = count($valores);
        if ($n === 0) {
            return null;
        }
        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? $valores[$medio] : round(($valores[$medio - 1] + $valores[$medio]) / 2, 1);
    }
}
