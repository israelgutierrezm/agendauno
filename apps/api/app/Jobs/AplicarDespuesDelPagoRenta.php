<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reintenta lo que sigue a un cargo de renta pagado si falló al pagarse (ADR 0231):
 * reactivar el negocio, sumar timbres o mensajes y dar el mes gratis del referido. Todo
 * es idempotente. Vive en la base de la plataforma: no lleva negocio en el payload.
 */
class AplicarDespuesDelPagoRenta implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $cargoId) {}

    public function handle(ConfirmarCargoRenta $confirmar): void
    {
        $cargo = CargoRenta::query()->find($this->cargoId);
        if ($cargo instanceof CargoRenta) {
            $confirmar->aplicarDespuesDelPago($cargo);
        }
    }
}
