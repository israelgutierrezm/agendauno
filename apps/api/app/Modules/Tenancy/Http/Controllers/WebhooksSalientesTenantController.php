<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Integraciones\ValidarDestinoWebhook;
use App\Modules\Tenancy\Models\EntregaWebhookTenant;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Endpoints de webhooks salientes del estudio (R40): el propietario registra URLs a
 * las que AgendaUno entrega sus eventos de dominio, firmados. El `secreto` (HMAC) se
 * genera al crear y se devuelve UNA sola vez; nunca se vuelve a exponer. Opera SIEMPRE
 * sobre la BD del estudio resuelto.
 */
class WebhooksSalientesTenantController
{
    public function __construct(private readonly ValidarDestinoWebhook $destinos) {}

    private const LIMITE_ENTREGAS = 100;

    public function index(): JsonResponse
    {
        $webhooks = WebhookSalienteTenant::query()->orderByDesc('id')->get();

        return response()->json([
            'data' => $webhooks->map(fn (WebhookSalienteTenant $w): array => $this->presentar($w))->all(),
            'eventos_disponibles' => EventoDeDominioTenant::TIPOS,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'eventos' => ['nullable', 'array'],
            'eventos.*' => ['string', Rule::in(EventoDeDominioTenant::TIPOS)],
            'activo' => ['boolean'],
        ]);

        // SSRF: solo destinos públicos (se vuelve a validar en cada envío).
        $this->destinos->validar((string) $validado['url']);

        $secreto = 'whsec_'.Str::random(40);

        $webhook = WebhookSalienteTenant::query()->create([
            'url' => $validado['url'],
            'secreto' => $secreto,
            'eventos' => $validado['eventos'] ?? null,
            'activo' => (bool) ($validado['activo'] ?? true),
        ]);

        // El secreto se devuelve SOLO aqui (para configurar la verificacion de firma).
        return response()->json([
            'data' => ['secreto' => $secreto] + $this->presentar($webhook),
        ], 201);
    }

    public function eliminar(Request $request): JsonResponse
    {
        $webhook = WebhookSalienteTenant::query()->where('ulid', (string) $request->route('webhook'))->firstOrFail();
        $webhook->delete();

        return response()->json(status: 204);
    }

    public function entregas(Request $request): JsonResponse
    {
        $webhook = WebhookSalienteTenant::query()->where('ulid', (string) $request->route('webhook'))->firstOrFail();

        $entregas = $webhook->entregas()->orderByDesc('id')->limit(self::LIMITE_ENTREGAS)->get();

        return response()->json([
            'data' => $entregas->map(fn (EntregaWebhookTenant $e): array => [
                'id' => $e->ulid,
                'evento_tipo' => $e->evento_tipo,
                'evento_ulid' => $e->evento_ulid,
                'estado' => $e->estado,
                'http_status' => $e->http_status,
                'intentos' => $e->intentos,
                'ultimo_error' => $e->ultimo_error,
                'entregado_en' => $e->entregado_en?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(WebhookSalienteTenant $webhook): array
    {
        return [
            'id' => $webhook->ulid,
            'url' => $webhook->url,
            'eventos' => $webhook->eventos,
            'activo' => $webhook->activo,
        ];
    }
}
