<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * El resumen de arriba del directorio de clientes: cuántos hay, cuántos tienen un
 * plan vigente, cuántos llegaron este mes, cuántas membresías están por vencer o
 * recién vencidas (el mismo radar de Renovaciones) y cuántos deben algo.
 *
 * Cuenta a los clientes vigentes del padrón (sin archivados ni dados de baja) de las
 * sedes que ve quien consulta (`$sucursales`, null = todas).
 */
final class ResumenClientesTenant
{
    public function __construct(
        private readonly RadarRenovacionesTenant $radar,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @param  list<int>|null  $sucursales
     * @return array{total: int, con_plan: int, nuevos_mes: int, por_vencer: int, vencidas: int, con_adeudo: int, dias_por_vencer: int}
     */
    public function calcular(?array $sucursales): array
    {
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: config('app.timezone'));
        $ahora = CarbonImmutable::now($zona);

        $clientes = fn (): Builder => PersonaTenant::query()
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->when($sucursales !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $sucursales));

        // Un plan vigente: un acuerdo activo con algún derecho sin vencer.
        $conPlan = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->where(fn ($q) => $q->whereNull('derechos.valido_hasta')
                ->orWhere('derechos.valido_hasta', '>=', $ahora->toDateString()))
            ->select('acuerdos.persona_id');

        $conAdeudo = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pendiente->value)
            ->whereNotNull('persona_id')
            ->select('persona_id');

        $dias = $this->radar->diasPorDefecto();
        $radar = $this->radar->miembros($dias, $sucursales);
        $porVencer = count(array_filter($radar, static fn (array $m): bool => $m['estado'] === 'por_vencer'));

        return [
            'total' => $clientes()->count(),
            'con_plan' => $clientes()->whereIn('id', $conPlan)->count(),
            'nuevos_mes' => $clientes()->where('created_at', '>=', $ahora->startOfMonth()->utc())->count(),
            'por_vencer' => $porVencer,
            'vencidas' => count($radar) - $porVencer,
            'con_adeudo' => $clientes()->whereIn('id', $conAdeudo)->count(),
            'dias_por_vencer' => $dias,
        ];
    }
}
