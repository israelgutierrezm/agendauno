<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Los registros DNS con que se revisa un dominio propio (ADR 0189): sus TXT, su CNAME
 * y sus IPs. Interfaz para fijar las respuestas en pruebas sin depender de la red.
 */
interface ConsultaDns
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array;

    /** El destino de su CNAME, en minúsculas y sin el punto final; null si no tiene. */
    public function cname(string $host): ?string;

    /**
     * @return list<string>
     */
    public function ips(string $host): array;
}
