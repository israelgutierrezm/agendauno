<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\MarcaProducto;

/**
 * El enlace de los avisos de una cita para cancelarla o cambiarla sin cuenta (ADR 0221).
 * Va firmado con la llave de la aplicación sobre el negocio y la reserva: nadie llega a
 * la cita de otra persona adivinando su dirección. No se guarda en ningún lado; las
 * reglas de siempre (hasta cuándo se cancela o se cambia, cuántas veces) deciden qué se
 * puede hacer con él.
 */
final class EnlaceDeCitaTenant
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /** La dirección de la página de la cita, en la web del negocio conectado. */
    public function url(string $reservaUlid): ?string
    {
        $estudio = $this->gestor->actual();
        if (! $estudio instanceof Estudio) {
            return null;
        }

        return MarcaProducto::urlWeb($estudio).'/cita/'.rawurlencode((string) $estudio->slug).'/'.rawurlencode($reservaUlid)
            .'?f='.$this->firma($estudio, $reservaUlid);
    }

    /** Si la firma corresponde a esa reserva del negocio conectado. */
    public function valida(string $reservaUlid, string $firma): bool
    {
        $estudio = $this->gestor->actual();

        return $estudio instanceof Estudio && $firma !== '' && hash_equals($this->firma($estudio, $reservaUlid), $firma);
    }

    private function firma(Estudio $estudio, string $reservaUlid): string
    {
        return substr(hash_hmac('sha256', 'cita-sin-cuenta|'.$estudio->getKey().'|'.$reservaUlid, (string) config('app.key')), 0, 40);
    }
}
