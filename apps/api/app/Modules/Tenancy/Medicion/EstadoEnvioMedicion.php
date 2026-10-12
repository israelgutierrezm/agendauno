<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

/**
 * El estado de una conversión que se manda a un destino (ADR 0143).
 *
 * - `enviado` quiere decir que el proveedor la aceptó, no que la atribuyó a un anuncio.
 * - `omitido` no es un error: no se manda por las preferencias de la persona, porque
 *   falta un identificador del navegador, porque pasó la ventana del proveedor o porque
 *   es una demo o un entorno de pruebas.
 */
enum EstadoEnvioMedicion: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Error = 'error';
    case Omitido = 'omitido';
}
