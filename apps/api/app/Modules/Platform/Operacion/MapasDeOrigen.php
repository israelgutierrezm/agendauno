<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Throwable;

/**
 * Traduce un lugar del JavaScript compilado de la web («assets/index-abc.js:1:234») a
 * su archivo y línea originales («src/views/AgendaView.vue:120:5») con los mapas de
 * origen de esa compilación (ADR 0082). Los mapas no se publican: la imagen web los
 * deja en una carpeta que solo lee la API (`agendauno.errores.mapas_web`).
 *
 * Lee el formato Source Map v3 (VLQ en base 64) sin dependencias. Si no hay mapa o el
 * lugar no está en él, no traduce.
 */
class MapasDeOrigen
{
    private const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var array<string, array{sources: list<string>, lineas: list<string>}|null> leídos en esta petición */
    private array $leidos = [];

    public function original(string $lugar): ?string
    {
        if (preg_match('#^(assets/[A-Za-z0-9._-]+\.js):(\d+):(\d+)$#', $lugar, $m) !== 1) {
            return null;
        }
        $mapa = $this->mapa($m[1]);

        return $mapa === null ? null : $this->buscar($mapa, (int) $m[2], (int) $m[3]);
    }

    /**
     * Traduce cada lugar compilado de una traza, con o sin el dominio delante.
     */
    public function traza(string $traza): string
    {
        return (string) preg_replace_callback(
            '#(?:https?://[^\s()/]+/)?(assets/[A-Za-z0-9._-]+\.js:\d+:\d+)#',
            fn (array $m): string => $this->original($m[1]) ?? $m[0],
            $traza,
        );
    }

    /**
     * @return array{sources: list<string>, lineas: list<string>}|null
     */
    private function mapa(string $archivo): ?array
    {
        if (array_key_exists($archivo, $this->leidos)) {
            return $this->leidos[$archivo];
        }
        $ruta = rtrim((string) config('agendauno.errores.mapas_web'), '/\\').'/'.$archivo.'.map';
        $mapa = null;
        try {
            $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
            if (is_array($datos) && is_string($datos['mappings'] ?? null) && is_array($datos['sources'] ?? null)) {
                $mapa = [
                    'sources' => array_values(array_map(static fn (mixed $s): string => (string) $s, $datos['sources'])),
                    'lineas' => explode(';', $datos['mappings']),
                ];
            }
        } catch (Throwable) {
            $mapa = null;
        }

        return $this->leidos[$archivo] = $mapa;
    }

    /**
     * El último segmento de esa línea que empieza en la columna o antes. Las columnas
     * del navegador empiezan en 1; las del mapa, en 0.
     *
     * @param  array{sources: list<string>, lineas: list<string>}  $mapa
     */
    private function buscar(array $mapa, int $linea, int $columna): ?string
    {
        if ($linea < 1 || $linea > count($mapa['lineas'])) {
            return null;
        }
        // Los índices de origen, línea y columna se acumulan de una línea a otra.
        $origen = 0;
        $lineaOrigen = 0;
        $columnaOrigen = 0;
        $encontrado = null;
        for ($i = 0; $i < $linea; $i++) {
            $columnaGenerada = 0;
            foreach ($mapa['lineas'][$i] === '' ? [] : explode(',', $mapa['lineas'][$i]) as $segmento) {
                $campos = $this->vlq($segmento);
                if ($campos === []) {
                    continue;
                }
                $columnaGenerada += $campos[0];
                if (count($campos) >= 4) {
                    $origen += $campos[1];
                    $lineaOrigen += $campos[2];
                    $columnaOrigen += $campos[3];
                    if ($i === $linea - 1 && $columnaGenerada <= $columna - 1) {
                        $encontrado = [$origen, $lineaOrigen, $columnaOrigen];
                    }
                }
            }
        }
        if ($encontrado === null || ! isset($mapa['sources'][$encontrado[0]])) {
            return null;
        }

        return self::limpiar($mapa['sources'][$encontrado[0]]).':'.($encontrado[1] + 1).':'.($encontrado[2] + 1);
    }

    /**
     * @return list<int>
     */
    private function vlq(string $segmento): array
    {
        $valores = [];
        $valor = 0;
        $desplazamiento = 0;
        foreach (str_split($segmento) as $caracter) {
            $digito = strpos(self::BASE64, $caracter);
            if ($digito === false) {
                return [];
            }
            $valor += ($digito & 31) << $desplazamiento;
            if (($digito & 32) !== 0) {
                $desplazamiento += 5;

                continue;
            }
            $valores[] = ($valor & 1) === 1 ? -($valor >> 1) : $valor >> 1;
            $valor = 0;
            $desplazamiento = 0;
        }

        return $valores;
    }

    /** «../../src/views/X.vue» → «src/views/X.vue»; las librerías, desde node_modules. */
    private static function limpiar(string $origen): string
    {
        $origen = str_replace('\\', '/', $origen);
        foreach (['node_modules/', 'src/'] as $raiz) {
            $desde = strpos($origen, $raiz);
            if ($desde !== false) {
                return substr($origen, $desde);
            }
        }

        return (string) preg_replace('#^(\.\./|\./|/)+#', '', $origen);
    }
}
