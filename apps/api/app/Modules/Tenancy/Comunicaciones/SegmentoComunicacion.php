<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Application\ResolverSegmentoTenant;

/**
 * Segmentos (audiencias dinámicas) de una difusión de comunicaciones. Cada caso se
 * resuelve EN VIVO desde los datos del estudio (sin listas guardadas): TODOS los
 * miembros vigentes, los que están POR VENCER o ya VENCIDOS (recuperables) y los
 * PRIMERIZOS (nunca marcados presente). Ver {@see ResolverSegmentoTenant}.
 */
enum SegmentoComunicacion: string
{
    case Todos = 'todos';
    case PorVencer = 'por_vencer';
    case Vencidos = 'vencidos';
    case Primerizos = 'primerizos';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Todos los miembros',
            self::PorVencer => 'Membresía por vencer',
            self::Vencidos => 'Membresía vencida',
            self::Primerizos => 'Primerizos (sin asistir aún)',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Todos => 'Todas las personas con perfil de miembro, activas en el padrón.',
            self::PorVencer => 'Miembros cuya membresía vence en los próximos 14 días.',
            self::Vencidos => 'Miembros cuya membresía venció hace 14 días o menos (recuperables).',
            self::Primerizos => 'Miembros que aún no registran ninguna asistencia.',
        };
    }
}
