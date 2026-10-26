<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\ModalidadServicio;
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
        'reserva.apartada',
        'reserva.confirmada',
        'reserva.ofrecida',
        'reserva.recordatorio_24h',
        'reserva.recordatorio_2h',
        'reserva.cancelada',
        'reserva.sesion_cancelada',
        'reserva.reprogramada',
        'asistencia.marcada',
        'acceso.registrado',
        'orden.pagada',
        'pago.reembolsado',
        'pago.anulado',
        'pago.tardio',
        'pago.duplicado',
        'cobro.fallido',
        'membresia.suspendida',
        'membresia.regularizada',
        'membresia.pausada',
        'membresia.reanudada',
        'membresia.renovacion_proxima',
        'factura.timbrada',
        'cuenta.creada',
        'privacidad.baja_solicitada',
        'resena.creada',
        'pago_automatico.solicitado',
        // Captación (ADR 0117): alguien dejó sus datos; un prospecto pasó a ser miembro.
        'prospecto.creado',
        'prospecto.convertido',
        'prospecto.sin_atender',
        // Alguien escribió a la bandeja (ADR 0200): una vez hasta que se lea.
        'bandeja.mensaje_recibido',
    ];

    /**
     * Los que solo pasan en un negocio de clases (ADR 0104): las membresías y su cobro
     * automático y la lista de espera. En uno de citas no se ofrecen para avisos,
     * webhooks ni automatizaciones.
     */
    public const SOLO_CLASES = [
        'reserva.ofrecida',
        'cobro.fallido',
        'membresia.suspendida',
        'membresia.regularizada',
        'membresia.pausada',
        'membresia.reanudada',
        'membresia.renovacion_proxima',
        'pago_automatico.solicitado',
    ];

    /**
     * Los eventos que tienen sentido en un negocio de esa modalidad.
     *
     * @return list<string>
     */
    public static function tiposPara(?ModalidadServicio $modalidad): array
    {
        return $modalidad === ModalidadServicio::Citas
            ? array_values(array_diff(self::TIPOS, self::SOLO_CLASES))
            : self::TIPOS;
    }

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
