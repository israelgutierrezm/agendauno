<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\ApartadoArticuloTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Productos al reservar (ADR 0194). El negocio marca qué artículos del mostrador se
 * ofrecen al reservar; quien tiene su lugar confirmado en una clase los aparta antes de
 * que empiece y los recoge y paga en el mostrador (no se cobran en línea).
 *
 * - Se ofrece lo que hay en la sede de la clase, menos lo ya apartado ahí: no se aparta
 *   lo que no se podrá entregar. El artículo se bloquea al apartar.
 * - Apartar otra vez lo mismo para la misma clase suma a lo apartado (hasta 5).
 * - El cliente lo cancela antes de la clase; el equipo, cuando sea.
 * - Al cobrarse en el mostrador quedan entregados con su venta. El inventario se mueve
 *   con la venta, no al apartar.
 */
class ApartadosArticuloTenant
{
    public function __construct(private readonly InventarioTenant $inventario) {}

    /**
     * Lo que se ofrece para esa clase, con cuántos quedan para apartar.
     *
     * @return list<array{articulo: ArticuloTenant, disponibles: int}>
     */
    public function ofrecidos(SesionTenant $sesion): array
    {
        $sucursal = (int) $sesion->sucursal_id;
        $articulos = ArticuloTenant::query()->where('activo', true)->where('ofrecer_al_reservar', true)->orderBy('nombre')->get();
        if ($articulos->isEmpty()) {
            return [];
        }
        $ids = $articulos->modelKeys();
        $stock = $this->inventario->stockPorArticulos($ids, [$sucursal]);
        $apartados = $this->apartadosPorArticulo($ids, $sucursal);

        $ofrecidos = [];
        foreach ($articulos as $articulo) {
            $disponibles = (int) ($stock[$articulo->getKey()][$sucursal] ?? 0) - ($apartados[$articulo->getKey()] ?? 0);
            if ($disponibles > 0) {
                $ofrecidos[] = ['articulo' => $articulo, 'disponibles' => min($disponibles, ApartadoArticuloTenant::MAX_CANTIDAD)];
            }
        }

        return $ofrecidos;
    }

    public function apartar(PersonaTenant $persona, SesionTenant $sesion, ArticuloTenant $articulo, int $cantidad): ApartadoArticuloTenant
    {
        if ($sesion->inicia_en->isPast()) {
            throw ValidationException::withMessages(['sesion' => ['La clase ya empezó: pídelo en el mostrador.']]);
        }
        $reserva = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', EstadoReserva::Confirmada->value)
            ->first();
        if (! $reserva instanceof ReservaTenant) {
            throw ValidationException::withMessages(['sesion' => ['Aparta algo para una clase en la que tienes tu lugar.']]);
        }
        if (! $articulo->ofrecer_al_reservar || ! $articulo->activo) {
            throw ValidationException::withMessages(['articulo_id' => ['Ese artículo no se aparta al reservar.']]);
        }

        return DB::connection('tenant')->transaction(function () use ($persona, $sesion, $articulo, $cantidad, $reserva): ApartadoArticuloTenant {
            // Serializa a quienes apartan lo mismo: no se aparta de más.
            ArticuloTenant::query()->whereKey($articulo->getKey())->lockForUpdate()->first();
            $sucursal = (int) $sesion->sucursal_id;
            $existente = ApartadoArticuloTenant::query()
                ->where('persona_id', $persona->getKey())
                ->where('sesion_id', $sesion->getKey())
                ->where('articulo_id', $articulo->getKey())
                ->where('estado', 'apartado')
                ->first();
            $total = $cantidad + (int) ($existente->cantidad ?? 0);
            if ($total > ApartadoArticuloTenant::MAX_CANTIDAD) {
                throw ValidationException::withMessages(['cantidad' => ['Se apartan hasta '.ApartadoArticuloTenant::MAX_CANTIDAD.' de cada uno.']]);
            }
            $stock = $this->inventario->stock((int) $articulo->getKey(), $sucursal);
            $apartados = $this->apartadosPorArticulo([(int) $articulo->getKey()], $sucursal)[$articulo->getKey()] ?? 0;
            if ($stock - $apartados < $cantidad) {
                throw ValidationException::withMessages(['cantidad' => ['Ya no quedan suficientes para apartar.']]);
            }

            if ($existente instanceof ApartadoArticuloTenant) {
                $existente->update(['cantidad' => $total]);

                return $existente;
            }

            return ApartadoArticuloTenant::query()->create([
                'persona_id' => $persona->getKey(),
                'sesion_id' => $sesion->getKey(),
                'reserva_id' => $reserva->getKey(),
                'sucursal_id' => $sucursal,
                'articulo_id' => $articulo->getKey(),
                'cantidad' => $cantidad,
                'precio_minor' => $articulo->precio_minor,
                'moneda' => $articulo->moneda,
                'estado' => 'apartado',
            ]);
        });
    }

    /** Lo cancela: el cliente antes de su clase; el equipo, cuando sea. */
    public function cancelar(ApartadoArticuloTenant $apartado, bool $porElCliente): void
    {
        if ($apartado->estado !== 'apartado') {
            throw ValidationException::withMessages(['apartado' => ['Ya se entregó o se canceló.']]);
        }
        if ($porElCliente && $apartado->sesion?->inicia_en->isPast() === true) {
            throw ValidationException::withMessages(['apartado' => ['La clase ya empezó: avisa en el mostrador.']]);
        }
        $apartado->update(['estado' => 'cancelado']);
    }

    /**
     * @return Collection<int, ApartadoArticuloTenant>
     */
    public function deSesion(SesionTenant $sesion): Collection
    {
        return ApartadoArticuloTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', 'apartado')
            ->with(['articulo', 'persona'])
            ->orderBy('persona_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los cobrados en una venta del mostrador quedan entregados con ella. Solo los de su
     * comprador y su sede, aún apartados.
     *
     * @param  list<string>  $ulids
     */
    public function entregar(array $ulids, VentaPosTenant $venta): int
    {
        if ($ulids === [] || $venta->persona_id === null) {
            return 0;
        }

        return ApartadoArticuloTenant::query()
            ->whereIn('ulid', $ulids)
            ->where('persona_id', $venta->persona_id)
            ->where('sucursal_id', $venta->sucursal_id)
            ->where('estado', 'apartado')
            ->update(['estado' => 'entregado', 'venta_pos_id' => $venta->getKey()]);
    }

    /**
     * Lo apartado (sin entregar) de cada artículo en la sede.
     *
     * @param  list<int>  $articulos
     * @return array<int, int>
     */
    private function apartadosPorArticulo(array $articulos, int $sucursal): array
    {
        return ApartadoArticuloTenant::query()
            ->whereIn('articulo_id', $articulos)
            ->where('sucursal_id', $sucursal)
            ->where('estado', 'apartado')
            ->groupBy('articulo_id')
            ->selectRaw('articulo_id, sum(cantidad) as total')
            ->pluck('total', 'articulo_id')
            ->map(static fn (mixed $t): int => (int) $t)
            ->all();
    }
}
