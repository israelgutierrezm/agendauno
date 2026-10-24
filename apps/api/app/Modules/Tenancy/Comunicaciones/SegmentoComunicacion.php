<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Application\ResolverSegmentoTenant;
use App\Modules\Tenancy\Application\SegmentosRetencionTenant;

/**
 * Segmentos (audiencias dinámicas) de una difusión de comunicaciones. Cada caso se
 * resuelve EN VIVO desde los datos del estudio (sin listas guardadas): TODOS los
 * miembros vigentes, los que están POR VENCER o ya VENCIDOS (recuperables) y los
 * PRIMERIZOS (nunca marcados presente). Ver {@see ResolverSegmentoTenant}.
 *
 * Los de retención (ADR 0159) son los de {@see SegmentosRetencionTenant}, con la misma
 * clave: sin asistir, paquete por vencer con saldo, vino y no ha comprado, pago fallido
 * y ya debería regresar.
 */
enum SegmentoComunicacion: string
{
    case Todos = 'todos';
    case PorVencer = 'por_vencer';
    case Vencidos = 'vencidos';
    case Primerizos = 'primerizos';
    case SinAsistir = 'sin_asistir';
    case SinIniciar = 'sin_iniciar';
    case SaldoPorVencer = 'saldo_por_vencer';
    case VinoSinComprar = 'vino_sin_comprar';
    case PagoFallido = 'pago_fallido';
    case DebeRegresar = 'debe_regresar';

    /** ¿Aplica a un negocio de esta modalidad? (ADR 0104) */
    public function aplica(bool $citas): bool
    {
        return SegmentosRetencionTenant::aplica($this->value, $citas) || ! array_key_exists($this->value, SegmentosRetencionTenant::SEGMENTOS);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Todos los alumnos',
            self::PorVencer => 'Membresía por vencer',
            self::Vencidos => 'Membresía vencida',
            self::Primerizos => 'Primerizos (sin asistir aún)',
            self::SinAsistir => 'Con plan y sin venir',
            self::SinIniciar => 'Compró y no ha iniciado',
            self::SaldoPorVencer => 'Paquete por vencer con saldo',
            self::VinoSinComprar => 'Vino y no ha comprado',
            self::PagoFallido => 'Su pago automático falló',
            self::DebeRegresar => 'Ya debería regresar',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Todos => 'Todos los alumnos activos en el padrón.',
            self::PorVencer => 'Alumnos cuya membresía vence en los próximos 14 días.',
            self::Vencidos => 'Alumnos cuya membresía venció hace 14 días o menos (recuperables).',
            self::Primerizos => 'Alumnos que aún no registran ninguna asistencia.',
            self::SinAsistir => 'Tienen un plan en vigor y no vienen desde hace los días que fija tu negocio en Seguimiento.',
            self::SinIniciar => 'Tienen un plan en vigor, nunca han venido y no tienen nada reservado.',
            self::SaldoPorVencer => 'Su paquete vence pronto y aún les queda saldo sin usar.',
            self::VinoSinComprar => 'Vinieron hace poco y nunca han comprado un plan.',
            self::PagoFallido => 'Un cobro automático de su plan falló (en mora o suspendida).',
            self::DebeRegresar => 'Ya pasó el tiempo en que suelen repetir su servicio y no tienen otra cita.',
        };
    }
}
