<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ComprarMensajesWhatsApp;
use App\Modules\Tenancy\Application\MensajesWhatsAppTenant;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MovimientoWhatsAppTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp por mensaje (ADR 0186): qué avisos puede mandar el negocio (las plantillas
 * aprobadas), cuánto cuesta cada mensaje, su saldo, su tope del mes y comprar paquetes.
 */
class WhatsAppSaldoTenantController
{
    public function __construct(
        private readonly MensajesWhatsAppTenant $mensajes,
        private readonly ClienteWhatsApp $whatsapp,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'activo' => $this->whatsapp->activoPara($estudio),
            'cobra' => $this->mensajes->cobra(),
            'precio_minor' => $this->mensajes->precioMinor(),
            'moneda' => 'MXN',
            'disponibles' => $this->mensajes->disponibles(),
            'usados_mes' => $this->mensajes->usadosEsteMes(),
            'tope_mensual' => $this->mensajes->tope(),
            'paquetes' => $this->mensajes->paquetes(),
            'plantillas' => $this->mensajes->plantillas(),
            'movimientos' => MovimientoWhatsAppTenant::query()->orderByDesc('id')->limit(20)->get()
                ->map(static fn (MovimientoWhatsAppTenant $m): array => [
                    'id' => $m->ulid,
                    'tipo' => $m->tipo,
                    'cantidad' => $m->cantidad,
                    'saldo_despues' => $m->saldo_despues,
                    'detalle' => $m->detalle,
                    'fecha' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
        ]]);
    }

    public function comprar(Request $request, ComprarMensajesWhatsApp $comprar): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate([
            'cantidad' => ['required', 'integer'],
            'retorno' => ['nullable', Rule::in(['/renta', '/comunicaciones'])],
        ]);
        if (! $this->whatsapp->activoPara($estudio)) {
            throw ValidationException::withMessages(['cantidad' => ['WhatsApp no está activo en tu negocio.']]);
        }
        if (! $this->mensajes->cobra()) {
            throw ValidationException::withMessages(['cantidad' => ['Por ahora la plataforma no cobra los mensajes de WhatsApp.']]);
        }

        $cargo = $comprar->comprar($estudio, (int) $validado['cantidad'], (string) ($validado['retorno'] ?? '/renta'));

        return response()->json(['data' => [
            'id' => $cargo->ulid,
            'estado' => $cargo->estado->value,
            'monto_minor' => $cargo->monto_minor,
            'moneda' => $cargo->moneda,
            'checkout' => $cargo->checkout,
        ]], 201);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
