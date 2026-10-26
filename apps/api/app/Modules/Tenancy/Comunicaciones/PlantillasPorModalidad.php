<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\ModalidadServicio;
use Illuminate\Support\Facades\DB;

/**
 * Los avisos por omisión al cliente hablan como su negocio (ADR 0131): en uno de
 * clases, «tu lugar» y «reservar»; en uno de citas, «tu cita», «tu horario» y
 * «agendar». Las plantillas se siembran con el texto de clases (migraciones de
 * comunicaciones); aquí está su versión de citas.
 *
 * {@see self::adaptar()} cambia solo las que el negocio no editó (las que siguen con el
 * texto por omisión de la otra modalidad): lo que escribió el negocio no se toca.
 */
final class PlantillasPorModalidad
{
    /**
     * clave, canal, campo y el texto de clases → el de citas.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const TEXTOS = [
        ['reserva.confirmada', 'email', 'asunto',
            'Reserva confirmada: {{actividad}} el {{fecha}}',
            'Cita confirmada: {{actividad}} el {{fecha}}'],
        ['reserva.confirmada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nTu lugar está confirmado.\n\n{{actividad}}\n{{fecha}} a las {{hora}}\n{{sucursal}}\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nTu cita está confirmada.\n\n{{actividad}}\n{{fecha}} a las {{hora}}\n{{sucursal}}\n\nSi no puedes asistir, cancélala o cámbiala aquí:\n{{enlace_cita}}\n\n{{negocio}}"],
        ['reserva.confirmada', 'push', 'asunto',
            'Reserva confirmada',
            'Cita confirmada'],
        ['cuenta.creada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nTu cuenta está lista. Desde ahí puedes reservar, ver tus créditos y tus compras:\n{{enlace}}\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nTu cuenta está lista. Desde ahí puedes agendar tus citas y ver tus bonos y tus compras:\n{{enlace}}\n\n{{negocio}}"],
        ['reserva.recordatorio_24h', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nTe recordamos tu {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nTe recordamos tu cita de {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nSi no puedes asistir, cancélala o cámbiala aquí:\n{{enlace_cita}}\n\n{{negocio}}"],
        ['reserva.cancelada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nTu reserva de {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}} quedó cancelada. {{credito}}\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nTu cita de {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}} quedó cancelada. {{credito}}\n\n{{negocio}}"],
        ['reserva.sesion_cancelada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nLamentamos avisarte que se canceló {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}}. {{credito}}\n\nPuedes reservar otro horario desde tu cuenta:\n{{enlace}}\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nLamentamos avisarte que se canceló tu cita de {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}}. {{credito}}\n\nPuedes agendar otro horario desde tu cuenta:\n{{enlace}}\n\n{{negocio}}"],
        ['reserva.reprogramada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nTu {{actividad}} cambió de horario.\n\nAntes: {{antes_fecha}} a las {{antes_hora}}\nAhora: {{fecha}} a las {{hora}} en {{sucursal}}\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nTu cita de {{actividad}} cambió de horario.\n\nAntes: {{antes_fecha}} a las {{antes_hora}}\nAhora: {{fecha}} a las {{hora}} en {{sucursal}}\n\nSi no puedes asistir, cancélala o cámbiala aquí:\n{{enlace_cita}}\n\n{{negocio}}"],
        ['reserva.apartada', 'email', 'asunto',
            'Apartamos tu lugar: {{actividad}} el {{fecha}}',
            'Apartamos tu cita: {{actividad}} el {{fecha}}'],
        ['reserva.apartada', 'email', 'cuerpo',
            "Hola {{persona_nombre}}:\n\nApartamos tu lugar para {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nPara confirmarlo, completa el pago de {{total}} antes de las {{vence}}. Si no se paga, el lugar se libera.\n\nPágalo aquí: {{enlace}}\n\n{{negocio}}",
            "Hola {{persona_nombre}}:\n\nApartamos tu cita de {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nPara confirmarla, completa el pago de {{total}} antes de las {{vence}}. Si no se paga, el horario se libera.\n\nPágalo aquí: {{enlace}}\n\n{{negocio}}"],
    ];

    /**
     * Pasa a la modalidad dada las plantillas del negocio conectado que siguen con el
     * texto por omisión de la otra. Devuelve cuántos textos cambió.
     */
    public static function adaptar(ModalidadServicio $modalidad): int
    {
        $cambios = 0;
        foreach (self::TEXTOS as [$clave, $canal, $campo, $clases, $citas]) {
            [$de, $a] = $modalidad === ModalidadServicio::Citas ? [$clases, $citas] : [$citas, $clases];
            $cambios += DB::connection('tenant')->table('plantillas_mensaje')
                ->where('clave', $clave)
                ->where('canal', $canal)
                ->where($campo, $de)
                ->update([$campo => $a, 'updated_at' => now()]);
        }

        return $cambios;
    }
}
