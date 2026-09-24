<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CargoRentaNoPagable;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\PasarelaStripePlataforma;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cobra un cargo de renta del SaaS (plataforma -> dueño) con la pasarela de LA
 * PLATAFORMA. El cobro en linea es ASINCRONO: crea el intento con la pasarela,
 * guarda su referencia y devuelve los datos de checkout (client_secret/redirect);
 * el webhook de la plataforma lo confirmara -> `pagado`. Serializa con lockForUpdate
 * sobre el cargo (control plane, no conexion tenant).
 */
class CobrarCargoRenta
{
    public function __construct(
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly PasarelaStripePlataforma $stripe,
    ) {}

    public function ejecutar(CargoRenta $cargo, string $proveedor): CargoRenta
    {
        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela de la plataforma no esta activa.');
        }

        return DB::transaction(function () use ($cargo, $proveedor): CargoRenta {
            $bloqueado = CargoRenta::query()->whereKey($cargo->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado !== EstadoCargoRenta::Pendiente) {
                throw new CargoRentaNoPagable('El cargo no admite cobro.');
            }

            // Reintento: el intento anterior (sesión abierta) se vence antes de abrir
            // otro; si ya se pagó, se espera su confirmación en vez de cobrar de nuevo.
            $anterior = (string) $bloqueado->referencia_pago;
            if ($anterior !== '' && $bloqueado->metodo_pago === 'stripe'
                && ! $this->stripe->cancelarIntento($anterior, $this->registro->llaves('stripe'))) {
                throw new CargoRentaNoPagable('Ya hay un pago en proceso para esta renta; espera su confirmación.');
            }

            $resultado = $this->registro->resolver($proveedor)->cobrar($bloqueado, $this->registro->llaves($proveedor));
            $bloqueado->checkout = $resultado->datos;

            if ($resultado->esAprobado()) {
                $bloqueado->update([
                    'estado' => EstadoCargoRenta::Pagado->value,
                    'pagado_en' => Carbon::now(),
                    'metodo_pago' => $proveedor,
                    'referencia_pago' => $resultado->referencia,
                ]);
            } elseif ($resultado->esPendiente()) {
                // Queda pendiente; el webhook confirmara.
                $bloqueado->update([
                    'metodo_pago' => $proveedor,
                    'referencia_pago' => $resultado->referencia,
                ]);
            }

            return $bloqueado;
        });
    }
}
