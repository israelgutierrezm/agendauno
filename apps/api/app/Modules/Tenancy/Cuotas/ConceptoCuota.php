<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Cuotas;

/**
 * Lo que se cobra sin ser un producto (plan de mejoras B04): no da clases ni crea un
 * acuerdo; solo se paga.
 */
enum ConceptoCuota: string
{
    case Inscripcion = 'inscripcion';
    case Anualidad = 'anualidad';
    case PenalizacionBaja = 'penalizacion_baja';
    // La mensualidad vencida de una membresía que se dio de baja: se cobra aparte, sin
    // reabrir la renovación.
    case AdeudoMensualidad = 'adeudo_mensualidad';

    /** Cómo se nombra en recibos, cobros y por cobrar. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Inscripcion => 'Inscripción',
            self::Anualidad => 'Anualidad',
            self::PenalizacionBaja => 'Penalización por baja anticipada',
            self::AdeudoMensualidad => 'Mensualidad pendiente',
        };
    }
}
