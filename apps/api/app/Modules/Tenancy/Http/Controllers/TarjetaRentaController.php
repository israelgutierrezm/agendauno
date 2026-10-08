<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * La tarjeta con que se cobra sola la renta (domiciliación, ADR 0107): guardarla en
 * Stripe, confirmarla al volver y quitarla.
 */
class TarjetaRentaController
{
    public function __construct(
        private readonly DomiciliacionRenta $domiciliacion,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** Abre la página de Stripe para guardar (o cambiar) la tarjeta. */
    public function iniciar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->domiciliacion->iniciar($this->estudio($request))], 201);
    }

    /** Al volver de Stripe: guarda la tarjeta de esa sesión. */
    public function confirmar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate(['sesion' => ['required', 'string', 'starts_with:cs_', 'max:255']]);

        $guardado = $this->domiciliacion->guardarDeSesion((string) $validado['sesion'], $estudio);
        if (! $guardado instanceof Estudio) {
            throw ValidationException::withMessages(['sesion' => ['No encontramos la tarjeta que guardaste; inténtalo de nuevo.']]);
        }
        $this->auditar($request, 'renta.tarjeta_guardada', DomiciliacionRenta::tarjeta($guardado));

        return response()->json(['data' => ['tarjeta' => DomiciliacionRenta::tarjeta($guardado)]]);
    }

    public function quitar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $antes = DomiciliacionRenta::tarjeta($estudio);
        $this->domiciliacion->quitar($estudio);
        $this->auditar($request, 'renta.tarjeta_quitada', $antes);

        return response()->json(['data' => ['tarjeta' => null]]);
    }

    /**
     * @param  array<string, mixed>|null  $tarjeta
     */
    private function auditar(Request $request, string $accion, ?array $tarjeta): void
    {
        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, $accion, 'renta', null, null, [
            'marca' => $tarjeta['marca'] ?? null,
            'ultimos4' => $tarjeta['ultimos4'] ?? null,
        ]);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
