<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CobrarCargoRenta;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pago de la RENTA del SaaS por parte del dueño (plataforma -> dueño), con la pasarela
 * de la plataforma. Es el espejo de {@see OrdenesTenantController::cobrar} (alumno ->
 * estudio): el cobro en linea queda `pendiente` con la referencia del intento y el
 * webhook de la plataforma lo confirma. Actua sobre el control plane.
 */
class PagoRentaController
{
    public function __construct(
        private readonly CobrarCargoRenta $cobrar,
        private readonly RegistroDePasarelasPlataforma $registro,
    ) {}

    /**
     * Al volver de pagar la renta (o una compra de timbres o de mensajes): si el cargo ya
     * se confirmó, para decir «Pago confirmado» solo entonces.
     */
    public function confirmacion(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('cargo'))
            ->firstOrFail();

        return response()->json(['data' => ['estado' => match ($cargo->estado) {
            EstadoCargoRenta::Pagado => 'confirmado',
            EstadoCargoRenta::Cancelado => 'no_completado',
            default => 'en_proceso',
        }]]);
    }

    public function pagar(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $validado = $request->validate([
            'proveedor' => ['nullable', Rule::in(['stripe', 'mercadopago', 'openpay'])],
            // A dónde vuelve tras pagar: Timbres, para una compra de timbres (con su aviso).
            'retorno' => ['nullable', Rule::in(['/renta', '/renta/timbres'])],
        ]);

        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('cargo'))
            ->firstOrFail();

        // Proveedor explicito o, si no se indica, la primera pasarela activa de la plataforma.
        $proveedor = ($validado['proveedor'] ?? '') !== ''
            ? (string) $validado['proveedor']
            : $this->registro->primeraActiva();

        if ($proveedor === null || $proveedor === '') {
            throw new PasarelaNoDisponible('La plataforma no tiene una pasarela de cobro activa.');
        }

        $cargo->retorno = $validado['retorno'] ?? null;
        $pagado = $this->cobrar->ejecutar($cargo, $proveedor);

        return response()->json(['data' => [
            'id' => $pagado->ulid,
            'estado' => $pagado->estado->value,
            'proveedor' => $proveedor,
            'referencia' => $pagado->referencia_pago,
            'checkout' => $pagado->checkout,
        ]], 201);
    }
}
