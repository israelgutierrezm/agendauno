<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\ReglaCapacidadCanalTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\CanalReserva;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * La única regla de cupo de una clase (plan de mejoras, B02): qué reservas ocupan un
 * lugar y cuántos quedan para un canal. La usan el motor de reservas (que además bloquea
 * la sesión y revalida al confirmar), la página pública, la agenda del alumno y la del
 * equipo: todos dicen lo mismo de la misma sesión en el mismo instante.
 *
 * - Ocupan lugar: confirmadas, ofrecidas (la oferta de la lista de espera aparta el lugar
 *   mientras decide) y pendientes de pago (lo retienen mientras se paga, hasta que el
 *   proceso de cada minuto las cancela).
 * - También quienes vienen por Wellhub o TotalPass (ADR 0148): sus lugares de plataforma
 *   reservados o con asistencia ocupan cupo sin ser reservas; cuentan en el canal de su
 *   plataforma.
 * - Cada regla de capacidad por canal (R20) aparta sus cupos sin usar para su canal hasta
 *   `liberar_horas_antes` del inicio; para los demás canales esos lugares no están.
 * - Sin capacidad, la clase no tiene tope (`null`).
 */
class CupoDeSesionTenant
{
    /**
     * Estados de reserva que ocupan un lugar en la sesión.
     *
     * @var list<string>
     */
    public const OCUPAN_LUGAR = [
        EstadoReserva::Confirmada->value,
        EstadoReserva::Ofrecida->value,
        EstadoReserva::PendientePago->value,
    ];

    /** Atributo con que {@see cargar()} deja en cada sesión los lugares apartados para otros canales. */
    public const ATRIBUTO_APARTADOS = 'apartados_otros_canales';

    /** Atributo de {@see conteos()} con los lugares que ocupan las plataformas (ADR 0148). */
    public const ATRIBUTO_PLATAFORMA = 'ocupados_plataforma';

    /**
     * Los conteos de una lista de sesiones (para `withCount` o `loadCount`): cuántas
     * reservas ocupan lugar, cuántos lugares ocupan las plataformas y cuántas esperan.
     * Lo ocupado en total lo da {@see ocupadosCargados()}.
     *
     * @return array<string, Closure(Builder<ReservaTenant>|Builder<LugarPlataformaTenant>): void>
     */
    public static function conteos(): array
    {
        return [
            'reservas as ocupados' => static function (Builder $q): void {
                $q->whereIn('estado', self::OCUPAN_LUGAR);
            },
            'lugaresPlataforma as '.self::ATRIBUTO_PLATAFORMA => static function (Builder $q): void {
                $q->whereIn('estado', LugarPlataformaTenant::OCUPAN_LUGAR);
            },
            'reservas as en_espera' => static function (Builder $q): void {
                $q->where('estado', EstadoReserva::EnEspera->value);
            },
        ];
    }

    /**
     * Lugares ocupados de una sesión ya cargada con {@see conteos()}: reservas más
     * plataformas. Lo que no venga cargado se consulta.
     */
    public static function ocupadosCargados(SesionTenant $sesion): int
    {
        $atributos = $sesion->getAttributes();
        if (! array_key_exists('ocupados', $atributos)) {
            return app(self::class)->ocupadas($sesion);
        }
        $plataforma = array_key_exists(self::ATRIBUTO_PLATAFORMA, $atributos)
            ? (int) $atributos[self::ATRIBUTO_PLATAFORMA]
            : self::ocupadosDePlataforma($sesion);

        return (int) $atributos['ocupados'] + $plataforma;
    }

    /** Lugares que ocupan Wellhub y TotalPass en la sesión. */
    public static function ocupadosDePlataforma(SesionTenant $sesion): int
    {
        return LugarPlataformaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->whereIn('estado', LugarPlataformaTenant::OCUPAN_LUGAR)
            ->count();
    }

    /**
     * Lugares que el canal directo aún puede reservar en la sesión, con lo que la lista
     * ya trajo (`ocupados` de {@see conteos()} y lo apartado de {@see cargar()}); lo que
     * falte se consulta. Null si la clase no tiene tope.
     */
    public function libres(SesionTenant $sesion): ?int
    {
        if ($sesion->capacidad === null) {
            return null;
        }
        $atributos = $sesion->getAttributes();
        $ocupados = self::ocupadosCargados($sesion);
        if (! array_key_exists(self::ATRIBUTO_APARTADOS, $atributos)) {
            $this->cargar([$sesion]);
        }

        return max(0, (int) $sesion->capacidad - $ocupados - (int) $sesion->getAttribute(self::ATRIBUTO_APARTADOS));
    }

    /** Reservas y lugares de plataforma que ocupan un lugar en la sesión. */
    public function ocupadas(SesionTenant $sesion): int
    {
        return ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->whereIn('estado', self::OCUPAN_LUGAR)
            ->count() + self::ocupadosDePlataforma($sesion);
    }

    /**
     * Lugares que el canal aún puede tomar: los libres menos los que otros canales tienen
     * apartados y sin usar. Null si la clase no tiene tope. Nunca supera `capacidad -
     * ocupadas` (no-sobreventa).
     */
    public function disponibles(SesionTenant $sesion, string $canal = CanalReserva::Directo->value): ?int
    {
        if ($sesion->capacidad === null) {
            return null;
        }

        $libres = (int) $sesion->capacidad - $this->ocupadas($sesion);
        if ($libres <= 0) {
            return 0;
        }

        return max(0, $libres - ($this->apartadosParaOtrosCanales([$sesion], $canal)[$sesion->getKey()] ?? 0));
    }

    /**
     * Lugares que otros canales (distintos de `$canal`) tienen apartados y sin usar en
     * cada sesión, con dos o tres consultas para todo el lote.
     *
     * @param  iterable<SesionTenant>  $sesiones
     * @return array<int, int> id de la sesión => lugares apartados
     */
    public function apartadosParaOtrosCanales(iterable $sesiones, string $canal = CanalReserva::Directo->value): array
    {
        $porOferta = [];
        $resultado = [];
        foreach ($sesiones as $sesion) {
            $resultado[(int) $sesion->getKey()] = 0;
            if ($sesion->capacidad !== null) {
                $porOferta[(int) $sesion->oferta_id][] = $sesion;
            }
        }
        if ($porOferta === []) {
            return $resultado;
        }

        $reglas = ReglaCapacidadCanalTenant::query()
            ->whereIn('oferta_id', array_keys($porOferta))
            ->where('activa', true)
            ->where('canal', '!=', $canal)
            ->get()
            ->groupBy('oferta_id');
        if ($reglas->isEmpty()) {
            return $resultado;
        }

        // Lo que cada canal ya usó en cada sesión con reglas: sus reservas y, en Wellhub y
        // TotalPass, los lugares de su plataforma (ADR 0148).
        $conReglas = collect($porOferta)->only($reglas->keys()->all())->flatten(1);
        $ids = $conReglas->map(fn (SesionTenant $s): int => (int) $s->getKey())->all();
        $usados = [];
        foreach (ReservaTenant::query()
            ->whereIn('sesion_id', $ids)
            ->whereIn('estado', self::OCUPAN_LUGAR)
            ->selectRaw('sesion_id, canal, COUNT(*) as total')
            ->groupBy('sesion_id', 'canal')
            ->toBase()
            ->get() as $fila) {
            $canalDeFila = $fila->canal instanceof CanalReserva ? $fila->canal->value : (string) $fila->canal;
            $usados[(int) $fila->sesion_id][$canalDeFila] = ($usados[(int) $fila->sesion_id][$canalDeFila] ?? 0) + (int) $fila->total;
        }
        foreach (LugarPlataformaTenant::query()
            ->whereIn('sesion_id', $ids)
            ->whereIn('estado', LugarPlataformaTenant::OCUPAN_LUGAR)
            ->selectRaw('sesion_id, proveedor, COUNT(*) as total')
            ->groupBy('sesion_id', 'proveedor')
            ->toBase()
            ->get() as $fila) {
            $usados[(int) $fila->sesion_id][(string) $fila->proveedor] = ($usados[(int) $fila->sesion_id][(string) $fila->proveedor] ?? 0) + (int) $fila->total;
        }

        $ahora = Carbon::now();
        foreach ($conReglas as $sesion) {
            $apartados = 0;
            foreach ($reglas->get($sesion->oferta_id, collect()) as $regla) {
                /** @var ReglaCapacidadCanalTenant $regla */
                // Pasada su ventana de liberación, sus cupos vuelven a todos.
                if ($ahora->greaterThanOrEqualTo($sesion->inicia_en->copy()->subHours($regla->liberar_horas_antes))) {
                    continue;
                }
                $usadosDelCanal = $usados[(int) $sesion->getKey()][$regla->canal->value] ?? 0;
                $apartados += max(0, $regla->cupos - $usadosDelCanal);
            }
            $resultado[(int) $sesion->getKey()] = $apartados;
        }

        return $resultado;
    }

    /**
     * Deja en cada sesión del lote cuántos lugares apartan otros canales (para que el
     * presentador no consulte sesión por sesión). Pareja de `withCount` en las listas.
     *
     * @param  iterable<SesionTenant>  $sesiones
     */
    public function cargar(iterable $sesiones, string $canal = CanalReserva::Directo->value): void
    {
        $apartados = $this->apartadosParaOtrosCanales($sesiones, $canal);
        foreach ($sesiones as $sesion) {
            $sesion->setAttribute(self::ATRIBUTO_APARTADOS, $apartados[(int) $sesion->getKey()] ?? 0);
        }
    }
}
