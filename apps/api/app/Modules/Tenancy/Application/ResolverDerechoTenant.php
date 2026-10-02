<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Resuelve que derecho (entitlement) tenant-local de una persona cubre una sesion:
 * uno vigente y con saldo suficiente. Prefiere gastar un derecho limitado con saldo
 * antes que uno ilimitado, para no "desperdiciar" packs comprados.
 */
class ResolverDerechoTenant
{
    public function __construct(private readonly LibroMayorTenant $libro) {}

    public function paraSesion(PersonaTenant $persona, SesionTenant $sesion, int $unidades): ?DerechoTenant
    {
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', function (Builder $consulta) use ($persona): void {
                $consulta->where('persona_id', $persona->getKey())
                    ->where('estado', EstadoAcuerdo::Activo->value);
            })
            ->with('ofertas:id')
            ->get()
            ->filter(fn (DerechoTenant $derecho): bool => $this->vigente($derecho, $sesion->inicia_en) && $this->cubre($derecho, $sesion))
            // Se gasta primero lo que vence antes; con el mismo vencimiento, el paquete
            // antes que sus clases extra (así el corte muestra cuándo se usaron).
            ->sortBy([
                fn (DerechoTenant $a, DerechoTenant $b): int => ($a->valido_hasta?->toDateString() ?? '9999-12-31') <=> ($b->valido_hasta?->toDateString() ?? '9999-12-31'),
                fn (DerechoTenant $a, DerechoTenant $b): int => ($a->extra_de_id !== null) <=> ($b->extra_de_id !== null),
                fn (DerechoTenant $a, DerechoTenant $b): int => $a->getKey() <=> $b->getKey(),
            ]);

        $limitado = $derechos->first(
            fn (DerechoTenant $derecho): bool => ! $derecho->ilimitado && $this->libro->disponible($derecho) >= $unidades,
        );

        if ($limitado !== null) {
            return $limitado;
        }

        return $derechos->first(fn (DerechoTenant $derecho): bool => $derecho->ilimitado);
    }

    /**
     * Los servicios (de entre `$ofertas`) que la persona puede reservar HOY con un bono
     * o membresía: un derecho activo, vigente, con saldo para una sesión (o ilimitado)
     * y que incluya ese servicio. La sucursal se revisa al agendar (ADR 0091).
     *
     * @param  iterable<OfertaTenant>  $ofertas
     * @return list<int> ids internos de las ofertas cubiertas
     */
    public function ofertasCubiertas(PersonaTenant $persona, iterable $ofertas, int $unidades): array
    {
        $ahora = now();
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', function (Builder $consulta) use ($persona): void {
                $consulta->where('persona_id', $persona->getKey())
                    ->where('estado', EstadoAcuerdo::Activo->value);
            })
            ->with('ofertas:id')
            ->get()
            ->filter(fn (DerechoTenant $d): bool => $this->vigente($d, $ahora)
                && ($d->ilimitado || $this->libro->disponible($d) >= $unidades));

        $cubiertas = [];
        foreach ($ofertas as $oferta) {
            $cubre = $derechos->contains(fn (DerechoTenant $d): bool => ($d->actividad_id === null || (int) $d->actividad_id === (int) $oferta->actividad_id)
                && ($d->ofertas->isEmpty() || $d->ofertas->contains('id', (int) $oferta->getKey())));
            if ($cubre) {
                $cubiertas[] = (int) $oferta->getKey();
            }
        }

        return $cubiertas;
    }

    /**
     * ¿El derecho cubre esta sesion segun sus restricciones de actividad, sucursal y
     * clases (p. ej. "Nivel 1 a 3")? Sin restriccion (nulo o sin clases) cubre cualquiera.
     */
    private function cubre(DerechoTenant $derecho, SesionTenant $sesion): bool
    {
        $sesion->loadMissing('oferta');

        if ($derecho->actividad_id !== null && (int) $derecho->actividad_id !== (int) $sesion->oferta?->actividad_id) {
            return false;
        }

        if ($derecho->sucursal_id !== null && (int) $derecho->sucursal_id !== (int) $sesion->sucursal_id) {
            return false;
        }

        $ofertas = $derecho->ofertas;

        return $ofertas->isEmpty() || $ofertas->contains('id', (int) $sesion->oferta_id);
    }

    private function vigente(DerechoTenant $derecho, CarbonInterface $momento): bool
    {
        if ($derecho->valido_desde !== null && $momento->lessThan($derecho->valido_desde)) {
            return false;
        }

        if ($derecho->valido_hasta !== null && $momento->greaterThan($derecho->valido_hasta->copy()->endOfDay())) {
            return false;
        }

        return true;
    }
}
