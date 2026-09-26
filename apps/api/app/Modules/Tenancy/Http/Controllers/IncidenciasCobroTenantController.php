<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\IncidenciasCobroTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja "por conciliar" de Cobranza: lo del dinero que necesita que alguien lo
 * revise, y cómo se resolvió (quién, cuándo, qué). Una devolución incierta se
 * resuelve diciendo si la pasarela la hizo o no (se ve en su panel).
 */
class IncidenciasCobroTenantController
{
    private const LIMITE = 100;

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(['estado' => ['nullable', Rule::in([IncidenciaCobroTenant::ABIERTA, IncidenciaCobroTenant::RESUELTA])]]);

        $incidencias = IncidenciaCobroTenant::query()
            ->where('estado', $validado['estado'] ?? IncidenciaCobroTenant::ABIERTA)
            ->with(['pago.orden.persona', 'reembolso', 'resolvio'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $incidencias->map(fn (IncidenciaCobroTenant $i): array => $this->presentar($i))->all(),
        ]);
    }

    public function resolver(Request $request, ReembolsarPagoTenant $reembolsos, IncidenciasCobroTenant $incidencias, RegistrarAuditoria $auditoria): JsonResponse
    {
        $incidencia = IncidenciaCobroTenant::query()->where('ulid', (string) $request->route('incidencia'))->firstOrFail();
        $validado = $request->validate([
            'resolucion' => ['required', 'string', 'max:500'],
            // En una devolución incierta: si la pasarela la hizo o no.
            'reembolso' => ['nullable', Rule::in([EstadoReembolso::Aprobado->value, EstadoReembolso::Fallido->value])],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        if ($incidencia->estado === IncidenciaCobroTenant::RESUELTA) {
            return response()->json(['data' => $this->presentar($incidencia)]);
        }

        $reembolso = $incidencia->reembolso;
        if ($reembolso instanceof ReembolsoTenant && ! $reembolso->estado->esFinal()) {
            $resultado = EstadoReembolso::tryFrom((string) ($validado['reembolso'] ?? ''));
            if ($resultado === null) {
                throw ValidationException::withMessages(['reembolso' => ['Indica si la pasarela hizo la devolución o no.']]);
            }
            $reembolsos->resolver($reembolso, $resultado, null, $resultado === EstadoReembolso::Fallido ? 'Marcada como no hecha: '.$validado['resolucion'] : null, $actor);
        }

        $incidencias->cerrar($incidencia->refresh(), (string) $validado['resolucion'], $actor);
        $auditoria->registrar($actor, 'incidencia.resuelta', 'incidencia_cobro', (string) $incidencia->ulid, null, [
            'tipo' => $incidencia->tipo,
            'reembolso' => $validado['reembolso'] ?? null,
        ], (string) $validado['resolucion']);

        return response()->json(['data' => $this->presentar($incidencia->refresh()->load(['pago.orden.persona', 'reembolso', 'resolvio']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(IncidenciaCobroTenant $incidencia): array
    {
        $reembolso = $incidencia->reembolso;

        return [
            'id' => $incidencia->ulid,
            'tipo' => $incidencia->tipo,
            'estado' => $incidencia->estado,
            'detalle' => $incidencia->detalle,
            'fecha' => $incidencia->created_at?->toIso8601String(),
            'persona' => $incidencia->pago?->orden?->persona?->nombreCompleto(),
            'pago' => $incidencia->pago?->ulid,
            'reembolso' => $reembolso instanceof ReembolsoTenant ? [
                'id' => $reembolso->ulid,
                'estado' => $reembolso->estado->value,
                'monto_minor' => $reembolso->monto_minor,
                'moneda' => $reembolso->moneda,
                'proveedor' => $reembolso->proveedor,
                'motivo_fallo' => $reembolso->motivo_fallo,
            ] : null,
            'resuelta_en' => $incidencia->resuelta_en?->toIso8601String(),
            'resuelta_por' => $incidencia->resolvio?->name,
            'resolucion' => $incidencia->resolucion,
        ];
    }
}
