<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Devoluciones (refunds) de un pago del estudio (data plane del tenant). Total o
 * parcial, con reversión del entitlement segun politica (ver
 * {@see ReembolsarPagoTenant}). Operacion sensible: exige `motivo`, queda con actor
 * en la devolucion y en la bitacora de auditoria. Opera SIEMPRE sobre la BD del
 * estudio resuelto.
 */
class ReembolsosTenantController
{
    public function __construct(
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pago = PagoTenant::query()->where('ulid', (string) $request->route('pago'))->firstOrFail();

        $lista = $pago->reembolsos()->orderByDesc('id')->get();

        return response()->json([
            'data' => $lista->map(fn (ReembolsoTenant $r): array => $this->presentar($r))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $pago = PagoTenant::query()->where('ulid', (string) $request->route('pago'))->firstOrFail();

        $validado = $request->validate([
            'monto_minor' => ['nullable', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:255'],
            'revertir_creditos' => ['boolean'],
            // Pago en línea cuyo dinero el negocio ya devolvió por fuera.
            'manual' => ['boolean'],
            // Una por intento desde la pantalla: repetir (doble clic) no devuelve dos veces.
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $llave = $request->header('Idempotency-Key') ?: ($validado['idempotency_key'] ?? null);

        $actor = $request->attributes->get('usuario_tenant');
        $actorUsuario = $actor instanceof Usuario ? $actor : null;
        $monto = isset($validado['monto_minor']) ? (int) $validado['monto_minor'] : null;
        $revertir = (bool) ($validado['revertir_creditos'] ?? true);

        $reembolso = $this->reembolsos->ejecutar(
            $pago, $monto, $validado['motivo'], $actorUsuario, $revertir, (bool) ($validado['manual'] ?? false),
            is_string($llave) && $llave !== '' ? mb_substr($llave, 0, 100) : null,
        );

        $this->auditoria->registrar(
            $actorUsuario,
            'pago.reembolso',
            'pago',
            $pago->ulid,
            null,
            [
                'monto_minor' => $reembolso->monto_minor,
                'estado' => $reembolso->estado->value,
                'via' => $reembolso->metadata['via'] ?? null,
                'revirtio_creditos' => $reembolso->revirtio_creditos,
            ],
            $validado['motivo'],
        );

        return response()->json(['data' => $this->presentar($reembolso->refresh())], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ReembolsoTenant $reembolso): array
    {
        return [
            'id' => $reembolso->ulid,
            'monto_minor' => $reembolso->monto_minor,
            'moneda' => $reembolso->moneda,
            'estado' => $reembolso->estado->value,
            // pasarela (devuelto en línea) | manual (devuelto por fuera) | caja
            'via' => $reembolso->metadata['via'] ?? null,
            'proveedor' => $reembolso->proveedor,
            'motivo' => $reembolso->motivo,
            'revirtio_creditos' => $reembolso->revirtio_creditos,
            'referencia_externa' => $reembolso->referencia_externa,
            // Por qué no se hizo (fallida) o por qué no se sabe aún (incierta).
            'motivo_fallo' => $reembolso->motivo_fallo,
            'actor' => $reembolso->actor_nombre,
            'fecha' => $reembolso->created_at?->toIso8601String(),
            // Cuándo se devolvió de verdad (vacío mientras no se confirma).
            'aplicado_en' => $reembolso->aplicado_en?->toIso8601String(),
        ];
    }
}
