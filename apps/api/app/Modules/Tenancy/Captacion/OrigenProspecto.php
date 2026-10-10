<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Captacion;

/**
 * Por dónde llegó un prospecto: un formulario de captación (sitio, enlace, QR o
 * embebido) o capturado a mano por el equipo.
 */
enum OrigenProspecto: string
{
    case Formulario = 'formulario';
    case Manual = 'manual';
}
