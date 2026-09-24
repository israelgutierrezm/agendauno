<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;

/**
 * Pasarela cuyo pago automático es una SUSCRIPCIÓN que ella misma cobra cada periodo
 * (Mercado Pago `preapproval`, suscripciones de OpenPay). A diferencia de
 * {@see PasarelaDomiciliable}, aquí no se cobra desde el sistema: se suscribe la
 * membresía y después se concilian los cobros que la pasarela hizo.
 */
interface PasarelaConSuscripcion
{
    /**
     * Suscribe la membresía por su precio, con el primer cobro en su próxima
     * renovación. Guarda en la domiciliación las referencias de la pasarela.
     * Devuelve `redirect` con la página donde el cliente la autoriza, `formulario`
     * con lo que necesita la captura de tarjeta de la pasarela, o `activa`.
     *
     * @param  array<string, string>  $datos  lo que envió el cliente (p. ej. la tarjeta tokenizada)
     * @param  array<string, string>  $llaves
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    public function suscribir(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, ?string $retorno, array $datos, array $llaves): array;

    /**
     * Estado de la suscripción en la pasarela (`activa`, `pendiente` o `cancelada`)
     * y, si la pasarela la informa, con qué tarjeta se cobra.
     *
     * @param  array<string, string>  $llaves
     * @return array{estado: string, tarjeta: TarjetaGuardada|null}
     */
    public function consultarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array;

    /**
     * Los cobros recientes que la pasarela hizo a la suscripción (ya terminados).
     *
     * @param  array<string, string>  $llaves
     * @return list<CobroDeSuscripcion>
     */
    public function cobrosDeSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array;

    /**
     * Cancela la suscripción en la pasarela: ya no cobra más.
     *
     * @param  array<string, string>  $llaves
     */
    public function cancelarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): void;
}
