<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Mis facturas (ADR 0212): las que el negocio le timbró al cliente por sus compras y
 * sus ventas del mostrador, con su PDF y su XML. También le llegan por correo.
 */
class MisFacturasTenantController
{
    public function __construct(private readonly CuentaEnUsoTenant $cuenta) {}

    public function index(Request $request): JsonResponse
    {
        $facturas = $this->deLaPersona($this->persona($request))
            ->latest('timbrada_en')
            ->limit(100)
            ->get();

        return response()->json(['data' => $facturas->map(static fn (FacturaTenant $f): array => [
            'id' => $f->ulid,
            'tipo' => $f->tipo,
            'uuid' => $f->uuid,
            'total_minor' => $f->total_minor,
            'moneda' => $f->moneda,
            'receptor_rfc' => $f->receptor_rfc,
            'timbrada_en' => $f->timbrada_en?->toIso8601String(),
            'cancelada' => $f->cancelacion_estado === EstadoCancelacion::Aceptada,
        ])->values()->all()]);
    }

    public function descargar(Request $request, ClienteFacturacion $cliente): Response
    {
        $factura = $this->deLaPersona($this->persona($request))->where('ulid', (string) $request->route('factura'))->firstOrFail();

        return FacturasTenantController::archivo($factura, (string) $request->route('formato'), DatosFiscalesTenant::query()->firstOrFail(), $cliente);
    }

    /**
     * Las timbradas de sus órdenes o de sus ventas del mostrador.
     *
     * @return Builder<FacturaTenant>
     */
    private function deLaPersona(PersonaTenant $persona): Builder
    {
        return FacturaTenant::query()
            ->where('estado', EstadoFactura::Timbrada->value)
            ->where(fn (Builder $q) => $q
                ->whereHas('orden', fn (Builder $o) => $o->where('persona_id', $persona->getKey()))
                ->orWhereHas('venta', fn (Builder $v) => $v->where('persona_id', $persona->getKey())));
    }

    private function persona(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }
}
