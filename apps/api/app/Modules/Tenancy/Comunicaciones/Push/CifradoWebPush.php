<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Lo criptográfico del push en la web (ADR 0225), con el openssl de PHP y sin
 * dependencias: las llaves VAPID (P-256), la firma de la cabecera `Authorization`
 * (RFC 8292, ES256) y el cifrado del mensaje para el navegador (RFC 8291, aes128gcm).
 * Las llaves viajan en base64url: la pública como el punto sin comprimir (65 bytes) y la
 * privada como su escalar (32 bytes).
 */
final class CifradoWebPush
{
    /** SubjectPublicKeyInfo de una llave P-256, sin el punto (RFC 5480). */
    private const PREFIJO_PUBLICA = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Tamaño de registro del contenido cifrado (un solo registro). */
    private const REGISTRO = 4096;

    /**
     * Un par de llaves VAPID nuevo para la plataforma.
     *
     * @return array{publica: string, privada: string}
     */
    public static function generarLlaves(): array
    {
        $llave = self::nuevaLlave();
        $ec = self::detalles($llave);

        return ['publica' => self::b64(self::punto($ec)), 'privada' => self::b64(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT))];
    }

    /**
     * El cuerpo cifrado de un mensaje para la suscripción de un navegador (su llave
     * `p256dh` y su secreto `auth`, en base64url).
     */
    public static function cifrar(string $mensaje, string $p256dh, string $auth): string
    {
        $publicaNavegador = self::deB64($p256dh);
        $secretoAuth = self::deB64($auth);
        if (strlen($publicaNavegador) !== 65 || $publicaNavegador[0] !== "\x04" || strlen($secretoAuth) < 16) {
            throw new RuntimeException('La suscripción del navegador no es válida.');
        }

        // Una llave efímera por mensaje: de ella y la del navegador sale el secreto común.
        $efimera = self::nuevaLlave();
        $publicaEfimera = self::punto(self::detalles($efimera));
        $comun = openssl_pkey_derive(self::publicaPem($publicaNavegador), $efimera, 32);
        if (! is_string($comun) || $comun === '') {
            throw new RuntimeException('No se pudo derivar el secreto del mensaje.');
        }

        $ikm = hash_hkdf('sha256', $comun, 32, "WebPush: info\0".$publicaNavegador.$publicaEfimera, $secretoAuth);
        $sal = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);

        $etiqueta = '';
        // El último (y único) registro termina con el delimitador 0x02.
        $cifrado = openssl_encrypt($mensaje."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiqueta, '', 16);
        if (! is_string($cifrado)) {
            throw new RuntimeException('No se pudo cifrar el mensaje.');
        }

        return $sal.pack('N', self::REGISTRO).chr(65).$publicaEfimera.$cifrado.$etiqueta;
    }

    /**
     * La cabecera `Authorization` VAPID para el servicio de push del endpoint: un JWT
     * ES256 con su origen (`aud`), vencimiento y contacto (`sub`), y la llave pública.
     */
    public static function autorizacion(string $endpoint, string $publica, string $privada, string $sujeto, int $vence): string
    {
        $partes = parse_url($endpoint);
        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])) {
            throw new RuntimeException('El endpoint de la suscripción no es válido.');
        }
        $aud = $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
        $firmar = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::b64((string) json_encode(['aud' => $aud, 'exp' => $vence, 'sub' => $sujeto], JSON_UNESCAPED_SLASHES));

        $der = '';
        if (! openssl_sign($firmar, $der, self::privadaPem(self::deB64($privada), self::deB64($publica)), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar la cabecera VAPID.');
        }

        return 'vapid t='.$firmar.'.'.self::b64(self::firmaCruda($der)).', k='.$publica;
    }

    /** La llave pública (punto sin comprimir) en PEM. */
    public static function publicaPem(string $punto): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode((string) hex2bin(self::PREFIJO_PUBLICA).$punto), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function deB64(string $texto): string
    {
        $decodificado = base64_decode(strtr($texto, '-_', '+/'), true);

        return is_string($decodificado) ? $decodificado : '';
    }

    /** ECPrivateKey (RFC 5915) en PEM, con su curva y su llave pública. */
    private static function privadaPem(string $escalar, string $punto): string
    {
        $der = hex2bin('30770201010420').$escalar.hex2bin('a00a06082a8648ce3d030107a144034200').$punto;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** De la firma DER (SEQUENCE de dos INTEGER) a r||s de 32 bytes cada uno (JWS). */
    private static function firmaCruda(string $der): string
    {
        $pos = 2;
        $cruda = '';
        for ($i = 0; $i < 2; $i++) {
            $largo = ord($der[$pos + 1]);
            $entero = ltrim(substr($der, $pos + 2, $largo), "\0");
            $cruda .= str_pad($entero, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $largo;
        }

        return $cruda;
    }

    /** Una llave P-256 nueva (la efímera de cada mensaje o las VAPID). */
    public static function nuevaLlave(): OpenSSLAsymmetricKey
    {
        $opciones = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $llave = openssl_pkey_new($opciones);
        if (! $llave instanceof OpenSSLAsymmetricKey) {
            // Sin OPENSSL_CONF (p. ej. PHP en Windows) openssl pide un archivo de
            // configuración aunque no lo use: uno mínimo.
            // (con ella, PHP revisa un largo mínimo aunque la curva lo fije).
            $llave = openssl_pkey_new([...$opciones, 'config' => self::configuracionMinima(), 'private_key_bits' => 384]);
        }
        if (! $llave instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('No se pudo crear la llave del mensaje.');
        }

        return $llave;
    }

    private static function configuracionMinima(): string
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agendauno-openssl.cnf';
        if (! is_file($ruta)) {
            file_put_contents($ruta, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
        }

        return $ruta;
    }

    /**
     * @return array{x: string, y: string, d: string}
     */
    private static function detalles(OpenSSLAsymmetricKey $llave): array
    {
        $detalles = openssl_pkey_get_details($llave);
        if (! is_array($detalles) || ! isset($detalles['ec']['x'], $detalles['ec']['y'], $detalles['ec']['d'])) {
            throw new RuntimeException('No se pudo leer la llave del mensaje.');
        }

        return ['x' => (string) $detalles['ec']['x'], 'y' => (string) $detalles['ec']['y'], 'd' => (string) $detalles['ec']['d']];
    }

    /**
     * @param  array{x: string, y: string, d: string}  $ec
     */
    private static function punto(array $ec): string
    {
        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }
}
