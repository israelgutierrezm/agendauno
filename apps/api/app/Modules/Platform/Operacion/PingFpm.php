<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

/**
 * Pregunta a PHP-FPM por su ping (`ping.path` de php-fpm.conf) por FastCGI, como lo
 * haría nginx (ADR 0173). Sirve al chequeo de salud de Docker del contenedor `api`:
 * si FPM no acepta conexiones o sus procesos están todos atorados, no contesta.
 * FastCGI a mano para no agregar paquetes a la imagen (cgi-fcgi).
 */
class PingFpm
{
    private const BEGIN_REQUEST = 1;

    private const END_REQUEST = 3;

    private const PARAMS = 4;

    private const STDIN = 5;

    private const STDOUT = 6;

    private const RESPONDER = 1;

    /** Null si contestó; si no, por qué. */
    public function fallo(string $host, int $puerto, float $segundos, string $ruta = '/ping', string $esperado = 'pong'): ?string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$puerto}", $codigo, $error, $segundos);
        if ($socket === false) {
            return "PHP-FPM no acepta conexiones en {$host}:{$puerto}: {$error}";
        }
        try {
            stream_set_timeout($socket, max(1, (int) ceil($segundos)));
            $parametros = '';
            foreach ([
                'SCRIPT_NAME' => $ruta,
                'SCRIPT_FILENAME' => $ruta,
                'REQUEST_URI' => $ruta,
                'REQUEST_METHOD' => 'GET',
                'QUERY_STRING' => '',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
            ] as $nombre => $valor) {
                $parametros .= self::largo($nombre).self::largo($valor).$nombre.$valor;
            }
            $peticion = self::registro(self::BEGIN_REQUEST, pack('nCx5', self::RESPONDER, 0))
                .self::registro(self::PARAMS, $parametros)
                .self::registro(self::PARAMS, '')
                .self::registro(self::STDIN, '');
            if (fwrite($socket, $peticion) !== strlen($peticion)) {
                return 'No se pudo hablar con PHP-FPM.';
            }

            $salida = '';
            while (true) {
                $cabecera = self::leer($socket, 8);
                if ($cabecera === null) {
                    return 'PHP-FPM no contestó a tiempo.';
                }
                /** @var array{tipo: int, largo: int, relleno: int} $registro */
                $registro = unpack('Cversion/Ctipo/nid/nlargo/Crelleno', $cabecera);
                $contenido = $registro['largo'] > 0 ? self::leer($socket, $registro['largo']) : '';
                if ($contenido === null || ($registro['relleno'] > 0 && self::leer($socket, $registro['relleno']) === null)) {
                    return 'PHP-FPM no contestó a tiempo.';
                }
                if ($registro['tipo'] === self::STDOUT) {
                    $salida .= $contenido;
                }
                if ($registro['tipo'] === self::END_REQUEST) {
                    break;
                }
            }

            return str_contains($salida, $esperado) ? null : 'PHP-FPM contestó, pero no su ping.';
        } finally {
            fclose($socket);
        }
    }

    private static function registro(int $tipo, string $contenido): string
    {
        $relleno = (8 - strlen($contenido) % 8) % 8;

        return pack('CCnnCx', 1, $tipo, 1, strlen($contenido), $relleno).$contenido.str_repeat("\0", $relleno);
    }

    /** El largo de un nombre o un valor de FastCGI: 1 byte hasta 127; si no, 4. */
    private static function largo(string $texto): string
    {
        return strlen($texto) < 128 ? chr(strlen($texto)) : pack('N', strlen($texto) | 0x80000000);
    }

    /**
     * Lee exactamente `$bytes` o null si se cerró o se acabó el tiempo.
     *
     * @param  resource  $socket
     */
    private static function leer($socket, int $bytes): ?string
    {
        $datos = '';
        while (strlen($datos) < $bytes) {
            $parte = fread($socket, $bytes - strlen($datos));
            if ($parte === false || $parte === '') {
                return null;
            }
            $datos .= $parte;
        }

        return $datos;
    }
}
