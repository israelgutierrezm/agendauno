<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Http\SesionTenantPresenter;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo que le interesó cuando era prospecto (plan de mejoras B01/C04, ADR 0137): al
 * entrar a su cuenta lo ve a la mano para continuar sin buscarlo de nuevo. Solo por un
 * tiempo después de convertirse, y solo si sigue disponible y aún no lo tiene: un plan
 * a la venta que no ha comprado, una clase futura que no ha reservado. Nunca aparta
 * lugar.
 *
 * También lo que eligió en la página del negocio justo antes de entrar (ADR 0142): la
 * página lo manda en la dirección de regreso (`?plan=` o `?clase=`). Solo cuenta lo
 * de este negocio que sigue público; eso va primero.
 */
class MiInteresTenantController
{
    /** Días después de convertirse en que se le recuerda. */
    private const DIAS = 30;

    public function __construct(private readonly CuentaEnUsoTenant $cuenta) {}

    public function __invoke(Request $request): JsonResponse
    {
        $persona = $this->cuenta->persona($request);
        if (! $persona instanceof PersonaTenant) {
            return response()->json(['data' => null]);
        }

        $elegidoPlan = self::ulid($request->query('plan'));
        $elegidaClase = self::ulid($request->query('clase'));
        if ($elegidoPlan !== null || $elegidaClase !== null) {
            $producto = $elegidoPlan !== null
                ? ProductoTenant::query()->where('ulid', $elegidoPlan)->where('archivado', false)->first()
                : null;
            $sesion = $elegidaClase !== null
                ? SesionTenant::query()->where('ulid', $elegidaClase)->where('tipo', TipoSesionTenant::Clase->value)->with('oferta')->first()
                : null;

            return $this->presentar($persona, $producto, $sesion);
        }

        $prospecto = ProspectoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoProspecto::Convertido->value, EstadoProspecto::InvitacionEnviada->value])
            ->where(fn ($q) => $q->whereNull('convertido_en')->orWhere('convertido_en', '>=', Carbon::now()->subDays(self::DIAS)))
            ->where('updated_at', '>=', Carbon::now()->subDays(self::DIAS))
            ->with(['producto', 'sesion.oferta'])
            ->latest('id')
            ->first();
        if (! $prospecto instanceof ProspectoTenant) {
            return response()->json(['data' => null]);
        }

        return $this->presentar($persona, $prospecto->producto, $prospecto->sesion);
    }

    private function presentar(PersonaTenant $persona, ?ProductoTenant $producto, ?SesionTenant $sesion): JsonResponse
    {
        $plan = $producto instanceof ProductoTenant && ! $producto->archivado
            && ! AcuerdoTenant::query()->where('persona_id', $persona->getKey())->where('producto_comercial_id', $producto->getKey())->exists()
            ? ['id' => $producto->ulid, 'nombre' => $producto->nombre, 'precio_minor' => $producto->precio_minor, 'moneda' => $producto->moneda]
            : null;

        $clase = $sesion instanceof SesionTenant && $sesion->estado === EstadoSesionTenant::Programada && $sesion->inicia_en->isFuture()
            && ! ReservaTenant::query()->where('persona_id', $persona->getKey())->where('sesion_id', $sesion->getKey())
                ->whereNotIn('estado', [EstadoReserva::Cancelada->value, EstadoReserva::Expirada->value])->exists()
            ? ['id' => $sesion->ulid, 'nombre' => $sesion->oferta?->nombre, 'inicia_en' => $sesion->inicia_en->toIso8601String(), 'zona_horaria' => $sesion->zona_horaria,
                'utc_offset_min' => SesionTenantPresenter::horaLocal($sesion)['utc_offset_min']]
            : null;

        return response()->json(['data' => $plan === null && $clase === null ? null : ['plan' => $plan, 'clase' => $clase]]);
    }

    private static function ulid(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $valor) === 1 ? $valor : null;
    }
}
