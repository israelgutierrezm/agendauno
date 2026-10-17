<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;

/**
 * Cuántas sesiones próximas usan un espacio a la vez, en su momento más lleno. Lo
 * pide quien corrige su capacidad o su modo: bajarla por debajo de eso dejaría fechas
 * ya agendadas usándolo más veces de las que admite. Cuenta el tramo ocupado (con
 * preparación y limpieza), igual que {@see VerificarRecursoTenant}.
 */
class UsoSimultaneoDeRecursoTenant
{
    /**
     * @return array{maximo: int, desde: CarbonImmutable|null}
     */
    public function maximo(RecursoTenant $recurso): array
    {
        $eventos = [];
        SesionTenant::query()
            ->where('recurso_id', $recurso->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('ocupa_hasta', '>', now())
            ->get(['ocupa_desde', 'ocupa_hasta'])
            ->each(function (SesionTenant $s) use (&$eventos): void {
                // Al mismo instante, primero sale una y luego entra otra: tocarse no es
                // solaparse (la verificación usa < y >).
                $eventos[] = [CarbonImmutable::instance($s->ocupa_desde), 1];
                $eventos[] = [CarbonImmutable::instance($s->ocupa_hasta), -1];
            });
        usort($eventos, static fn (array $a, array $b): int => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);

        $actual = 0;
        $maximo = 0;
        $desde = null;
        foreach ($eventos as [$momento, $cambio]) {
            $actual += $cambio;
            if ($actual > $maximo) {
                $maximo = $actual;
                $desde = $momento;
            }
        }

        return ['maximo' => $maximo, 'desde' => $desde];
    }
}
