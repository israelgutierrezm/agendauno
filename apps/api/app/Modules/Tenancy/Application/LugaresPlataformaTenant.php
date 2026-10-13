<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Support\Facades\DB;

/**
 * El lugar que ocupa en la clase quien viene por Wellhub o TotalPass (ADR 0148). Ocupa
 * cupo sin ser una reserva: no es de una persona del negocio ni consume créditos.
 */
class LugaresPlataformaTenant
{
    /**
     * Su visita ocupa un lugar en su clase: si reservó por la plataforma, ese lugar pasa
     * a asistencia; si no, se toma uno. La persona ya está ahí, así que no se rechaza
     * aunque la clase esté llena. Una visita sin clase no ocupa nada todavía.
     */
    public function ocuparPorVisita(CheckinTenant $checkin): ?LugarPlataformaTenant
    {
        if ($checkin->sesion_id === null) {
            return null;
        }

        return DB::connection('tenant')->transaction(function () use ($checkin): LugarPlataformaTenant {
            // Como cualquier lugar: se bloquea la clase y luego se lee (invariante 8).
            SesionTenant::query()->whereKey($checkin->sesion_id)->lockForUpdate()->first();

            $suyo = LugarPlataformaTenant::query()->where('checkin_id', $checkin->getKey())->first();
            if ($suyo instanceof LugarPlataformaTenant) {
                return $suyo;
            }

            if ($checkin->visitante_id !== null) {
                $reservado = LugarPlataformaTenant::query()
                    ->where('sesion_id', $checkin->sesion_id)
                    ->where('visitante_id', $checkin->visitante_id)
                    ->where('estado', LugarPlataformaTenant::RESERVADO)
                    ->first();
                if ($reservado instanceof LugarPlataformaTenant) {
                    $reservado->fill(['estado' => LugarPlataformaTenant::ASISTIO, 'checkin_id' => $checkin->getKey()])->save();

                    return $reservado;
                }
            }

            return LugarPlataformaTenant::query()->create([
                'sesion_id' => $checkin->sesion_id,
                'proveedor' => $checkin->proveedor,
                'visitante_id' => $checkin->visitante_id,
                'origen' => LugarPlataformaTenant::ORIGEN_VISITA,
                'estado' => LugarPlataformaTenant::ASISTIO,
                'checkin_id' => $checkin->getKey(),
            ]);
        });
    }
}
