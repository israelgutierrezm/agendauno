<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * La consulta con el DNS del sistema. Un dominio que no responde no tiene registros.
 */
class ConsultaDnsSistema implements ConsultaDns
{
    public function txt(string $host): array
    {
        $registros = @dns_get_record($host, DNS_TXT);
        $textos = [];
        foreach (is_array($registros) ? $registros : [] as $registro) {
            if (isset($registro['txt']) && is_string($registro['txt'])) {
                $textos[] = $registro['txt'];
            }
        }

        return $textos;
    }

    public function cname(string $host): ?string
    {
        $registros = @dns_get_record($host, DNS_CNAME);
        foreach (is_array($registros) ? $registros : [] as $registro) {
            if (isset($registro['target']) && is_string($registro['target'])) {
                return strtolower(rtrim($registro['target'], '.'));
            }
        }

        return null;
    }

    public function ips(string $host): array
    {
        return array_values(array_unique(gethostbynamel($host) ?: []));
    }
}
