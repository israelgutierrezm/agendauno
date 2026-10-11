<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Automatizacion;

/**
 * Por qué conviene contactar a alguien (plan de mejoras M09/C06/C05): señales que el
 * sistema verifica, no suposiciones como «cliente perdido».
 */
enum MotivoSeguimiento: string
{
    case PrimeraAsistencia = 'primera_asistencia';
    case PlanPorVencer = 'plan_por_vencer';
    case PlanVencido = 'plan_vencido';
    case SinAsistir = 'sin_asistir';
    case CompraSinReservar = 'compro_sin_reservar';

    /** Con las palabras de la modalidad del negocio (clases o citas; ADR 0104). */
    public function etiqueta(bool $citas = false): string
    {
        return match ($this) {
            self::PrimeraAsistencia => $citas ? 'Su primera cita' : 'Su primera clase',
            self::PlanPorVencer => 'Plan por vencer',
            self::PlanVencido => 'Plan vencido',
            self::SinAsistir => 'Sin asistir',
            self::CompraSinReservar => $citas ? 'Compró y no ha agendado' : 'Compró y no ha reservado',
        };
    }

    /**
     * ¿Es un contacto comercial? Esos no se hacen a quien no desea promociones; los de
     * servicio (su primera clase, ayudarle a reservar lo que pagó), sí.
     */
    public function comercial(): bool
    {
        return match ($this) {
            self::PlanPorVencer, self::PlanVencido, self::SinAsistir => true,
            default => false,
        };
    }

    /** Un mensaje sugerido, para editarlo antes de enviarlo. */
    public function mensajeSugerido(string $nombre, ?string $dato = null, bool $citas = false): string
    {
        return $citas ? match ($this) {
            self::PrimeraAsistencia => "Hola {$nombre}, gracias por venir a tu primera cita. ¿Cómo te sentiste? Si quieres, te ayudamos a agendar la siguiente.",
            self::PlanPorVencer => "Hola {$nombre}, tu plan vence el {$dato}. Si quieres seguir, te ayudamos a renovarlo.",
            self::PlanVencido => "Hola {$nombre}, tu plan venció el {$dato}. Te esperamos de vuelta cuando quieras; te ayudamos a elegir un plan.",
            self::SinAsistir => "Hola {$nombre}, te extrañamos: tu última cita fue el {$dato}. ¿Te ayudamos a agendar la próxima?",
            self::CompraSinReservar => "Hola {$nombre}, ya tienes tu plan listo. ¿Te ayudamos a agendar tu primera cita?",
        } : match ($this) {
            self::PrimeraAsistencia => "Hola {$nombre}, gracias por venir a tu primera clase. ¿Cómo te sentiste? Si quieres, te ayudamos a elegir tu siguiente clase.",
            self::PlanPorVencer => "Hola {$nombre}, tu plan vence el {$dato}. Si quieres seguir, te ayudamos a renovarlo.",
            self::PlanVencido => "Hola {$nombre}, tu plan venció el {$dato}. Te esperamos de vuelta cuando quieras; te ayudamos a elegir un plan.",
            self::SinAsistir => "Hola {$nombre}, te extrañamos: tu última clase fue el {$dato}. ¿Te ayudamos a apartar tu lugar en la próxima?",
            self::CompraSinReservar => "Hola {$nombre}, ya tienes tu plan listo. ¿Te ayudamos a reservar tu primera clase?",
        };
    }
}
