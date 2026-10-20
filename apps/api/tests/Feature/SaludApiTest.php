<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
| El chequeo de salud del contenedor `api` (ADR 0173): `agendauno:salud-api` pregunta
| a PHP-FPM por su ping por FastCGI. Aquí contesta un PHP-FPM falso (otro proceso).
*/

afterEach(function (): void {
    foreach ($GLOBALS['procesos_fpm_falso'] ?? [] as $proceso) {
        proc_terminate($proceso);
        proc_close($proceso);
    }
    $GLOBALS['procesos_fpm_falso'] = [];
    File::delete(storage_path('framework/testing/fpm-falso.php'));
});

/**
 * Levanta un PHP-FPM falso que contesta una vez con `$respuesta`; devuelve su puerto.
 */
function fpmFalsoQueContesta(string $respuesta): int
{
    $archivo = storage_path('framework/testing/fpm-falso.php');
    File::ensureDirectoryExists(dirname($archivo));
    File::put($archivo, <<<'PHP'
        <?php
        $servidor = stream_socket_server('tcp://127.0.0.1:0', $codigo, $error);
        $nombre = (string) stream_socket_get_name($servidor, false);
        fwrite(STDOUT, substr($nombre, strrpos($nombre, ':') + 1)."\n");
        fflush(STDOUT);
        $conexion = stream_socket_accept($servidor, 20);
        if ($conexion === false) {
            exit(1);
        }
        // Lee la petición hasta su STDIN vacío.
        while (($cabecera = fread($conexion, 8)) !== false && strlen($cabecera) === 8) {
            $r = unpack('Cversion/Ctipo/nid/nlargo/Crelleno', $cabecera);
            if ($r['largo'] + $r['relleno'] > 0) {
                fread($conexion, $r['largo'] + $r['relleno']);
            }
            if ($r['tipo'] === 5 && $r['largo'] === 0) {
                break;
            }
        }
        $registro = fn (int $tipo, string $contenido): string => pack('CCnnCx', 1, $tipo, 1, strlen($contenido), 0).$contenido;
        fwrite($conexion, $registro(6, "Content-type: text/plain\r\n\r\n".($argv[1] ?? '')).$registro(6, '').$registro(3, pack('NCx3', 0, 0)));
        fclose($conexion);
        PHP);

    $proceso = proc_open([PHP_BINARY, $archivo, $respuesta], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
    expect($proceso)->toBeResource();
    $GLOBALS['procesos_fpm_falso'][] = $proceso;

    return (int) trim((string) fgets($tubos[1]));
}

it('sano cuando PHP-FPM contesta su ping', function (): void {
    $puerto = fpmFalsoQueContesta('pong');

    $this->artisan('agendauno:salud-api', ['--puerto' => $puerto])
        ->expectsOutputToContain('PHP-FPM contesta.')
        ->assertSuccessful();
});

it('falla si contesta otra cosa o no acepta conexiones', function (): void {
    $puerto = fpmFalsoQueContesta('File not found.');
    $this->artisan('agendauno:salud-api', ['--puerto' => $puerto])
        ->expectsOutputToContain('no su ping')
        ->assertFailed();

    // Un puerto donde ya no escucha nadie.
    $libre = stream_socket_server('tcp://127.0.0.1:0');
    $cerrado = (int) substr((string) stream_socket_get_name($libre, false), strrpos((string) stream_socket_get_name($libre, false), ':') + 1);
    fclose($libre);
    $this->artisan('agendauno:salud-api', ['--puerto' => $cerrado, '--segundos' => 1])
        ->expectsOutputToContain('no acepta conexiones')
        ->assertFailed();
});
