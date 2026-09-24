<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Resuelve un dominio a sus IPs (A y AAAA). Interfaz para poder fijar respuestas en
 * pruebas sin depender de la red.
 */
interface ResolvedorDns
{
    /**
     * @return list<string>
     */
    public function ips(string $host): array;
}
