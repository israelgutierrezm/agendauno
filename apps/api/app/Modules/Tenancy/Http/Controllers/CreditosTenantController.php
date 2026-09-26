<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ContextoMovimiento;
use App\Modules\Tenancy\Application\CreditosTenant;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\PresentarMovimientosCreditoTenant;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ledger de creditos del estudio (data plane del tenant): consumo directo y
 * retenciones (holds) con confirmar/liberar/perder. Concurrencia protegida en el
 * servicio (lockForUpdate en la conexion del tenant). Opera SIEMPRE sobre la BD del
 * estudio resuelto.
 */
class CreditosTenantController
{
    private const LIMITE = 200;

    public function __construct(
        private readonly CreditosTenant $creditos,
        private readonly LibroMayorTenant $libro,
        private readonly PresentarMovimientosCreditoTenant $movimientos,
    ) {}

    public function consumir(Request $request): JsonResponse
    {
        $derecho = DerechoTenant::query()->where('ulid', (string) $request->route('derecho'))->firstOrFail();
        $validado = $request->validate([
            'unidades' => ['required', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $request->attributes->get('usuario_tenant');
        $movimiento = $this->creditos->consumir(
            $derecho,
            (int) $validado['unidades'],
            $validado['descripcion'] ?? null,
            ContextoMovimiento::para(OrigenMovimiento::Ajuste, null, null, $actor instanceof Usuario ? $actor : null),
        );

        return response()->json([
            'data' => [
                'movimiento' => $movimiento->ulid,
                'saldo' => $this->libro->saldo($derecho),
                'disponible' => $this->libro->disponible($derecho),
            ],
        ], 201);
    }

    /**
     * Historial auditable del ledger de un derecho: cada asiento con su origen, saldo
     * resultante, referencia (reserva/acuerdo), actor y datos extra. Más reciente
     * primero. Fuente de verdad del saldo: sigue siendo la suma de `unidades`.
     */
    public function movimientos(Request $request): JsonResponse
    {
        $derecho = DerechoTenant::query()->where('ulid', (string) $request->route('derecho'))->firstOrFail();

        $movimientos = MovimientoCreditoTenant::query()
            ->where('derecho_id', $derecho->getKey())
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $movimientos->map(fn (MovimientoCreditoTenant $m): array => $this->presentarMovimiento($m))->all(),
            'saldo' => $this->libro->saldo($derecho),
            'disponible' => $derecho->ilimitado ? null : $this->libro->disponible($derecho),
        ]);
    }

    public function retener(Request $request): JsonResponse
    {
        $derecho = DerechoTenant::query()->where('ulid', (string) $request->route('derecho'))->firstOrFail();
        $validado = $request->validate([
            'unidades' => ['required', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $retencion = $this->creditos->retener($derecho, (int) $validado['unidades'], $validado['descripcion'] ?? null);

        return response()->json([
            'data' => [
                'retencion' => $retencion->ulid,
                'saldo' => $this->libro->saldo($derecho),
                'disponible' => $this->libro->disponible($derecho),
            ],
        ], 201);
    }

    public function confirmar(Request $request): JsonResponse
    {
        $retencion = RetencionCreditoTenant::query()->where('ulid', (string) $request->route('retencion'))->firstOrFail();
        $this->creditos->confirmar($retencion, $this->contextoManual($request));

        return response()->json(['data' => $this->presentarRetencion($retencion->refresh())]);
    }

    public function liberar(Request $request): JsonResponse
    {
        $retencion = RetencionCreditoTenant::query()->where('ulid', (string) $request->route('retencion'))->firstOrFail();
        $this->creditos->liberar($retencion);

        return response()->json(['data' => $this->presentarRetencion($retencion->refresh())]);
    }

    public function perder(Request $request): JsonResponse
    {
        $retencion = RetencionCreditoTenant::query()->where('ulid', (string) $request->route('retencion'))->firstOrFail();
        $this->creditos->perder($retencion, $this->contextoManual($request));

        return response()->json(['data' => $this->presentarRetencion($retencion->refresh())]);
    }

    /**
     * Contexto de un movimiento provocado manualmente desde este controlador (ajuste
     * operativo), con el actor autenticado si lo hay.
     */
    private function contextoManual(Request $request): ContextoMovimiento
    {
        $actor = $request->attributes->get('usuario_tenant');

        return ContextoMovimiento::para(OrigenMovimiento::Ajuste, null, null, $actor instanceof Usuario ? $actor : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarMovimiento(MovimientoCreditoTenant $movimiento): array
    {
        return [
            'id' => $movimiento->ulid,
            'tipo' => $movimiento->tipo->value,
            'origen' => $movimiento->origen,
            'unidades' => $movimiento->unidades,
            'saldo_posterior' => $movimiento->saldo_posterior,
            'descripcion' => $movimiento->descripcion,
            // Por qué cambió el saldo, en palabras (el mismo texto que ve el alumno).
            'concepto' => $this->movimientos->concepto($movimiento),
            'referencia_tipo' => $movimiento->referencia_tipo,
            'referencia_id' => $movimiento->referencia_id,
            'actor' => $movimiento->actor_nombre,
            'metadata' => $movimiento->metadata,
            'fecha' => $movimiento->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarRetencion(RetencionCreditoTenant $retencion): array
    {
        $derecho = $retencion->derecho;

        return [
            'id' => $retencion->ulid,
            'unidades' => $retencion->unidades,
            'estado' => $retencion->estado->value,
            'saldo' => $this->libro->saldo($derecho),
            'disponible' => $this->libro->disponible($derecho),
        ];
    }
}
