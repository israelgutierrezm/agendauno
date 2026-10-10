<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Captacion;

/**
 * Por qué se descartó un prospecto (plan de mejoras, C04): pocos y útiles, con «otro» y
 * un comentario breve opcional.
 */
enum MotivoCierreProspecto: string
{
    case SinHorario = 'sin_horario';
    case Precio = 'precio';
    case SinRespuesta = 'sin_respuesta';
    case NoCumple = 'no_cumple';
    case EligioOtra = 'eligio_otra';
    case Otro = 'otro';
}
