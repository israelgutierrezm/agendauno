<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;

/**
 * El reporte interno por campaña del negocio (ADR 0144): en un periodo, por la campaña
 * que se acredita (ADR 0142), cuántas solicitudes, compradores nuevos, compras
 * confirmadas, devoluciones y primeras reservas hubo, y aparte las renovaciones. Es la
 * atribución propia del sistema, no la de Google ni la de Meta.
 *
 * - La campaña de cada cosa: la de su registro (la solicitud, la orden); si no tiene,
 *   la más reciente de la persona o la de su solicitud convertida. Sin ninguna, «sin
 *   atribución».
 * - Adquisición: compras que no son renovación. Comprador nuevo: su primera compra
 *   pagada cae en el periodo. Renovaciones: aparte, para no inflar la adquisición.
 * - Devoluciones: reembolsos aplicados en el periodo, por la campaña de su orden.
 * - No hay gasto publicitario capturado: no se calcula costo por resultado.
 * - Fechas en la zona del negocio; montos en unidades menores y en su moneda.
 */
class ReporteCampanasTenant
{
    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @return array{desde: string, hasta: string, moneda: string, filas: list<array<string, mixed>>}
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = $this->fechas->zona();
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();
        /** Por contexto (o «sin_atribucion»), cuánto de cada cosa. */
        $filas = [];
        $sumar = function (?int $contexto, string $columna, int $cuanto = 1) use (&$filas): void {
            $clave = $this->clave($contexto);
            $filas[$clave] ??= self::cuentas();
            $filas[$clave][$columna] += $cuanto;
        };

        // Solicitudes de sus formularios.
        ProspectoTenant::query()->where('origen', OrigenProspecto::Formulario->value)->whereBetween('created_at', [$inicio, $fin])
            ->get(['id', 'contexto_medicion_id'])
            ->each(fn (ProspectoTenant $p) => $sumar($p->contexto_medicion_id, 'solicitudes'));

        // Compras confirmadas (adquisición) y renovaciones, aparte.
        // Lo que se pagó en el periodo, aunque después se haya devuelto (la devolución
        // cuenta aparte, como en GA4: compra y luego devolución).
        $ordenes = OrdenTenant::query()->whereIn('estado', [EstadoOrden::Pagada->value, EstadoOrden::Cancelada->value])
            ->whereNotNull('pagada_en')->where('total_minor', '>', 0)
            ->whereBetween('pagada_en', [$inicio, $fin])
            ->get(['id', 'persona_id', 'contexto_medicion_id', 'total_minor', 'renueva_acuerdo_id', 'pagada_en']);
        $porPersona = $this->contextosPorPersona($ordenes->pluck('persona_id')->filter()->unique()->values()->all());
        $contextoDe = fn (OrdenTenant $o): ?int => $o->contexto_medicion_id ?? ($porPersona[$o->persona_id] ?? null);
        foreach ($ordenes as $orden) {
            if ($orden->renueva_acuerdo_id !== null) {
                $sumar($contextoDe($orden), 'renovaciones');
                $sumar($contextoDe($orden), 'renovaciones_minor', $orden->total_minor);

                continue;
            }
            $sumar($contextoDe($orden), 'compras');
            $sumar($contextoDe($orden), 'compras_minor', $orden->total_minor);
            $primera = ! OrdenTenant::query()->where('persona_id', $orden->persona_id)
                ->whereNotNull('pagada_en')->whereNull('renueva_acuerdo_id')->where('total_minor', '>', 0)
                ->where('id', '<', $orden->getKey())->exists();
            if ($primera) {
                $sumar($contextoDe($orden), 'nuevos_compradores');
            }
        }

        // Devoluciones aplicadas, por la campaña de su orden.
        ReembolsoTenant::query()->where('estado', EstadoReembolso::Aprobado->value)->whereBetween('aplicado_en', [$inicio, $fin])
            ->with('pago.orden')->get()
            ->each(function (ReembolsoTenant $r) use ($sumar, $porPersona): void {
                $orden = $r->pago?->orden;
                $contexto = $orden instanceof OrdenTenant
                    ? $orden->contexto_medicion_id ?? $porPersona[$orden->persona_id] ?? $this->contextosPorPersona([$orden->persona_id])[$orden->persona_id] ?? null
                    : null;
                $sumar($contexto, 'devoluciones');
                $sumar($contexto, 'devoluciones_minor', (int) $r->monto_minor);
            });

        // Primeras reservas confirmadas de cada persona.
        $reservas = ReservaTenant::query()->where('estado', EstadoReserva::Confirmada->value)->whereBetween('created_at', [$inicio, $fin])
            ->whereNotNull('persona_id')
            ->whereNotExists(fn ($q) => $q->from('reservas as previa')->whereColumn('previa.persona_id', 'reservas.persona_id')
                ->where('previa.estado', EstadoReserva::Confirmada->value)->whereColumn('previa.id', '<', 'reservas.id'))
            ->get(['id', 'persona_id']);
        $porPersonaReserva = $this->contextosPorPersona($reservas->pluck('persona_id')->unique()->values()->all());
        $reservas->each(fn (ReservaTenant $r) => $sumar($porPersonaReserva[$r->persona_id] ?? null, 'primeras_reservas'));

        $campanas = ContextoMedicionTenant::query()
            ->whereIn('id', collect(array_keys($filas))->filter(fn (int|string $c): bool => $c !== 'sin_atribucion')->map(fn (int|string $c): int => (int) $c))
            ->get(['id', 'fuente', 'medio', 'campana', 'referente'])->keyBy('id');

        // Las filas se agrupan por campaña (no por contexto): dos personas de la misma
        // campaña suman en la misma fila.
        $agrupadas = [];
        foreach ($filas as $claveContexto => $cuentas) {
            $c = $claveContexto === 'sin_atribucion' ? null : $campanas->get((int) $claveContexto);
            $campana = $c instanceof ContextoMedicionTenant && ($c->campana ?? $c->fuente ?? $c->referente) !== null
                ? ['campana' => $c->campana, 'fuente' => $c->fuente, 'medio' => $c->medio, 'referente' => $c->campana === null && $c->fuente === null ? $c->referente : null]
                : null;
            $clave = $campana === null ? 'sin_atribucion' : implode('|', array_map(fn (?string $v): string => $v ?? '', $campana));
            $agrupadas[$clave] ??= ['campana' => $campana, 'cuentas' => self::cuentas()];
            foreach ($cuentas as $columna => $valor) {
                $agrupadas[$clave]['cuentas'][$columna] += $valor;
            }
        }
        $lista = array_map(static fn (array $g): array => ['campana' => $g['campana'], ...$g['cuentas']], array_values($agrupadas));
        // Primero lo que más trajo; «sin atribución» al final.
        usort($lista, static fn (array $a, array $b): int => [$a['campana'] === null ? 1 : 0, -$a['compras'], -$a['solicitudes']]
            <=> [$b['campana'] === null ? 1 : 0, -$b['compras'], -$b['solicitudes']]);

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'moneda' => $this->parametros->moneda(),
            'filas' => $lista,
        ];
    }

    /**
     * @return array{solicitudes: int, nuevos_compradores: int, compras: int, compras_minor: int, devoluciones: int, devoluciones_minor: int, primeras_reservas: int, renovaciones: int, renovaciones_minor: int}
     */
    private static function cuentas(): array
    {
        return ['solicitudes' => 0, 'nuevos_compradores' => 0, 'compras' => 0, 'compras_minor' => 0, 'devoluciones' => 0,
            'devoluciones_minor' => 0, 'primeras_reservas' => 0, 'renovaciones' => 0, 'renovaciones_minor' => 0];
    }

    private function clave(?int $contexto): string
    {
        return $contexto !== null ? (string) $contexto : 'sin_atribucion';
    }

    /**
     * El contexto de cada persona: el suyo más reciente o el de su solicitud convertida.
     *
     * @param  array<int, mixed>  $personas
     * @return array<int, int>
     */
    private function contextosPorPersona(array $personas): array
    {
        if ($personas === []) {
            return [];
        }
        $deSolicitud = ProspectoTenant::query()->whereIn('persona_id', $personas)->whereNotNull('contexto_medicion_id')
            ->orderBy('id')->pluck('contexto_medicion_id', 'persona_id')->all();
        $propios = ContextoMedicionTenant::query()->whereIn('persona_id', $personas)
            ->orderBy('id')->pluck('id', 'persona_id')->all();

        return array_map('intval', $propios + $deSolicitud);
    }
}
