<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Al volver de la página de la pasarela, la web pregunta aquí si el pago ya se
 * confirmó (por el aviso de la pasarela o la conciliación) y solo entonces dice «Pago
 * confirmado». Sin sesión, porque también vuelven la cita y el presupuesto sin cuenta:
 * el ULID del pago viaja en la URL de regreso (`ref_pago`) y solo se responde su estado,
 * sin montos ni datos de nadie.
 */
class ConfirmacionPagoController
{
    public function __invoke(Request $request): JsonResponse
    {
        $pago = PagoTenant::query()->where('ulid', (string) $request->route('pago'))->firstOrFail();

        return response()->json(['data' => ['estado' => self::estado($pago->estado)]]);
    }

    /** `confirmado`, `en_proceso` (aún sin aviso de la pasarela) o `no_completado`. */
    public static function estado(EstadoPago $estado): string
    {
        return match ($estado) {
            EstadoPago::Pendiente => 'en_proceso',
            EstadoPago::Rechazado, EstadoPago::Anulado => 'no_completado',
            EstadoPago::Aprobado, EstadoPago::Reembolsado, EstadoPago::ParcialmenteReembolsado => 'confirmado',
        };
    }
}
