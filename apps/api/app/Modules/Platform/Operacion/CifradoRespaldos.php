<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use RuntimeException;

/**
 * Cifra los respaldos antes de que salgan del servidor (ADR 0171): la base central, la
 * de cada negocio y los archivos subidos. Si alguien lee el bucket, no lee los datos.
 *
 * AES-256-GCM por bloques de 1 MiB (construcción STREAM), sin cargar el respaldo en
 * memoria. Cada respaldo usa su propia llave, derivada de `RESPALDOS_LLAVE` con una sal
 * al azar; cada bloque lleva su número y si es el último, así que quitar, cambiar de
 * orden o cortar bloques se detecta al descifrar.
 *
 * Formato: `AU1C` · id de la llave (8) · sal (16) · bloques [largo (4) · último (1) ·
 * etiqueta (16) · cifrado]. El id dice con qué llave se cifró: al cambiarla, las de antes
 * van en `RESPALDOS_LLAVES_ANTERIORES` para poder restaurar lo viejo.
 */
class CifradoRespaldos
{
    private const MAGIA = 'AU1C';

    private const CIFRADO = 'aes-256-gcm';

    private const BLOQUE = 1048576;

    private const ETIQUETA = 16;

    /** ¿Hay llave? Sin ella los respaldos se guardan como antes (la verificación lo marca). */
    public function activo(): bool
    {
        return $this->llaveActual() !== null;
    }

    /** ¿El archivo es un respaldo cifrado (empieza con la marca)? */
    public function estaCifrado(string $archivo): bool
    {
        $entrada = @fopen($archivo, 'rb');
        if ($entrada === false) {
            return false;
        }
        $marca = fread($entrada, strlen(self::MAGIA));
        fclose($entrada);

        return $marca === self::MAGIA;
    }

    public function cifrar(string $origen, string $destino): void
    {
        $llave = $this->llaveActual() ?? throw new RuntimeException('Falta RESPALDOS_LLAVE para cifrar el respaldo.');
        $entrada = fopen($origen, 'rb');
        $salida = fopen($destino, 'wb');
        if ($entrada === false || $salida === false) {
            throw new RuntimeException('No se pudo cifrar el respaldo.');
        }
        try {
            $sal = random_bytes(16);
            $cabecera = self::MAGIA.self::id($llave).$sal;
            $this->escribir($salida, $cabecera);
            $delArchivo = self::llaveDelArchivo($llave, $sal);
            $numero = 0;
            $bloque = (string) fread($entrada, self::BLOQUE);
            do {
                $siguiente = feof($entrada) ? '' : (string) fread($entrada, self::BLOQUE);
                $ultimo = $siguiente === '' && feof($entrada);
                $etiqueta = '';
                $cifrado = openssl_encrypt($bloque, self::CIFRADO, $delArchivo, OPENSSL_RAW_DATA, self::nonce($numero), $etiqueta, self::datos($cabecera, $numero, $ultimo), self::ETIQUETA);
                if ($cifrado === false || strlen($etiqueta) !== self::ETIQUETA) {
                    throw new RuntimeException('No se pudo cifrar el respaldo.');
                }
                $this->escribir($salida, pack('N', strlen($cifrado)).($ultimo ? "\x01" : "\x00").$etiqueta.$cifrado);
                $bloque = $siguiente;
                $numero++;
            } while (! $ultimo);
        } finally {
            fclose($entrada);
            fclose($salida);
        }
    }

    public function descifrar(string $origen, string $destino): void
    {
        $entrada = fopen($origen, 'rb');
        $salida = fopen($destino, 'wb');
        if ($entrada === false || $salida === false) {
            throw new RuntimeException('No se pudo descifrar el respaldo.');
        }
        try {
            $cabecera = $this->leer($entrada, strlen(self::MAGIA) + 8 + 16);
            if (! str_starts_with($cabecera, self::MAGIA)) {
                throw new RuntimeException('El respaldo no está cifrado por AgendaUno.');
            }
            $llave = $this->llaves()[substr($cabecera, strlen(self::MAGIA), 8)]
                ?? throw new RuntimeException('Falta la llave con que se cifró este respaldo: RESPALDOS_LLAVE o, si la cambiaste, RESPALDOS_LLAVES_ANTERIORES.');
            $delArchivo = self::llaveDelArchivo($llave, substr($cabecera, -16));
            for ($numero = 0; ; $numero++) {
                $marco = fread($entrada, 4 + 1 + self::ETIQUETA);
                if ($marco === false || strlen($marco) !== 4 + 1 + self::ETIQUETA) {
                    throw new RuntimeException('El respaldo está incompleto: le faltan datos al final.');
                }
                $largo = unpack('N', substr($marco, 0, 4));
                $largo = is_array($largo) ? (int) $largo[1] : -1;
                $ultimo = $marco[4] === "\x01";
                // GCM no agrega bytes al cifrado: ningún bloque pasa de 1 MiB.
                if ($largo < 0 || $largo > self::BLOQUE) {
                    throw new RuntimeException('El respaldo está dañado.');
                }
                $cifrado = $largo === 0 ? '' : $this->leer($entrada, $largo);
                $plano = openssl_decrypt($cifrado, self::CIFRADO, $delArchivo, OPENSSL_RAW_DATA, self::nonce($numero), substr($marco, 5), self::datos($cabecera, $numero, $ultimo));
                if ($plano === false) {
                    throw new RuntimeException('El respaldo está dañado o la llave no es la correcta.');
                }
                $this->escribir($salida, $plano);
                if ($ultimo) {
                    break;
                }
            }
            if (fread($entrada, 1) !== '') {
                throw new RuntimeException('El respaldo está dañado: trae datos de más.');
            }
        } catch (RuntimeException $e) {
            fclose($salida);
            $salida = null;
            @unlink($destino);
            throw $e;
        } finally {
            fclose($entrada);
            if ($salida !== null) {
                fclose($salida);
            }
        }
    }

    private function llaveActual(): ?string
    {
        $llave = trim((string) config('agendauno.respaldos.llave'));

        return $llave === '' ? null : self::decodificar($llave);
    }

    /**
     * La actual y las anteriores, por su id.
     *
     * @return array<string, string>
     */
    private function llaves(): array
    {
        $llaves = [];
        $anteriores = array_filter(array_map('trim', explode(',', (string) config('agendauno.respaldos.llaves_anteriores'))));
        foreach ([...$anteriores, (string) config('agendauno.respaldos.llave')] as $texto) {
            if (trim($texto) !== '') {
                $llave = self::decodificar(trim($texto));
                $llaves[self::id($llave)] = $llave;
            }
        }

        return $llaves;
    }

    /** `base64:` opcional, como APP_KEY; 32 bytes. */
    private static function decodificar(string $texto): string
    {
        $llave = base64_decode(str_starts_with($texto, 'base64:') ? substr($texto, 7) : $texto, true);
        if ($llave === false || strlen($llave) !== 32) {
            throw new RuntimeException('RESPALDOS_LLAVE debe ser de 32 bytes en base64 (openssl rand -base64 32).');
        }

        return $llave;
    }

    /** Identifica la llave sin revelarla. */
    private static function id(string $llave): string
    {
        return substr(hash_hmac('sha256', 'agendauno-respaldos-id', $llave, true), 0, 8);
    }

    private static function llaveDelArchivo(string $llave, string $sal): string
    {
        return hash_hkdf('sha256', $llave, 32, 'agendauno-respaldo-v1', $sal);
    }

    private static function nonce(int $numero): string
    {
        return "\0\0\0\0".pack('J', $numero);
    }

    private static function datos(string $cabecera, int $numero, bool $ultimo): string
    {
        return $cabecera.pack('J', $numero).($ultimo ? "\x01" : "\x00");
    }

    /** @param  resource  $salida */
    private function escribir($salida, string $datos): void
    {
        if ($datos !== '' && fwrite($salida, $datos) !== strlen($datos)) {
            throw new RuntimeException('No se pudo guardar el respaldo.');
        }
    }

    /** @param  resource  $entrada */
    private function leer($entrada, int $largo): string
    {
        $datos = '';
        while (strlen($datos) < $largo && ! feof($entrada)) {
            $parte = fread($entrada, $largo - strlen($datos));
            if ($parte === false) {
                break;
            }
            $datos .= $parte;
        }
        if (strlen($datos) !== $largo) {
            throw new RuntimeException('El respaldo está incompleto: le faltan datos al final.');
        }

        return $datos;
    }
}
