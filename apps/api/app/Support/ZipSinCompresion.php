<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Un ZIP sin compresión (método «stored»), en memoria y sin la extensión zip de PHP:
 * basta para empaquetar unos pocos archivos pequeños, como el pase de Apple Wallet
 * (ADR 0195). Formato: un encabezado local por archivo, el directorio central y su
 * cierre (APPNOTE de PKWARE).
 */
final class ZipSinCompresion
{
    /**
     * @param  array<string, string>  $archivos  nombre => contenido
     */
    public static function crear(array $archivos): string
    {
        $cuerpo = '';
        $directorio = '';
        foreach ($archivos as $nombre => $contenido) {
            $crc = crc32($contenido);
            $tamano = strlen($contenido);
            $desplazamiento = strlen($cuerpo);
            // Versión 2.0, sin banderas, sin compresión, fecha fija (1-ene-1980).
            $comun = pack('vvvvvVVVvv', 20, 0, 0, 0, 0x21, $crc, $tamano, $tamano, strlen($nombre), 0);
            $cuerpo .= pack('V', 0x04034B50).$comun.$nombre.$contenido;
            $directorio .= pack('Vv', 0x02014B50, 20).$comun.pack('vvvVV', 0, 0, 0, 0, $desplazamiento).$nombre;
        }

        return $cuerpo.$directorio.pack('VvvvvVVv', 0x06054B50, 0, 0, count($archivos), count($archivos), strlen($directorio), strlen($cuerpo), 0);
    }
}
