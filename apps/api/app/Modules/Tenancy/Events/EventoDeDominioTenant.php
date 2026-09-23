<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de dominio tenant-local ya PUBLICADO por el relay del outbox (R39). Es la
 * costura de desacople: los consumidores (comunicaciones, webhooks salientes,
 * analitica, automatizacion) se suscriben a esta clase y filtran por `tipo`, sin
 * acoplarse a los servicios que lo producen. Entrega at-least-once: los listeners
 * deben ser idempotentes (usar `eventoUlid`/`correlationId` para deduplicar).
 */
class EventoDeDominioTenant
{
    use Dispatchable;

    /**
     * Los eventos que publican los módulos del estudio. Sirven de catálogo para
     * quien se suscribe (webhooks salientes, mensajes automáticos).
     */
    public const TIPOS = [
        'reserva.creada',
        'reserva.ofrecida',
        'asistencia.marcada',
        'acceso.registrado',
        'orden.pagada',
        'pago.reembolsado',
        'cobro.fallido',
        'membresia.suspendida',
        'membresia.regularizada',
        'factura.timbrada',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventoUlid,
        public readonly string $tipo,
        public readonly string $agregadoTipo,
        public readonly ?string $agregadoId,
        public readonly array $payload,
        public readonly ?string $correlationId,
    ) {}
}
