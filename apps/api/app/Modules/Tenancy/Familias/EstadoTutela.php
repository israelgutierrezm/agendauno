<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Familias;

/**
 * El vínculo responsable → participante (B05): pendiente hasta que el responsable acepta
 * la autorización; activo mientras no la retire; retirado ya no da acceso.
 */
enum EstadoTutela: string
{
    case Pendiente = 'pendiente';
    case Activa = 'activa';
    case Retirada = 'retirada';
}
