<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\RegistrarCheckin;
use App\Modules\Tenancy\Application\ReservasPlataformaTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Las reservas que llegaron desde la app de Wellhub, TotalPass o Fitpass, en la lista de
 * la clase (ADR 0202): recepción ve quién reservó, marca que llegó (Wellhub con su
 * Wellhub ID ya guardado, sin teclear sus 13 dígitos; TotalPass lo valida solo en su
 * app) y puede cancelarla (la plataforma le avisa a la persona).
 */
class LugaresPlataformaTenantController
{
    public function __construct(
        private readonly ReservasPlataformaTenant $reservas,
        private readonly RegistrarCheckin $checkins,
        private readonly AccesoSesionTenant $acceso,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * Las reservas de plataforma vigentes o atendidas de una clase, para su lista.
     *
     * @return list<array<string, mixed>>
     */
    public static function deLaSesion(SesionTenant $sesion): array
    {
        return LugarPlataformaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->whereIn('estado', [LugarPlataformaTenant::RESERVADO, LugarPlataformaTenant::ASISTIO, LugarPlataformaTenant::NO_ASISTIO])
            ->with('visitante')
            ->orderBy('id')
            ->get()
            ->map(static fn (LugarPlataformaTenant $l): array => [
                'id' => $l->ulid,
                'proveedor' => $l->proveedor,
                'nombre' => $l->visitante->nombre ?? $l->visitante->id_externo ?? null,
                'estado' => $l->estado,
                // TotalPass valida la llegada sola (check-in en su app).
                'puede_marcar' => $l->estado === LugarPlataformaTenant::RESERVADO && $l->proveedor !== ProveedorPartner::TotalPass->value,
            ])->values()->all();
    }

    public function llego(Request $request): JsonResponse
    {
        $lugar = $this->lugar($request);
        $usuario = $this->usuario($request);
        if ($lugar->estado !== LugarPlataformaTenant::RESERVADO) {
            throw ValidationException::withMessages(['lugar' => ['Esa reserva ya no está pendiente de llegada.']]);
        }
        $codigo = match ($lugar->proveedor) {
            ProveedorPartner::Wellhub->value => (string) $lugar->visitante?->id_externo,
            ProveedorPartner::Fitpass->value => substr((string) $lugar->referencia_externa, strlen(ReservasPlataformaTenant::referencia(ProveedorPartner::Fitpass, ''))),
            default => throw ValidationException::withMessages(['lugar' => ['La persona hace check-in en la app de TotalPass: la visita se valida sola.']]),
        };
        $checkin = $this->checkins->ejecutar($lugar->sesion, $lugar->proveedor, $codigo, $usuario);

        return response()->json(['data' => ['checkin' => $checkin->ulid, 'estado' => $checkin->estado]], 201);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $lugar = $this->lugar($request);
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:250']]);
        $motivo = trim((string) ($validado['motivo'] ?? '')) ?: 'El negocio canceló tu reserva.';
        $error = $this->reservas->cancelarPorElNegocio($lugar, $motivo);
        if ($error !== null) {
            throw ValidationException::withMessages(['lugar' => [$error]]);
        }
        $this->auditoria->registrar($this->usuario($request), 'plataforma.reserva_cancelada', 'lugar_plataforma', (string) $lugar->ulid, null, ['proveedor' => $lugar->proveedor, 'motivo' => $motivo]);

        return response()->json(['data' => ['id' => $lugar->ulid, 'estado' => $lugar->estado]]);
    }

    private function lugar(Request $request): LugarPlataformaTenant
    {
        $lugar = LugarPlataformaTenant::query()->where('ulid', (string) $request->route('lugar'))->with(['sesion', 'visitante'])->firstOrFail();
        abort_unless($lugar->sesion instanceof SesionTenant && $this->acceso->puedeOperar($lugar->sesion, $this->usuario($request)), 403);

        return $lugar;
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}
