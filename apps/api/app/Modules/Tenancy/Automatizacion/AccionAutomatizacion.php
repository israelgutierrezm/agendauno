<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Automatizacion;

/**
 * Acción que ejecuta una automatización al cumplirse su disparador y condición (R16).
 * Por ahora una sola: crear una tarea de seguimiento para el staff (el envío de
 * mensajes ya lo cubre Comunicaciones/R28). El enum deja lugar a más acciones.
 */
enum AccionAutomatizacion: string
{
    case CrearTarea = 'crear_tarea';
}
