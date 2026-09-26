<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;

/**
 * Politica de cancelacion/no-show ya resuelta (VALORES efectivos que aplican a una
 * reserva). Se congela en la reserva al crearse; cancelar/marcar asistencia leen ese
 * snapshot, no la configuracion viva. {@see ResolverPoliticaCancelacionTenant}.
 */
final readonly class PoliticaCancelacion
{
    public function __construct(
        public int $horasLimite,
        public bool $penalizaTarde,
        public bool $penalizaNoShow,
        public int $toleranciaNoShow = 0,
        // En cuántos días a la redonda se cuentan las inasistencias toleradas.
        public int $ventanaNoShowDias = 30,
    ) {}

    /**
     * Política cuando el negocio no configuró ninguna: la que fija la plataforma
     * (parámetros `cancelacion.*`, ADR 0042).
     */
    public static function porDefecto(ParametrosTenant $parametros): self
    {
        return new self(
            $parametros->entero('cancelacion.horas_limite'),
            $parametros->siNo('cancelacion.penaliza_tarde'),
            $parametros->siNo('cancelacion.penaliza_no_show'),
            $parametros->entero('cancelacion.tolerancia_no_show'),
            $parametros->entero('cancelacion.ventana_no_show_dias'),
        );
    }

    public static function deModelo(PoliticaCancelacionTenant $modelo, ParametrosTenant $parametros): self
    {
        return new self(
            $modelo->horas_limite,
            $modelo->penaliza_tarde,
            $modelo->penaliza_no_show,
            $modelo->tolerancia_no_show,
            $modelo->ventana_no_show_dias ?? $parametros->entero('cancelacion.ventana_no_show_dias'),
        );
    }
}
