<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Http\Middleware\ResolverEstudio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Suspensión automática por renta vencida (ADR 0073).
 *
 * - Un negocio con una renta sin pagar que venció hace más días de gracia se
 *   suspende solo. Los días de gracia son el parámetro de plataforma
 *   `renta.dias_gracia_suspension`; 0 = nunca.
 * - Suspendido así, el negocio queda cerrado para todos: página pública, clientes y
 *   equipo. Solo quien ve la facturación puede entrar a pagar
 *   ({@see ResolverEstudio}).
 * - Al pagar se reactiva solo. Si el superadministrador lo reactiva a mano, no se
 *   vuelve a suspender en otros días de gracia.
 */
class SuspensionPorRenta
{
    public const POR_RENTA = 'renta';

    public const POR_PLATAFORMA = 'plataforma';

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly AlertasPlataforma $alertas,
    ) {}

    public function diasGracia(): int
    {
        return $this->parametros->entero('renta.dias_gracia_suspension');
    }

    /**
     * El día en que se suspende el negocio por esa renta (si sigue sin pagarse), o
     * null si la suspensión automática está apagada.
     */
    public function fechaDeSuspension(CargoRenta $cargo): ?CarbonImmutable
    {
        $dias = $this->diasGracia();

        return $dias > 0 && $cargo->vence_en instanceof DateTimeInterface
            ? CarbonImmutable::instance($cargo->vence_en)->startOfDay()->addDays($dias)
            : null;
    }

    /**
     * Suspende a quien le toca y reactiva a quien ya pagó.
     *
     * @return array{suspendidos: int, reactivados: int}
     */
    public function revisar(): array
    {
        $suspendidos = 0;
        $dias = $this->diasGracia();
        if ($dias > 0) {
            CargoRenta::query()->with('estudio')
                ->where('estado', EstadoCargoRenta::Pendiente->value)
                ->whereDate('vence_en', '<=', CarbonImmutable::today()->subDays($dias)->toDateString())
                ->orderBy('vence_en')
                ->get()
                ->groupBy('estudio_id')
                ->each(function (Collection $cargos) use (&$suspendidos): void {
                    /** @var CargoRenta $cargo */
                    $cargo = $cargos->first();
                    if ($cargo->estudio instanceof Estudio && $this->suspender($cargo->estudio, $cargo)) {
                        $suspendidos++;
                    }
                });
        }

        $reactivados = 0;
        Estudio::query()
            ->where('estado', EstadoEstudio::Suspended->value)
            ->where('suspendido_por', self::POR_RENTA)
            ->each(function (Estudio $estudio) use (&$reactivados): void {
                if ($this->reactivarSiPago($estudio)) {
                    $reactivados++;
                }
            });

        return ['suspendidos' => $suspendidos, 'reactivados' => $reactivados];
    }

    /**
     * Si ya no debe rentas fuera de la gracia, vuelve a abrir el negocio suspendido
     * por renta: en prueba si aún no termina; si no, activo.
     */
    public function reactivarSiPago(?Estudio $estudio): bool
    {
        if (! $estudio instanceof Estudio || ! $estudio->suspendidoPorRenta() || $this->rentaFueraDeGracia($estudio) !== null) {
            return false;
        }

        $enPrueba = $estudio->trial_termina_en !== null && ! $estudio->trial_termina_en->isPast();
        $estudio->forceFill([
            'estado' => ($enPrueba ? EstadoEstudio::Trialing : EstadoEstudio::Active)->value,
            'suspendido_por' => null,
            'suspendido_en' => null,
        ])->save();
        Log::info('plataforma.estudio.reactivado_por_pago', ['estudio' => $estudio->slug]);

        return true;
    }

    /**
     * La renta más antigua que sigue sin pagarse fuera de la gracia (sin gracia,
     * cualquiera vencida), o null.
     */
    public function rentaFueraDeGracia(Estudio $estudio): ?CargoRenta
    {
        $dias = $this->diasGracia();
        $limite = $dias > 0 ? CarbonImmutable::today()->subDays($dias) : CarbonImmutable::yesterday();

        return CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->whereDate('vence_en', '<=', $limite->toDateString())
            ->orderBy('vence_en')
            ->first();
    }

    private function suspender(Estudio $estudio, CargoRenta $cargo): bool
    {
        if (! $estudio->estado->operativo()) {
            return false;
        }
        // Lo reactivó el superadministrador a mano: aún no se vuelve a suspender.
        if ($estudio->sin_suspension_hasta !== null && ! $estudio->sin_suspension_hasta->isPast()) {
            return false;
        }

        $estudio->forceFill([
            'estado' => EstadoEstudio::Suspended->value,
            'suspendido_por' => self::POR_RENTA,
            'suspendido_en' => now(),
        ])->save();

        $periodo = CarbonImmutable::createFromFormat('Y-m-d', $cargo->periodo.'-01');
        $this->alertas->registrar(
            'suspension_por_renta',
            'estudio-'.$estudio->getKey().'-'.now()->format('Ymd'),
            "{$estudio->nombre} se suspendió solo: la renta de "
                .($periodo instanceof CarbonImmutable ? $periodo->locale('es')->isoFormat('MMMM [de] YYYY') : $cargo->periodo)
                .' por '.DatosDeOrden::dinero($cargo->monto_minor, $cargo->moneda).' sigue sin pagarse. Al pagarla se reactiva solo.',
            (string) $estudio->slug,
        );
        Log::info('plataforma.estudio.suspendido_por_renta', ['estudio' => $estudio->slug, 'cargo' => $cargo->ulid]);

        return true;
    }
}
