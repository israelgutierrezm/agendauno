<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

/**
 * Estado de un mensaje en su ciclo de vida: generado (encolado) → enviado, o fallido
 * (se reintenta hasta un tope).
 */
enum EstadoMensaje: string
{
    case Encolado = 'encolado';
    case Enviado = 'enviado';
    case Fallido = 'fallido';
    // No se envía: dejó de aplicar (p. ej. el recordatorio de un horario que se reprogramó).
    case Descartado = 'descartado';
}
