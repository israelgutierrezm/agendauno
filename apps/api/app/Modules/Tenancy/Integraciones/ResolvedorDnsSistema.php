<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Resolución con el DNS del sistema (A e AAAA).
 */
class ResolvedorDnsSistema implements ResolvedorDns
{
    public function ips(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $registro) {
                if (isset($registro['ipv6']) && is_string($registro['ipv6'])) {
                    $ips[] = $registro['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
