<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Política de reserva de una oferta (citas): cómo se HABILITA una reserva.
 *
 * - `Entitlement`: consume una membresía/pack (créditos) — estudios de pole/gym.
 * - `Pago`: exige un PAGO en línea por la sesión antes de confirmar (pago-para-reservar)
 *   — barberías/servicios por cita.
 *
 * Conviven en el MISMO core (configuración por oferta, no una rama por industria): un
 * estudio puede tener ofertas por membresía y ofertas de pago-para-reservar a la vez.
 */
enum PoliticaReservaTenant: string
{
    case Entitlement = 'entitlement';
    case Pago = 'pago';
}
