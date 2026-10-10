<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\CupoProfesionalesExcedido;
use App\Modules\Tenancy\Models\Estudio;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Suma profesionales sin pasarse de los contratados (ADR 0107) aunque lleguen dos a la
 * vez (dos invitaciones, una importación, un cambio de rol): bloquea la fila del negocio
 * en el control plane (la misma que bloquea el cambio de plan) mientras cuenta y da de
 * alta. El segundo espera a que el primero termine y ya lo cuenta.
 */
class CupoProfesionales
{
    public function __construct(private readonly PlanCitasSaas $planes) {}

    /**
     * Da de alta a `$nuevos` profesionales con `$sumar` (en la base del negocio) solo si
     * caben en su plan. Sin profesionales nuevos o sin plan con límite, solo da de alta.
     *
     * @template T
     *
     * @param  Closure(): T  $sumar
     * @return T
     *
     * @throws CupoProfesionalesExcedido
     */
    public function sumar(Estudio $estudio, int $nuevos, Closure $sumar): mixed
    {
        if ($nuevos <= 0 || $this->planes->limiteProfesionales($estudio) === null) {
            return $sumar();
        }

        return DB::transaction(function () use ($estudio, $nuevos, $sumar): mixed {
            $bloqueado = Estudio::query()->whereKey($estudio->getKey())->lockForUpdate()->firstOrFail();
            $this->planes->exigirCupo($bloqueado, $nuevos);

            return $sumar();
        });
    }
}
