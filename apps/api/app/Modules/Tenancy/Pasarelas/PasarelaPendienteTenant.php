<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Str;

/**
 * Pasarela en linea que aun no tiene integracion real de creacion de intento
 * (OpenPay / Mercado Pago) o que espera confirmacion externa (ventanilla): crea un
 * intento pendiente con una referencia propia; el webhook (o la aprobacion de
 * comprobante) lo confirma. La integracion real por proveedor se conecta aqui,
 * siguiendo el patron de PasarelaStripeTenant, cuando el estudio tenga llaves.
 */
class PasarelaPendienteTenant implements PasarelaTenant
{
    public function __construct(private readonly string $nombre) {}

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        return ResultadoPago::pendiente($this->nombre.'_'.Str::lower(Str::random(24)));
    }
}
