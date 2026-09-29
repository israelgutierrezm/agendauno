<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;

/**
 * Cómo se cobra una cita que agenda el cliente (ADR 0065). El negocio decide si pide
 * el pago en línea para confirmarla (`citas.pago_en_linea_obligatorio`); si no, la cita
 * queda confirmada al agendar y se paga en línea o en la sucursal. Sin cobro en línea
 * activo no se puede exigir: la cita se apartaría y se vencería sin que nadie pudiera
 * pagarla.
 */
class CobroDeCitasTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly RegistroDePasarelasTenant $pasarelas,
    ) {}

    /** ¿Hay una pasarela con la que el cliente pueda pagar en línea? */
    public function pagoEnLinea(): bool
    {
        return $this->pasarelas->enLinea() !== null;
    }

    /** ¿La cita se aparta hasta pagarla en línea (y se vence si no se paga)? */
    public function pagoObligatorio(): bool
    {
        return $this->parametros->siNo('citas.pago_en_linea_obligatorio') && $this->pagoEnLinea();
    }

    /**
     * Para las pantallas de agendar.
     *
     * @return array{pago_obligatorio: bool, pago_en_linea: bool}
     */
    public function paraPantalla(): array
    {
        $enLinea = $this->pagoEnLinea();

        return [
            'pago_obligatorio' => $enLinea && $this->parametros->siNo('citas.pago_en_linea_obligatorio'),
            'pago_en_linea' => $enLinea,
        ];
    }
}
