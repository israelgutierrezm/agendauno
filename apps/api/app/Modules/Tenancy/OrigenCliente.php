<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Cómo conoció el cliente al negocio (ADR 0067): lo dice al agendar su primera cita
 * en línea y queda en su ficha. Sirve para saber qué canal le trae clientes.
 */
enum OrigenCliente: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Tiktok = 'tiktok';
    case Google = 'google';
    case Recomendacion = 'recomendacion';
    case PasoPorAqui = 'paso_por_aqui';
    case Otro = 'otro';
}
