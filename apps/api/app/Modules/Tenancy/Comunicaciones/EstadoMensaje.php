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
    // Tomado por una pasada del envío (ADR 0209): se está entregando. Si la pasada se
    // corta antes de anotar cómo terminó, no se vuelve a mandar (como máximo una vez):
    // al rato queda fallido sin reintento. Es un estado interno: el listado lo muestra
    // como «en cola».
    case Enviando = 'enviando';
    case Enviado = 'enviado';
    case Fallido = 'fallido';
    // Resultado incierto (ADR 0227): el proveedor pudo haberlo recibido (se cortó la
    // comunicación después de mandarlo, o la pasada se cortó). No se reintenta solo: un
    // aviso repetido es peor; se revisa y, si hace falta, se reenvía a mano.
    case Incierto = 'incierto';
    // No se envía: dejó de aplicar (p. ej. el recordatorio de un horario que se reprogramó).
    case Descartado = 'descartado';
}
