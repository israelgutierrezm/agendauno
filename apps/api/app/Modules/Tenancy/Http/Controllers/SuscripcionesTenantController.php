<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use Illuminate\Http\JsonResponse;

/**
 * Suscripciones recurrentes del estudio (Etapa 2): membresías con cobro recurrente
 * (llevan `proxima_cobro_en`), para dar visibilidad de las próximas renovaciones que
 * el scheduler cobrará. Solo lectura; el cobro lo ejecuta el comando
 * `turnouno:cobrar-suscripciones`.
 */
class SuscripcionesTenantController
{
    private const LIMITE = 200;

    public function index(): JsonResponse
    {
        $acuerdos = AcuerdoTenant::query()
            ->whereNotNull('proxima_cobro_en')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with(['persona', 'producto'])
            ->orderBy('proxima_cobro_en')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $acuerdos->map(static fn (AcuerdoTenant $a): array => [
                'id' => $a->ulid,
                'persona' => $a->persona?->nombreCompleto(),
                'producto' => $a->producto?->nombre,
                'precio_minor' => $a->producto?->precio_minor,
                'moneda' => $a->producto?->moneda,
                'proxima_cobro_en' => $a->proxima_cobro_en?->toDateString(),
                'estado' => $a->estado->value,
            ])->all(),
        ]);
    }
}
