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
    // Retención por segmentos (ADR 0159).
    case SaldoPorVencer = 'saldo_por_vencer';
    case VinoSinComprar = 'vino_sin_comprar';
    case PagoFallido = 'pago_fallido';
    case DebeRegresar = 'debe_regresar';

    /** Con las palabras de la modalidad del negocio (clases o citas; ADR 0104). */
    public function etiqueta(bool $citas = false): string
    {
        return match ($this) {
            self::PrimeraAsistencia => $citas ? 'Su primera cita' : 'Su primera clase',
            self::PlanPorVencer => 'Plan por vencer',
            self::PlanVencido => 'Plan vencido',
            self::SinAsistir => 'Sin asistir',
            self::CompraSinReservar => $citas ? 'Compró y no ha agendado' : 'Compró y no ha reservado',
            self::SaldoPorVencer => $citas ? 'Paquete por vencer con citas sin usar' : 'Paquete por vencer con clases sin usar',
            self::VinoSinComprar => 'Vino y no ha comprado',
            self::PagoFallido => 'Su pago falló',
            self::DebeRegresar => 'Ya debería regresar',
        };
    }

    /**
     * ¿Es un contacto comercial? Esos no se hacen a quien no desea promociones; los de
     * servicio (su primera clase, ayudarle a reservar lo que pagó), sí.
     */
    public function comercial(): bool
    {
        return match ($this) {
            self::PlanPorVencer, self::PlanVencido, self::SinAsistir, self::SaldoPorVencer, self::VinoSinComprar, self::DebeRegresar => true,
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
            self::SaldoPorVencer => "Hola {$nombre}, tu paquete vence el {$dato} y aún tienes citas sin usar. ¿Te ayudamos a agendarlas?",
            self::VinoSinComprar => "Hola {$nombre}, gracias por venir el {$dato}. Si quieres volver, te ayudamos a elegir un plan.",
            self::PagoFallido => "Hola {$nombre}, no pudimos cobrar tu plan el {$dato}. ¿Te ayudamos a revisar tu forma de pago?",
            self::DebeRegresar => "Hola {$nombre}, ya pasó un tiempo desde tu última visita ({$dato}). ¿Te agendamos la siguiente?",
        } : match ($this) {
            self::PrimeraAsistencia => "Hola {$nombre}, gracias por venir a tu primera clase. ¿Cómo te sentiste? Si quieres, te ayudamos a elegir tu siguiente clase.",
            self::PlanPorVencer => "Hola {$nombre}, tu plan vence el {$dato}. Si quieres seguir, te ayudamos a renovarlo.",
            self::PlanVencido => "Hola {$nombre}, tu plan venció el {$dato}. Te esperamos de vuelta cuando quieras; te ayudamos a elegir un plan.",
            self::SinAsistir => "Hola {$nombre}, te extrañamos: tu última clase fue el {$dato}. ¿Te ayudamos a apartar tu lugar en la próxima?",
            self::CompraSinReservar => "Hola {$nombre}, ya tienes tu plan listo. ¿Te ayudamos a reservar tu primera clase?",
            self::SaldoPorVencer => "Hola {$nombre}, tu paquete vence el {$dato} y aún tienes clases sin usar. ¿Te ayudamos a apartar tus lugares?",
            self::VinoSinComprar => "Hola {$nombre}, gracias por venir el {$dato}. Si quieres seguir, te ayudamos a elegir un plan.",
            self::PagoFallido => "Hola {$nombre}, no pudimos cobrar tu plan el {$dato}. ¿Te ayudamos a revisar tu forma de pago?",
            self::DebeRegresar => "Hola {$nombre}, ya pasó un tiempo desde tu última clase ({$dato}). ¿Te apartamos un lugar?",
        };
    }

    /**
     * El mensaje sugerido para el responsable de un participante sin celular propio
     * (familias, ADR 0123): le habla al responsable de su participante.
     */
    public function mensajeParaResponsable(string $responsable, string $participante, ?string $dato = null, bool $citas = false): string
    {
        $sesion = $citas ? 'cita' : 'clase';

        return match ($this) {
            self::PrimeraAsistencia => "Hola {$responsable}, gracias por traer a {$participante} a su primera {$sesion}. ¿Cómo le fue? Si quieren, les ayudamos con la siguiente.",
            self::PlanPorVencer => "Hola {$responsable}, el plan de {$participante} vence el {$dato}. Si quieren seguir, les ayudamos a renovarlo.",
            self::PlanVencido => "Hola {$responsable}, el plan de {$participante} venció el {$dato}. Los esperamos de vuelta cuando quieran; les ayudamos a elegir un plan.",
            self::SinAsistir => "Hola {$responsable}, extrañamos a {$participante}: su última {$sesion} fue el {$dato}. ¿Les ayudamos con la próxima?",
            self::CompraSinReservar => "Hola {$responsable}, el plan de {$participante} ya está listo. ¿Les ayudamos a ".($citas ? 'agendar su primera cita' : 'reservar su primera clase').'?',
            self::SaldoPorVencer => "Hola {$responsable}, el paquete de {$participante} vence el {$dato} y aún tiene {$sesion}s sin usar. ¿Les ayudamos a apartarlas?",
            self::VinoSinComprar => "Hola {$responsable}, gracias por traer a {$participante} el {$dato}. Si quieren seguir, les ayudamos a elegir un plan.",
            self::PagoFallido => "Hola {$responsable}, no pudimos cobrar el plan de {$participante} el {$dato}. ¿Les ayudamos a revisar la forma de pago?",
            self::DebeRegresar => "Hola {$responsable}, ya pasó un tiempo desde la última {$sesion} de {$participante} ({$dato}). ¿Les apartamos la siguiente?",
        };
    }
}
