<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo que le interesó cuando era prospecto (plan de mejoras B01/C04, ADR 0137): al
 * entrar a su cuenta lo ve a la mano para continuar sin buscarlo de nuevo. Solo por un
 * tiempo después de convertirse, y solo si sigue disponible y aún no lo tiene: un plan
 * a la venta que no ha comprado, una clase futura que no ha reservado. Nunca aparta
 * lugar.
 */
class MiInteresTenantController
{
    /** Días después de convertirse en que se le recuerda. */
    private const DIAS = 30;

    public function __construct(private readonly CuentaEnUsoTenant $cuenta) {}

    public function __invoke(Request $request): JsonResponse
    {
        $persona = $this->cuenta->persona($request);
        $prospecto = $persona instanceof PersonaTenant
            ? ProspectoTenant::query()
                ->where('persona_id', $persona->getKey())
                ->whereIn('estado', [EstadoProspecto::Convertido->value, EstadoProspecto::InvitacionEnviada->value])
                ->where(fn ($q) => $q->whereNull('convertido_en')->orWhere('convertido_en', '>=', Carbon::now()->subDays(self::DIAS)))
                ->where('updated_at', '>=', Carbon::now()->subDays(self::DIAS))
                ->with(['producto', 'sesion.oferta'])
                ->latest('id')
                ->first()
            : null;
        if (! $persona instanceof PersonaTenant || ! $prospecto instanceof ProspectoTenant) {
            return response()->json(['data' => null]);
        }

        $producto = $prospecto->producto;
        $plan = $producto instanceof ProductoTenant && ! $producto->archivado
            && ! AcuerdoTenant::query()->where('persona_id', $persona->getKey())->where('producto_comercial_id', $producto->getKey())->exists()
            ? ['id' => $producto->ulid, 'nombre' => $producto->nombre, 'precio_minor' => $producto->precio_minor, 'moneda' => $producto->moneda]
            : null;

        $sesion = $prospecto->sesion;
        $clase = $sesion instanceof SesionTenant && $sesion->estado === EstadoSesionTenant::Programada && $sesion->inicia_en->isFuture()
            && ! ReservaTenant::query()->where('persona_id', $persona->getKey())->where('sesion_id', $sesion->getKey())
                ->whereNotIn('estado', [EstadoReserva::Cancelada->value, EstadoReserva::Expirada->value])->exists()
            ? ['id' => $sesion->ulid, 'nombre' => $sesion->oferta?->nombre, 'inicia_en' => $sesion->inicia_en->toIso8601String(), 'zona_horaria' => $sesion->zona_horaria]
            : null;

        return response()->json(['data' => $plan === null && $clase === null ? null : ['plan' => $plan, 'clase' => $clase]]);
    }
}
