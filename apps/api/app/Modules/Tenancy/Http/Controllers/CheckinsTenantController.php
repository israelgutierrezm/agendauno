<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\RegistrarCheckin;
use App\Modules\Tenancy\Exceptions\CheckinInvalido;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Check-ins de plataformas de bienestar (Wellhub / TotalPass) en las clases del
 * estudio. Registrar valida el codigo del usuario contra el proveedor y guarda el
 * acceso (sin consumir creditos). Una visita que el proveedor no pudo verificar queda
 * «sin verificar»; quien tiene `checkins.excepcion` la registra como excepción con su
 * motivo y lo que comprobó (M10). Un instructor solo opera sus sesiones asignadas.
 */
class CheckinsTenantController
{
    public function __construct(
        private readonly RegistrarCheckin $registrar,
        private readonly AccesoSesionTenant $acceso,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function registrar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'proveedor' => ['required', Rule::in(ProveedorPartner::valores())],
            'sesion_id' => ['required', 'string'],
            'codigo' => ['required', 'string', 'max:255'],
        ]);

        $sesion = SesionTenant::query()->where('ulid', $validado['sesion_id'])->firstOrFail();
        $usuario = $this->usuario($request);
        abort_unless($this->acceso->puedeOperar($sesion, $usuario), 403);

        $checkin = $this->registrar->ejecutar($sesion, $validado['proveedor'], $validado['codigo'], $usuario);

        return response()->json(['data' => $this->presentar($checkin)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        abort_unless($this->acceso->puedeOperar($sesion, $this->usuario($request)), 403);

        $checkins = CheckinTenant::query()
            ->with(['registradoPor', 'autorizadoPor'])
            ->where('sesion_id', $sesion->getKey())
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $checkins->map(fn (CheckinTenant $c): array => $this->presentar($c))->all(),
        ]);
    }

    /**
     * Registrar como excepción una visita que la plataforma no pudo verificar: queda
     * quién la autorizó, por qué y lo que comprobó. Una rechazada no se puede (no se
     * asume que el proveedor acepte cualquier excepción).
     */
    public function excepcion(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'evidencia' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $checkin = CheckinTenant::query()->with('sesion')->where('ulid', (string) $request->route('checkin'))->firstOrFail();
        $usuario = $this->usuario($request);
        abort_unless($checkin->sesion instanceof SesionTenant && $this->acceso->puedeOperar($checkin->sesion, $usuario), 403);

        if ($checkin->estado !== CheckinTenant::NO_VERIFICABLE) {
            throw new CheckinInvalido('Solo una visita que la plataforma no pudo verificar se registra como excepción.');
        }

        $checkin->fill([
            'estado' => CheckinTenant::EXCEPCION,
            'autorizado_por_id' => $usuario?->getKey(),
            'autorizado_en' => now(),
            'motivo_excepcion' => trim($validado['motivo']),
            'evidencia' => trim($validado['evidencia']),
        ])->save();

        $this->auditoria->registrar(
            $usuario,
            'checkin.excepcion',
            'checkin',
            (string) $checkin->ulid,
            ['estado' => CheckinTenant::NO_VERIFICABLE, 'motivo' => $checkin->motivo],
            ['estado' => CheckinTenant::EXCEPCION, 'proveedor' => $checkin->proveedor, 'sesion' => $checkin->sesion->ulid, 'evidencia' => $checkin->evidencia],
            $checkin->motivo_excepcion,
        );

        return response()->json(['data' => $this->presentar($checkin->load(['registradoPor', 'autorizadoPor']))]);
    }

    private function usuario(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CheckinTenant $checkin): array
    {
        return [
            'id' => $checkin->ulid,
            'proveedor' => $checkin->proveedor,
            'usuario' => $checkin->nombre_usuario,
            'estado' => $checkin->estado,
            // Por qué no se pudo verificar (solo si quedó sin verificar).
            'motivo' => $checkin->estado === CheckinTenant::NO_VERIFICABLE ? $checkin->motivo : null,
            'registrado_en' => $checkin->registrado_en->toIso8601String(),
            'registrado_por' => $checkin->registradoPor?->nombreCorto(),
            'autorizado_por' => $checkin->autorizadoPor?->nombreCorto(),
            'autorizado_en' => $checkin->autorizado_en?->toIso8601String(),
            'motivo_excepcion' => $checkin->motivo_excepcion,
            'evidencia' => $checkin->evidencia,
        ];
    }
}
