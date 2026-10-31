<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Pasarela a la que se le puede preguntar si un cobro tiene un contracargo, para no
 * depender solo de su aviso (ADR 0231). Solo lee. OpenPay no la implementa: falta
 * confirmar en sandbox cómo lo reporta.
 */
interface PasarelaConContracargos
{
    /**
     * El contracargo del cobro, o null si no tiene: su id, en qué va, el monto y el motivo.
     *
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     * @return array{id: string, estado: 'abierto'|'ganado'|'perdido', monto_minor: int, motivo: string|null}|null
     */
    public function contracargo(PagoTenant $pago, array $llaves): ?array;
}
