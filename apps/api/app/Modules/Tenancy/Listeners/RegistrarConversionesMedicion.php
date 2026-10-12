<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\ConversionesNegocioTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use Throwable;

/**
 * Del evento de dominio, las conversiones pendientes para el GA4 y el Meta del negocio
 * (ADR 0143). Solo las anota: se mandan aparte (`agendauno:enviar-conversiones`). Es
 * idempotente (una por destino y evento) y nunca hace fallar el despacho del outbox:
 * medir es opcional.
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class RegistrarConversionesMedicion
{
    public function __construct(private readonly ConversionesNegocioTenant $conversiones) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        try {
            $this->conversiones->desdeEvento($evento);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
