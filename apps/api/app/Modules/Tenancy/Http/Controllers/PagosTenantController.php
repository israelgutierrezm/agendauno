<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CorregirMetodoPagoTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pagos capturados del estudio (data plane del tenant): base de la pantalla de
 * cobranza para consultar cobros y emitir reembolsos. Solo pagos con dinero recibido
 * (aprobados / total o parcialmente reembolsados); los pendientes/rechazados no
 * aplican. Incluye el monto ya reembolsado y el reembolsable restante.
 */
class PagosTenantController
{
    private const LIMITE = 200;

    public function index(): JsonResponse
    {
        $pagos = PagoTenant::query()
            ->whereIn('estado', [
                EstadoPago::Aprobado->value,
                EstadoPago::ParcialmenteReembolsado->value,
                EstadoPago::Reembolsado->value,
            ])
            ->with(['orden.persona', 'registradoPor'])
            // Devuelto de verdad (aprobado) y lo comprometido (incluye lo que está en curso);
            // una devolución fallida no cuenta.
            ->withSum(['reembolsos as reembolsado_minor' => fn ($q) => $q->where('estado', EstadoReembolso::Aprobado->value)], 'monto_minor')
            ->withSum(['reembolsos as comprometido_minor' => fn ($q) => $q->whereIn('estado', EstadoReembolso::comprometidos())], 'monto_minor')
            ->withCount('reembolsos')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $pagos->map(function (PagoTenant $pago): array {
                $reembolsado = (int) ($pago->getAttribute('reembolsado_minor') ?? 0);
                $comprometido = (int) ($pago->getAttribute('comprometido_minor') ?? 0);

                return [
                    'id' => $pago->ulid,
                    'fecha' => $pago->created_at?->toIso8601String(),
                    'persona' => $pago->orden?->persona?->nombreCompleto(),
                    'monto_minor' => $pago->monto_minor,
                    'moneda' => $pago->moneda,
                    'estado' => $pago->estado->value,
                    'proveedor' => $pago->proveedor,
                    'metodo' => $pago->metodo?->value,
                    'reembolsado_minor' => $reembolsado,
                    'reembolsable_minor' => max(0, $pago->monto_minor - $comprometido),
                    'con_reembolsos' => (int) $pago->getAttribute('reembolsos_count') > 0,
                    'registrado_por' => $pago->registradoPor?->name,
                ];
            })->all(),
        ]);
    }

    /**
     * Corrige la forma de pago de un cobro en caja (ADR 0086): mismo monto, otra forma.
     * Con motivo opcional; queda en la bitácora.
     */
    public function corregirMetodo(Request $request, CorregirMetodoPagoTenant $corregir): JsonResponse
    {
        $pago = PagoTenant::query()->where('ulid', (string) $request->route('pago'))->firstOrFail();
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(CorregirMetodoPagoTenant::METODOS)],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');

        $pago = $corregir->corregir(
            $pago,
            (string) $validado['metodo'],
            $actor instanceof Usuario ? $actor : null,
            $validado['motivo'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $pago->ulid,
            'metodo' => $pago->orden->metodo_pago ?? $pago->metodo->value,
        ]]);
    }
}
