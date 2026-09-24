<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Support\Str;

/**
 * Pasarela de la plataforma en linea que aun no tiene integracion real de creacion
 * de intento (Mercado Pago / Open Pay): crea un intento pendiente con una referencia
 * propia; el webhook lo confirma. La integracion real por proveedor se conecta aqui,
 * siguiendo el patron de {@see PasarelaStripePlataforma}, cuando la plataforma tenga
 * llaves.
 */
class PasarelaPendientePlataforma implements PasarelaPlataforma
{
    public function __construct(private readonly string $nombre) {}

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago
    {
        return ResultadoPago::pendiente($this->nombre.'_'.Str::lower(Str::random(24)));
    }
}
