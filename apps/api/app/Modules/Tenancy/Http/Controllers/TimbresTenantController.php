<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AcreditarTimbresPagados;
use App\Modules\Tenancy\Application\ComprarTimbres;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Los timbres del negocio para facturar a sus clientes (ADR 0107): cuántos le quedan,
 * sus movimientos y comprar un paquete (en la página de Stripe).
 */
class TimbresTenantController
{
    public function __construct(
        private readonly TimbresTenant $timbres,
        private readonly AcreditarTimbresPagados $acreditar,
        private readonly FuncionesPlan $funciones,
        private readonly RegionNegocioTenant $region,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->acreditar->sincronizar($estudio);

        return response()->json(['data' => [
            'disponibles' => $this->timbres->disponibles(),
            'precio_timbre_minor' => $this->timbres->precioTimbreMinor(),
            'iva_porcentaje' => (int) (TarifaSaas::vigente($estudio->modalidad())->definicion['iva_porcentaje'] ?? 16),
            'moneda' => 'MXN',
            'paquetes' => $this->timbres->paquetes(),
            'posible' => $this->motivo($estudio) === null,
            'motivo' => $this->motivo($estudio),
            'movimientos' => $this->timbres->movimientos(),
        ]]);
    }

    public function comprar(Request $request, ComprarTimbres $comprar): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate(['cantidad' => ['required', 'integer']]);
        $motivo = $this->motivo($estudio);
        if ($motivo !== null) {
            throw ValidationException::withMessages(['cantidad' => [$motivo]]);
        }

        $cargo = $comprar->comprar($estudio, (int) $validado['cantidad']);

        return response()->json(['data' => [
            'id' => $cargo->ulid,
            'estado' => $cargo->estado->value,
            'monto_minor' => $cargo->monto_minor,
            'moneda' => $cargo->moneda,
            'checkout' => $cargo->checkout,
        ]], 201);
    }

    /** Por qué no puede comprar timbres (null si puede). */
    private function motivo(Estudio $estudio): ?string
    {
        // Sin FacturAPI en la plataforma no se timbra: no se venden timbres que no sirven.
        if (! ConfiguracionPlataforma::facturacionDisponible()) {
            return FacturacionNoConfigurada::MOTIVO;
        }
        if (! $this->region->factura()) {
            return RegionNegocioTenant::MOTIVO_FACTURACION;
        }
        if (! $this->funciones->tiene($estudio, 'facturacion')) {
            return 'La facturación está en el plan Pro.';
        }

        return null;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
