<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Acceso\ResultadoAcceso;
use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Registra un intento de acceso (R12): lo evalua con la politica, deja el asiento en
 * la bitacora `accesos` (permitido o denegado, con su codigo de razon) y emite el
 * evento de dominio `acceso.registrado` en el outbox, todo en una transaccion.
 */
class RegistrarAccesoTenant
{
    public function __construct(
        private readonly EvaluarAccesoTenant $evaluador,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    public function registrar(PersonaTenant $persona, MetodoAcceso $metodo, ?int $sucursalId, CarbonInterface $momento): AccesoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($persona, $metodo, $sucursalId, $momento): AccesoTenant {
            $decision = $this->evaluador->evaluar($persona, $sucursalId, $momento);

            $acceso = AccesoTenant::query()->create([
                'persona_id' => $persona->getKey(),
                'sucursal_id' => $sucursalId,
                'sesion_id' => $decision->sesionId,
                'metodo' => $metodo->value,
                'resultado' => ($decision->permitido ? ResultadoAcceso::Permitido : ResultadoAcceso::Denegado)->value,
                'codigo' => $decision->codigo,
                'registrado_en' => $momento,
            ]);

            $this->eventos->registrar('acceso.registrado', 'acceso', $acceso->ulid, [
                'persona_id' => $persona->ulid,
                'resultado' => $acceso->resultado->value,
                'codigo' => $acceso->codigo,
            ]);

            return $acceso;
        });
    }
}
