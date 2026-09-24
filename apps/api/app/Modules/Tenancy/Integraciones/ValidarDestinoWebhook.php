<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Exceptions\DestinoWebhookNoPermitido;

/**
 * Protección contra SSRF de los webhooks salientes: el negocio escribe la URL y el
 * servidor la llama, así que solo se permite un destino público. Se valida al
 * registrarla y otra vez en cada envío (el DNS puede cambiar): https, sin
 * credenciales en la URL, puertos 443/8443 y TODAS las IPs a las que resuelve el
 * dominio deben ser públicas (nada de loopback, redes privadas, link-local/metadatos
 * de la nube, CGNAT, multicast ni rangos de documentación). El envío se hace contra
 * la IP ya validada y sin seguir redirecciones.
 *
 * Ver https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
 */
class ValidarDestinoWebhook
{
    private const PUERTOS = [443, 8443];

    /**
     * Rangos no públicos que los filtros de PHP no cubren.
     */
    private const BLOQUEADOS = [
        '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24',
        '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private readonly ResolvedorDns $dns) {}

    /**
     * @return array{host: string, puerto: int, ip: string}
     */
    public function validar(string $url): array
    {
        $partes = parse_url(trim($url));
        if (! is_array($partes) || ! isset($partes['host'])) {
            throw new DestinoWebhookNoPermitido('La URL del webhook no es válida.');
        }
        if (strtolower($partes['scheme'] ?? '') !== 'https') {
            throw new DestinoWebhookNoPermitido('El webhook debe usar https.');
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            throw new DestinoWebhookNoPermitido('La URL del webhook no puede llevar usuario ni contraseña.');
        }

        $puerto = (int) ($partes['port'] ?? 443);
        if (! in_array($puerto, self::PUERTOS, true)) {
            throw new DestinoWebhookNoPermitido('El webhook solo puede usar el puerto 443 u 8443.');
        }

        $host = strtolower(trim($partes['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->ips($host);
        if ($ips === []) {
            throw new DestinoWebhookNoPermitido('No se pudo encontrar el dominio del webhook.');
        }

        foreach ($ips as $ip) {
            if (! self::esPublica($ip)) {
                throw new DestinoWebhookNoPermitido('El webhook apunta a una dirección interna o reservada.');
            }
        }

        return ['host' => $host, 'puerto' => $puerto, 'ip' => $ips[0]];
    }

    public static function esPublica(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOQUEADOS as $rango) {
            if (self::enRango($ip, $rango)) {
                return false;
            }
        }

        return true;
    }

    private static function enRango(string $ip, string $rango): bool
    {
        [$red, $bits] = explode('/', $rango);
        $binIp = inet_pton($ip);
        $binRed = inet_pton($red);
        if ($binIp === false || $binRed === false || strlen($binIp) !== strlen($binRed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($binIp, 0, $bytes) !== substr($binRed, 0, $bytes)) {
            return false;
        }
        $resto = $bits % 8;
        if ($resto === 0) {
            return true;
        }
        $mascara = (0xFF << (8 - $resto)) & 0xFF;

        return (ord($binIp[$bytes]) & $mascara) === (ord($binRed[$bytes]) & $mascara);
    }
}
