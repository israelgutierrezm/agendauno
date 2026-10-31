<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\EntregarWebhookTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Consumidor del outbox (R40): ante un {@see EventoDeDominioTenant} publicado por el
 * relay, entrega el evento (firmado) a cada endpoint saliente ACTIVO suscrito a ese
 * tipo. Corre dentro de la conexión del tenant activa. No propaga errores de entrega
 * (los deja como `fallido` para reintento) para no afectar la publicación del outbox.
 * Si el evento se reintenta (otro consumidor falló), no vuelve a crear ni a mandar la
 * entrega que ya existe para ese endpoint: sus reintentos van por su propio relay.
 * Si el plan del negocio ya no incluye integraciones (ADR 0107), no entrega nada.
 * Corre en la cola con el negocio del evento (ADR 0232): el endpoint lento de un negocio
 * no detiene el outbox de todos.
 */
class EnviarWebhooksSalientes implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** Solo se encola con un endpoint activo: los demás eventos no hacen un trabajo. */
    public function shouldQueue(EventoDeDominioTenant $evento): bool
    {
        return WebhookSalienteTenant::query()->where('activo', true)->exists();
    }

    public function __construct(
        private readonly EntregarWebhookTenant $entregador,
        private readonly FuncionesPlan $funciones,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        $endpoints = WebhookSalienteTenant::query()
            ->where('activo', true)
            ->get()
            ->filter(fn (WebhookSalienteTenant $w): bool => $w->suscritoA($evento->tipo));

        if ($endpoints->isEmpty() || ! $this->funciones->tieneElNegocioActual('integraciones')) {
            return;
        }

        $sobre = [
            'id' => $evento->eventoUlid,
            'tipo' => $evento->tipo,
            'agregado_tipo' => $evento->agregadoTipo,
            'agregado_id' => $evento->agregadoId,
            'datos' => $evento->payload,
            'correlation_id' => $evento->correlationId,
        ];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->entregas()->where('evento_ulid', $evento->eventoUlid)->exists()) {
                continue;
            }

            $entrega = $endpoint->entregas()->create([
                'evento_ulid' => $evento->eventoUlid,
                'evento_tipo' => $evento->tipo,
                'payload' => $sobre,
                'estado' => 'pendiente',
                'intentos' => 0,
            ]);

            $this->entregador->entregar($entrega, $endpoint);
        }
    }
}
