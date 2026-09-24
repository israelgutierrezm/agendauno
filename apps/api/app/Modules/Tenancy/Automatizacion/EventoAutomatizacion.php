<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Automatizacion;

use App\Modules\Tenancy\Events\EventoDeDominioTenant;

/**
 * Catálogo de eventos de dominio que pueden disparar una automatización (R16). Son un
 * subconjunto curado de los eventos del outbox ({@see EventoDeDominioTenant})
 * que llevan datos accionables para generar tareas de seguimiento.
 */
enum EventoAutomatizacion: string
{
    case ReservaCreada = 'reserva.creada';
    case ReservaOfrecida = 'reserva.ofrecida';
    case PagoReembolsado = 'pago.reembolsado';
    case AccesoRegistrado = 'acceso.registrado';
    case FacturaTimbrada = 'factura.timbrada';
    case MembresiaSuspendida = 'membresia.suspendida';
    case MembresiaRegularizada = 'membresia.regularizada';
}
