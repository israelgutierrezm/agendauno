<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\EsquemaPagoVersionTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * La tarifa con que se paga a alguien del equipo una sesión (ADR 0153): la versión de
 * su esquema vigente el día del negocio en que empezó la sesión. Así la nómina y los
 * reportes de un periodo pasado no cambian cuando después se cambia el esquema. Solo
 * cuentan quienes tienen su esquema activo.
 *
 * Carga todo de una vez por instancia (una por cálculo).
 */
class TarifasDePagoTenant
{
    /** @var Collection<int, Collection<int, EsquemaPagoVersionTenant>>|null */
    private ?Collection $versiones = null;

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    /**
     * La versión que le toca a esa persona en ese momento, o null si no tiene esquema
     * activo (o empezó a cobrar después).
     */
    public function para(int $usuarioId, CarbonInterface $momento): ?EsquemaPagoVersionTenant
    {
        $dia = $this->fechas->fecha($momento);

        return $this->versiones()->get($usuarioId)?->last(
            static fn (EsquemaPagoVersionTenant $v): bool => $v->vigente_desde === null || $v->vigente_desde->toDateString() <= $dia,
        );
    }

    /**
     * Las de quienes tienen su esquema activo, de la más vieja a la más nueva. Un
     * esquema sin versiones (creado antes de las versiones, o por un proceso que no
     * las guarda) vale desde siempre.
     *
     * @return Collection<int, Collection<int, EsquemaPagoVersionTenant>>
     */
    private function versiones(): Collection
    {
        if ($this->versiones !== null) {
            return $this->versiones;
        }

        $activos = EsquemaPagoTenant::query()->where('activo', true)->get()->keyBy('usuario_id');
        $guardadas = EsquemaPagoVersionTenant::query()
            ->whereIn('usuario_id', $activos->keys()->all())
            ->orderByRaw('vigente_desde is not null')
            ->orderBy('vigente_desde')
            ->get()
            ->groupBy('usuario_id');

        $this->versiones = $activos->map(static fn (EsquemaPagoTenant $e, int $usuarioId): Collection => $guardadas->get($usuarioId)
            ?? collect([new EsquemaPagoVersionTenant([
                'usuario_id' => $usuarioId,
                'tipo' => $e->tipo,
                'monto_minor' => $e->monto_minor,
                'moneda' => $e->moneda,
                'vigente_desde' => null,
            ])]));

        return $this->versiones;
    }
}
