<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Las preguntas de calificación del formulario de demo (ADR 0204, como el de la
 * competencia): las opciones que se aceptan y cómo se le leen a ventas. La landing
 * muestra los mismos valores con sus textos (y la palabra del producto para el público:
 * alumnos en clases, clientes en citas).
 */
final class CalificacionDemo
{
    /** Cuántos alumnos o clientes tiene, si ya opera. */
    public const TAMANOS = ['1-20', '21-50', '51-100', '101-300', '301-500', '500+'];

    /** Cuándo abre, si todavía no opera. */
    public const APERTURAS = ['menos_1_mes', '1_3_meses', 'mas_3_meses', 'no_se'];

    /** Cuántas sucursales tiene. */
    public const SUCURSALES = ['1', '2-4', '5+', 'en_linea'];

    /** Qué quiere resolver primero (hasta dos). */
    public const OBJETIVOS = ['reservas', 'cobros', 'app', 'asistencia', 'retencion', 'agenda', 'facturacion', 'reportes'];

    public const MAXIMO_OBJETIVOS = 2;

    /** Las respuestas en el orden de las preguntas. */
    private const CAMPOS = ['operando', 'tamano', 'apertura', 'sucursales', 'objetivos', 'giro_otro', 'redes'];

    private const TEXTOS = [
        'menos_1_mes' => 'En menos de un mes',
        '1_3_meses' => 'En 1 a 3 meses',
        'mas_3_meses' => 'En más de 3 meses',
        'no_se' => 'Aún no sabe',
        'en_linea' => 'Sin local (en línea o en varios estudios)',
        'reservas' => 'Reservas en línea',
        'cobros' => 'Cobros y pagos en línea',
        'app' => 'App para su público',
        'asistencia' => 'Asistencia y acceso',
        'retencion' => 'Que regresen (retención)',
        'agenda' => 'Agenda del equipo',
        'facturacion' => 'Facturación',
        'reportes' => 'Reportes y números',
    ];

    /**
     * Lo que se guarda: solo lo que vino, con las opciones conocidas.
     *
     * @param  array<string, mixed>  $validado
     * @return array<string, mixed>|null
     */
    public static function desde(array $validado): ?array
    {
        $datos = array_filter([
            'operando' => isset($validado['operando']) ? (bool) $validado['operando'] : null,
            'tamano' => $validado['tamano'] ?? null,
            'apertura' => $validado['apertura'] ?? null,
            'sucursales' => $validado['sucursales'] ?? null,
            'objetivos' => array_values(array_unique((array) ($validado['objetivos'] ?? []))) ?: null,
            'giro_otro' => trim((string) ($validado['giro_otro'] ?? '')) ?: null,
            'redes' => trim((string) ($validado['redes'] ?? '')) ?: null,
        ], fn (mixed $v): bool => $v !== null);

        return $datos === [] ? null : $datos;
    }

    /**
     * Lo guardado, con sus respuestas en el orden de las preguntas: MySQL reordena las
     * claves de una columna JSON y el superadmin las lee en el orden del formulario.
     *
     * @param  array<string, mixed>|null  $calificacion
     * @return array<string, mixed>|null
     */
    public static function enOrden(?array $calificacion): ?array
    {
        return $calificacion === null
            ? null
            : array_replace(array_intersect_key(array_flip(self::CAMPOS), $calificacion), $calificacion);
    }

    /**
     * Para el correo de ventas: cada respuesta en palabras.
     *
     * @param  array<string, mixed>|null  $calificacion
     * @return array<string, string>
     */
    public static function paraVentas(?array $calificacion): array
    {
        if ($calificacion === null) {
            return [];
        }
        $texto = fn (mixed $v): string => self::TEXTOS[(string) $v] ?? (string) $v;
        $lineas = [];
        if (isset($calificacion['giro_otro'])) {
            $lineas['Otro giro'] = (string) $calificacion['giro_otro'];
        }
        if (isset($calificacion['operando'])) {
            $lineas['Ya opera'] = $calificacion['operando'] ? 'Sí' : 'No, todavía no';
        }
        if (isset($calificacion['tamano'])) {
            $lineas['Alumnos o clientes'] = (string) $calificacion['tamano'];
        }
        if (isset($calificacion['apertura'])) {
            $lineas['Abre'] = $texto($calificacion['apertura']);
        }
        if (isset($calificacion['sucursales'])) {
            $lineas['Sucursales'] = $texto($calificacion['sucursales']);
        }
        if (isset($calificacion['objetivos'])) {
            $lineas['Quiere resolver'] = implode(', ', array_map($texto, (array) $calificacion['objetivos']));
        }
        if (isset($calificacion['redes'])) {
            $lineas['Instagram o página'] = (string) $calificacion['redes'];
        }

        return $lineas;
    }
}
