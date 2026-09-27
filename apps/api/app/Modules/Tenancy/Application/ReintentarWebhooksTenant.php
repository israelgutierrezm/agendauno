<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\EntregaWebhookTenant;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;

/**
 * Reintenta las entregas de webhook FALLIDAS de la BD del tenant (R40) que no han
 * agotado sus intentos, hasta un tope defensivo (evita reintentar en bucle un endpoint
 * muerto). Debe correr con la conexión del tenant ya activa (ver el comando que lo
 * orquesta). Reusa {@see EntregarWebhookTenant} (firma + POST + registro).
 */
class ReintentarWebhooksTenant
{
    public const MAX_INTENTOS = 6;

    private const LOTE = 500;

    public function __construct(private readonly EntregarWebhookTenant $entregador) {}

    public function ejecutar(): int
    {
        $reintentadas = 0;

        EntregaWebhookTenant::query()
            ->where('estado', 'fallido')
            ->where('intentos', '<', self::MAX_INTENTOS)
            ->with('webhook')
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(function (EntregaWebhookTenant $entrega) use (&$reintentadas): void {
                $endpoint = $entrega->webhook;
                if (! $endpoint instanceof WebhookSalienteTenant || ! $endpoint->activo) {
                    return;
                }

                $this->entregador->entregar($entrega, $endpoint);
                $reintentadas++;
            });

        return $reintentadas;
    }
}
