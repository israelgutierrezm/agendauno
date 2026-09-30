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
     * Avisos de la plataforma a los dueños (ADR 0071). Marcadores: {{nombre}} (del
     * dueño), {{negocio}}, {{fecha}}, {{periodo}}, {{monto}} y {{enlace}} (a la renta
     * en su panel).
     *
     * @var array<string, array{nombre: string, titulo: string, asunto: string, texto: string}>
     */
    private const DUENOS = [
        'prueba_por_terminar' => [
            'nombre' => 'agendauno_prueba_por_terminar',
            'titulo' => 'Prueba gratis por terminar',
            'asunto' => 'Tu prueba gratis de AgendaUno termina el {{fecha}}',
            'texto' => 'Hola {{nombre}}, la prueba gratis de {{negocio}} en AgendaUno termina el {{fecha}}. Desde ese día lo que uses cuenta para tu renta mensual; revisa tu plan aquí: {{enlace}} Gracias por usar AgendaUno.',
        ],
        'renta_emitida' => [
            'nombre' => 'agendauno_renta_emitida',
            'titulo' => 'Renta lista para pagar',
            'asunto' => 'Tu renta de {{periodo}} está lista',
            'texto' => 'Hola {{nombre}}, ya está la renta de {{negocio}} de {{periodo}}: {{monto}}, con vencimiento el {{fecha}}. Puedes pagarla aquí: {{enlace}} Gracias por usar AgendaUno.',
        ],
        'renta_vencida' => [
            'nombre' => 'agendauno_renta_vencida',
            'titulo' => 'Renta vencida',
            'asunto' => 'Tu renta de {{periodo}} venció',
            'texto' => 'Hola {{nombre}}, la renta de {{negocio}} de {{periodo}} por {{monto}} venció el {{fecha}}. Págala aquí para mantener tu cuenta al corriente: {{enlace}} Si ya la pagaste, ignora este mensaje.',
        ],
        'suspension_proxima' => [
            'nombre' => 'agendauno_suspension_proxima',
            'titulo' => 'Suspensión próxima por renta',
            'asunto' => 'Tu negocio se suspenderá el {{fecha}}',
            'texto' => 'Hola {{nombre}}, la renta de {{negocio}} de {{periodo}} por {{monto}} sigue sin pagarse. Si no se paga antes del {{fecha}}, el negocio se suspenderá y nadie podrá agendar. Págala aquí: {{enlace}} Si ya la pagaste, ignora este mensaje.',
        ],
        'cuenta_suspendida' => [
            'nombre' => 'agendauno_cuenta_suspendida',
            'titulo' => 'Negocio suspendido por renta',
            'asunto' => 'Suspendimos {{negocio}} por la renta sin pagar',
            'texto' => 'Hola {{nombre}}, suspendimos {{negocio}} en AgendaUno porque la renta de {{periodo}} por {{monto}} sigue sin pagarse. Entra y págala aquí para reactivarlo al momento: {{enlace}} Tu información está a salvo.',
        ],
        'pago_recibido' => [
            'nombre' => 'agendauno_pago_recibido',
            'titulo' => 'Pago de la renta recibido',
            'asunto' => 'Recibimos tu pago de {{periodo}}',
            'texto' => 'Hola {{nombre}}, recibimos el pago de la renta de {{negocio}} de {{periodo}} por {{monto}}. Puedes ver tu recibo y pedir tu factura aquí: {{enlace}} Gracias por usar AgendaUno.',
        ],
    ];

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
     * El aviso de la plataforma a un dueño de ese tipo (ADR 0071): nombre en Meta,
     * asunto del correo y texto (el mismo por correo y por WhatsApp).
     *
     * @return array{nombre: string, titulo: string, asunto: string, texto: string}|null
     */
    public static function paraDueno(string $tipo): ?array
    {
        return self::DUENOS[$tipo] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function tiposDeDuenos(): array
    {
        return array_keys(self::DUENOS);
    }

    /**
     * Para el superadministrador: qué registrar en Meta para hablar con los dueños.
     * El código de verificación (ADR 0070) usa la categoría Autenticación: Meta pone
     * el texto, con recomendación de seguridad, vigencia de 10 minutos y botón «Copiar
     * código». Los avisos (ADR 0071) son de Utilidad.
     *
     * @return list<array{evento: string, nombre: string, titulo: string, texto: string, idioma: string, categoria: string}>
     */
    public static function paraDuenos(): array
    {
        $lista = [[
            'evento' => 'registro.codigo',
            'nombre' => self::CODIGO_VERIFICACION,
            'titulo' => 'Código de verificación',
            'texto' => '{{1}} es tu código de verificación. Por tu seguridad, no lo compartas. Este código caduca en 10 minutos.',
            'idioma' => ClienteWhatsApp::IDIOMA,
            'categoria' => 'AUTHENTICATION',
        ]];
        foreach (self::DUENOS as $tipo => $plantilla) {
            $lista[] = [
                'evento' => $tipo,
                'nombre' => $plantilla['nombre'],
                'titulo' => $plantilla['titulo'],
                'texto' => self::textoParaMeta($plantilla['texto']),
                'idioma' => ClienteWhatsApp::IDIOMA,
                'categoria' => 'UTILITY',
            ];
        }

        return $lista;
    }
}
