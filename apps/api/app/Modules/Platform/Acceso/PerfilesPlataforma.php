<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

/**
 * Qué puede hacer cada perfil de operador del superadmin (ADR 0215). Todos ven todo
 * (consultar no cambia nada); lo que cambia algo depende del perfil:
 *
 * - `total`: todo, incluido el equipo, la configuración, las pasarelas, las tarifas,
 *   los legales y dar de baja un negocio.
 * - `cobranza`: la renta: cobros, cargos, pagos a mano, facturas, condonar, suspender
 *   y reactivar, lo pactado de cada negocio y el tipo de cambio.
 * - `soporte`: ayudar a los negocios: extender su prueba, su terminología, sus
 *   módulos, su WhatsApp, reenviar su activación, las demos, los errores, Wellhub y
 *   marcar alertas atendidas.
 * - `lectura`: solo ver.
 *
 * Una ruta que cambia algo y no está en ningún perfil solo la usa `total`: lo nuevo
 * nace restringido.
 */
final class PerfilesPlataforma
{
    public const TOTAL = 'total';

    public const PERFILES = [self::TOTAL, 'cobranza', 'soporte', 'lectura'];

    /**
     * Rutas (sin el prefijo `api.v1.plataforma.`) que cambian algo, por perfil.
     *
     * @var array<string, list<string>>
     */
    private const ESCRITURA = [
        'cobranza' => [
            'cargos.condonar', 'cargos.pago', 'cargos.factura', 'estudios.cargos.crear',
            'facturas.cancelar', 'facturas.cancelacion',
            'estudios.suspender', 'estudios.reactivar', 'estudios.actualizar', 'estudios.plan',
            'tipo-cambio.guardar',
        ],
        'soporte' => [
            'estudios.extender-prueba', 'estudios.terminologia.guardar', 'estudios.modulos', 'estudios.whatsapp',
            'estudios.reenviar-activacion',
            'demos.horario.guardar', 'demos.actualizar',
            'errores.update', 'alertas.atender',
            'wellhub.solicitudes.asignar', 'wellhub.solicitudes.descartar',
        ],
        'lectura' => [],
    ];

    /** Lo que cualquiera hace con su propia sesión. */
    private const PROPIAS = ['salir'];

    public static function permite(string $perfil, string $ruta, string $metodo): bool
    {
        if ($perfil === self::TOTAL || in_array(strtoupper($metodo), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $nombre = (string) preg_replace('/^api\.v1\.plataforma\./', '', $ruta);

        return in_array($nombre, self::PROPIAS, true) || in_array($nombre, self::ESCRITURA[$perfil] ?? [], true);
    }
}
