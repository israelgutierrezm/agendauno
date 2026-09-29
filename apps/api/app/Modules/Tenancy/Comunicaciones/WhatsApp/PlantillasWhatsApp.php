<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\WhatsApp;

/**
 * Avisos que se pueden mandar por WhatsApp (ADR 0069). Meta solo deja iniciar una
 * conversación con plantillas aprobadas, así que el texto es fijo (el negocio solo
 * enciende o apaga cada aviso) y la plataforma las registra una vez en su cuenta de
 * WhatsApp Business, categoría "Utilidad", idioma español (MEX), con estos nombres.
 *
 * El texto usa los mismos marcadores que las demás plantillas ({{persona_nombre}},
 * {{fecha}}, …): es la vista previa del negocio y lo que se guarda en la bandeja. Para
 * Meta cada marcador se vuelve {{1}}, {{2}}, … en el orden en que aparece. Ninguno
 * abre ni cierra el texto (Meta lo rechaza) y ninguno se repite.
 */
final class PlantillasWhatsApp
{
    /**
     * Código para que un dueño confirme su número al registrarse (ADR 0070).
     */
    public const CODIGO_VERIFICACION = 'agendauno_codigo_verificacion';

    /**
     * @var array<string, array{nombre: string, titulo: string, texto: string}>
     */
    private const CATALOGO = [
        'reserva.confirmada' => [
            'nombre' => 'agendauno_reserva_confirmada',
            'titulo' => 'Reserva confirmada',
            'texto' => 'Hola {{persona_nombre}}, {{negocio}} confirmó tu lugar en {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}. Si no puedes asistir, cancela con tiempo desde tu cuenta.',
        ],
        'reserva.apartada' => [
            'nombre' => 'agendauno_lugar_apartado',
            'titulo' => 'Lugar apartado',
            'texto' => 'Hola {{persona_nombre}}, {{negocio}} apartó tu lugar en {{actividad}} el {{fecha}} a las {{hora}}. Para confirmarlo, paga {{total}} antes de las {{vence}} en este enlace: {{enlace}} Si no se paga a tiempo, el lugar se libera.',
        ],
        'reserva.recordatorio_24h' => [
            'nombre' => 'agendauno_recordatorio_24h',
            'titulo' => 'Recordatorio un día antes',
            'texto' => 'Hola {{persona_nombre}}, te recordamos tu lugar en {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}} de {{negocio}}. Si no puedes asistir, cancela con tiempo desde tu cuenta.',
        ],
        'reserva.recordatorio_2h' => [
            'nombre' => 'agendauno_recordatorio_2h',
            'titulo' => 'Recordatorio unas horas antes',
            'texto' => 'Hola {{persona_nombre}}, en un rato tienes {{actividad}} a las {{hora}} en {{sucursal}} de {{negocio}}. Te esperamos.',
        ],
        'reserva.reprogramada' => [
            'nombre' => 'agendauno_reserva_reprogramada',
            'titulo' => 'Reserva reprogramada',
            'texto' => 'Hola {{persona_nombre}}, tu lugar en {{actividad}} cambió al {{fecha}} a las {{hora}} en {{sucursal}}. Si el nuevo horario no te queda, avísale a {{negocio}} lo antes posible.',
        ],
        'reserva.cancelada' => [
            'nombre' => 'agendauno_reserva_cancelada',
            'titulo' => 'Reserva cancelada',
            'texto' => 'Hola {{persona_nombre}}, se canceló tu lugar en {{actividad}} del {{fecha}} a las {{hora}} en {{negocio}}. Puedes volver a agendar cuando quieras.',
        ],
        'reserva.sesion_cancelada' => [
            'nombre' => 'agendauno_sesion_cancelada',
            'titulo' => 'El negocio canceló',
            'texto' => 'Hola {{persona_nombre}}, {{negocio}} canceló {{actividad}} del {{fecha}} a las {{hora}}. Lamentamos el cambio; puedes elegir otro horario desde tu cuenta.',
        ],
    ];

    /**
     * @return array{nombre: string, titulo: string, texto: string}|null
     */
    public static function para(string $evento): ?array
    {
        return self::CATALOGO[$evento] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function eventos(): array
    {
        return array_keys(self::CATALOGO);
    }

    /**
     * Los marcadores del texto en orden: el primero es {{1}} para Meta.
     *
     * @return list<string>
     */
    public static function marcadores(string $texto): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $texto, $coincidencias);

        return array_values(array_unique($coincidencias[1]));
    }

    /**
     * El texto como se registra en Meta: {{persona_nombre}} → {{1}}, …
     */
    public static function textoParaMeta(string $texto): string
    {
        foreach (self::marcadores($texto) as $i => $marcador) {
            $texto = str_replace('{{'.$marcador.'}}', '{{'.($i + 1).'}}', $texto);
        }

        return $texto;
    }

    /**
     * Los valores de {{1}}, {{2}}, … para un aviso. Meta no acepta un valor vacío ni
     * con saltos de línea, tabuladores o más de cuatro espacios seguidos.
     *
     * @param  array<string, string>  $contexto
     * @return list<string>
     */
    public static function parametros(string $texto, array $contexto): array
    {
        return array_map(static function (string $marcador) use ($contexto): string {
            $valor = trim((string) preg_replace('/\s+/u', ' ', $contexto[$marcador] ?? ''));

            return $valor === '' ? '-' : mb_substr($valor, 0, 200);
        }, self::marcadores($texto));
    }

    /**
     * Para el superadministrador: qué registrar en Meta para los avisos de los
     * negocios a sus clientes.
     *
     * @return list<array{evento: string, nombre: string, titulo: string, texto: string, idioma: string, categoria: string}>
     */
    public static function paraNegocios(): array
    {
        $lista = [];
        foreach (self::CATALOGO as $evento => $plantilla) {
            $lista[] = [
                'evento' => $evento,
                'nombre' => $plantilla['nombre'],
                'titulo' => $plantilla['titulo'],
                'texto' => self::textoParaMeta($plantilla['texto']),
                'idioma' => ClienteWhatsApp::IDIOMA,
                'categoria' => 'UTILITY',
            ];
        }

        return $lista;
    }

    /**
     * Para el superadministrador: qué registrar en Meta para hablar con los dueños
     * (ADR 0070). El código de verificación usa la categoría Autenticación: Meta pone
     * el texto, con recomendación de seguridad, vigencia de 10 minutos y botón «Copiar
     * código».
     *
     * @return list<array{evento: string, nombre: string, titulo: string, texto: string, idioma: string, categoria: string}>
     */
    public static function paraDuenos(): array
    {
        return [[
            'evento' => 'registro.codigo',
            'nombre' => self::CODIGO_VERIFICACION,
            'titulo' => 'Código de verificación',
            'texto' => '{{1}} es tu código de verificación. Por tu seguridad, no lo compartas. Este código caduca en 10 minutos.',
            'idioma' => ClienteWhatsApp::IDIOMA,
            'categoria' => 'AUTHENTICATION',
        ]];
    }
}
