<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Captacion;

/**
 * En qué va un prospecto (ADR 0117). Abiertos: nuevo, en seguimiento, aprobado e
 * invitación enviada. Cerrados: convertido (ya es miembro con cuenta) y descartado.
 */
enum EstadoProspecto: string
{
    case Nuevo = 'nuevo';
    case EnSeguimiento = 'en_seguimiento';
    case Aprobado = 'aprobado';
    case InvitacionEnviada = 'invitacion_enviada';
    case Convertido = 'convertido';
    case Descartado = 'descartado';

    public function abierto(): bool
    {
        return ! in_array($this, [self::Convertido, self::Descartado], true);
    }

    /** @return list<string> */
    public static function abiertos(): array
    {
        return array_values(array_map(
            static fn (self $e): string => $e->value,
            array_filter(self::cases(), static fn (self $e): bool => $e->abierto()),
        ));
    }
}
