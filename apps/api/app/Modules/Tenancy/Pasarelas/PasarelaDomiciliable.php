<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;

/**
 * Pasarela que admite pago automático (domiciliación): el cliente autoriza su
 * tarjeta una vez en la página de la pasarela y cada renovación se le cobra sin que
 * tenga que estar presente.
 */
interface PasarelaDomiciliable
{
    /**
     * Página de la pasarela donde el cliente autoriza su tarjeta, sin cobrar. Al
     * terminar, la pasarela avisa por webhook con la tarjeta y la `metadata`. La
     * `referencia` de la sesión permite conciliarla si el aviso no llega (ADR 0076).
     *
     * @param  array<string, string>  $metadata
     * @param  array<string, string>  $llaves
     * @return array{tipo: string, url: string, referencia?: string}
     */
    public function iniciarGuardado(PersonaTenant $persona, ?string $retorno, array $metadata, array $llaves): array;

    /**
     * Cobra el periodo a la tarjeta domiciliada, sin el cliente presente. La misma
     * `idempotencia` nunca cobra dos veces.
     *
     * @param  array<string, string>  $llaves
     *
     * @throws CobroNoConcluyente si la pasarela no respondió
     */
    public function cobrarDomiciliado(PagoTenant $pago, DomiciliacionTenant $domiciliacion, string $idempotencia, array $llaves): ResultadoPago;

    /**
     * La tarjeta (su referencia en la pasarela) ya no se usará para cobrar: se
     * desliga.
     *
     * @param  array<string, string>  $llaves
     */
    public function olvidarTarjeta(string $metodoExterno, array $llaves): void;
}
