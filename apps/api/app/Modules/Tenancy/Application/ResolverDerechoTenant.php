<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resuelve que derecho (entitlement) tenant-local de una persona cubre una sesion:
 * uno vigente y con saldo suficiente. Prefiere gastar un derecho limitado con saldo
 * antes que uno ilimitado, para no "desperdiciar" packs comprados.
 */
class ResolverDerechoTenant
{
    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly FechasNegocioTenant $fechas,
    ) {}

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
     * Qué puede reservar con lo que tiene, clase por clase, ANTES de intentarlo: la
     * misma regla que al reservar (plan activo, vigente el día de la clase, de esa
     * actividad, clase y sucursal, con saldo). Si no la cubre, el motivo (no tiene
     * plan, su plan no incluye esa clase, está en pausa o suspendido, no está
     * vigente ese día, no vale en esa sucursal o ya no le quedan clases) y si solo
     * una membresía del catálogo la incluye. Los planes y su saldo se leen una vez.
     *
     * @param  iterable<SesionTenant>  $sesiones  con `oferta` cargada
     * @return array<int, array{estado: string, motivo: string|null}> por id interno de la sesión
     */
    public function coberturaDeSesiones(PersonaTenant $persona, iterable $sesiones, int $unidades): array
    {
        // Lo que aún sirve o servirá: ni cancelado ni vencido.
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn (Builder $q) => $q->where('persona_id', $persona->getKey())
                ->where('estado', '!=', EstadoAcuerdo::Cancelado->value))
            ->where(fn ($q) => $q->whereNull('valido_hasta')->orWhere('valido_hasta', '>=', $this->fechas->hoy()))
            ->with(['acuerdo', 'ofertas:id'])
            ->get();
        $saldo = $derechos->mapWithKeys(fn (DerechoTenant $d): array => [
            (int) $d->getKey() => $d->ilimitado ? PHP_INT_MAX : $this->libro->disponible($d),
        ]);
        /** @var Collection<int, ProductoTenant>|null $catalogo */
        $catalogo = null;

        $cobertura = [];
        foreach ($sesiones as $sesion) {
            $motivo = $this->motivoSinCobertura($derechos, $saldo, $sesion, $unidades);
            if ($motivo === null) {
                $cobertura[(int) $sesion->getKey()] = ['estado' => 'incluida', 'motivo' => null];

                continue;
            }

            // Su plan no incluye esa clase (o no tiene): ¿solo una membresía la incluye?
            $soloMembresia = false;
            if (in_array($motivo, ['sin_plan', 'clase'], true)) {
                $catalogo ??= ProductoTenant::query()->where('archivado', false)
                    ->where('tipo', '!=', TipoProducto::AddOn->value)->with('ofertas:id')->get();
                $laIncluyen = $catalogo->filter(fn (ProductoTenant $p): bool => $this->productoCubre($p, $sesion));
                $soloMembresia = $laIncluyen->isNotEmpty()
                    && $laIncluyen->every(fn (ProductoTenant $p): bool => $p->tipo === TipoProducto::Membresia);
            }
            $cobertura[(int) $sesion->getKey()] = ['estado' => $soloMembresia ? 'solo_membresia' : 'no_incluida', 'motivo' => $motivo];
        }

        return $cobertura;
    }

    /**
     * Null si algún plan cubre la sesión; si no, el primer motivo que lo impide.
     *
     * @param  Collection<int, DerechoTenant>  $derechos
     * @param  Collection<int, int>  $saldo  disponible por id de derecho
     */
    private function motivoSinCobertura(Collection $derechos, Collection $saldo, SesionTenant $sesion, int $unidades): ?string
    {
        if ($derechos->isEmpty()) {
            return 'sin_plan';
        }
        $deLaClase = $derechos->filter(fn (DerechoTenant $d): bool => $this->cubre($d, $sesion, false));
        if ($deLaClase->isEmpty()) {
            return 'clase';
        }
        $activos = $deLaClase->filter(fn (DerechoTenant $d): bool => $d->acuerdo?->estado === EstadoAcuerdo::Activo);
        if ($activos->isEmpty()) {
            return $deLaClase->contains(fn (DerechoTenant $d): bool => $d->acuerdo?->estado === EstadoAcuerdo::Suspendido) ? 'suspendido' : 'pausa';
        }
        $eseDia = $activos->filter(fn (DerechoTenant $d): bool => $this->vigente($d, $sesion->inicia_en));
        if ($eseDia->isEmpty()) {
            return 'vigencia';
        }
        $enLaSede = $eseDia->filter(fn (DerechoTenant $d): bool => $this->cubre($d, $sesion));
        if ($enLaSede->isEmpty()) {
            return 'sucursal';
        }

        return $enLaSede->contains(fn (DerechoTenant $d): bool => (int) $saldo->get((int) $d->getKey(), 0) >= $unidades) ? null : 'saldo';
    }

    /** ¿Un plan del catálogo incluye esta sesión (actividad, sucursal y clases)? */
    private function productoCubre(ProductoTenant $producto, SesionTenant $sesion): bool
    {
        return ($producto->actividad_id === null || (int) $producto->actividad_id === (int) $sesion->oferta?->actividad_id)
            && $producto->valeEnSucursal((int) $sesion->sucursal_id)
            && ($producto->ofertas->isEmpty() || $producto->ofertas->contains('id', (int) $sesion->oferta_id));
    }

    /**
     * Por qué no se puede reservar con lo que tiene: si un plan vigente y con saldo
     * cubre la actividad pero no esa sucursal, se lo dice (para elegir otra sede o un
     * plan multisucursal); si no, que no tiene un derecho con saldo.
     */
    public function motivoSinDerecho(PersonaTenant $persona, SesionTenant $sesion, int $unidades): string
    {
        $otraSucursal = DerechoTenant::query()->whereHas('acuerdo', fn ($q) => $q
            ->where('persona_id', $persona->getKey())->where('estado', EstadoAcuerdo::Activo->value))
            ->with('ofertas:id')->get()->contains(fn (DerechoTenant $d): bool => $this->vigente($d, $sesion->inicia_en) && $this->cubre($d, $sesion, false)
                && ! $this->cubre($d, $sesion)
                && ($d->ilimitado || $this->libro->disponible($d) >= $unidades));

        return $otraSucursal ? 'Tu plan cubre esta actividad, pero no esta sucursal. Elige una sucursal incluida o un plan multisucursal.'
            : 'No hay un derecho con saldo para esta sesion.';
    }

    /**
     * ¿El derecho cubre esta sesion segun sus restricciones de actividad, sucursal (una
     * o varias, ADR 0017) y clases (p. ej. "Nivel 1 a 3")? Sin restriccion (nulo o sin
     * clases) cubre cualquiera. Sin `$revisarSucursal`, ignora la sucursal.
     */
    private function cubre(DerechoTenant $derecho, SesionTenant $sesion, bool $revisarSucursal = true): bool
    {
        $sesion->loadMissing('oferta');

        if ($derecho->actividad_id !== null && (int) $derecho->actividad_id !== (int) $sesion->oferta?->actividad_id) {
            return false;
        }

        if ($revisarSucursal && $derecho->sucursal_id !== null && (int) $derecho->sucursal_id !== (int) $sesion->sucursal_id) {
            return false;
        }
        if ($revisarSucursal && $derecho->sucursales_ids !== null && ! in_array((int) $sesion->sucursal_id, $derecho->sucursales_ids, true)) {
            return false;
        }

        $ofertas = $derecho->ofertas;

        return $ofertas->isEmpty() || $ofertas->contains('id', (int) $sesion->oferta_id);
    }

    /**
     * Las fechas de vigencia son días del negocio (su zona horaria): el último día
     * cubre hasta las 23:59 locales, no hasta la medianoche UTC.
     */
    private function vigente(DerechoTenant $derecho, CarbonInterface $momento): bool
    {
        return $this->fechas->cubre($derecho->valido_desde, $derecho->valido_hasta, $momento);
    }
}
