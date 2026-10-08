<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\PasarelaStripePlataforma;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La renta pagada cuyo aviso de Stripe no llegó: se pregunta a Stripe por los cargos
 * pendientes con un intento en curso y se aplica lo que habría aplicado el aviso
 * (pagado, o el intento ya no se puede pagar). Sin esto, un negocio que pagó podría
 * suspenderse por renta vencida.
 */
class ConciliarCargosRenta
{
    /** Minutos que se le dan al aviso antes de preguntar. */
    private const MINUTOS_DE_GRACIA = 10;

    public function __construct(
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly PasarelaStripePlataforma $stripe,
        private readonly ConfirmarCargoRenta $confirmar,
    ) {}

    /**
     * @return int cuántos cargos se confirmaron como pagados
     */
    public function ejecutar(): int
    {
        if (! $this->registro->activa('stripe')) {
            return 0;
        }

        return CargoRenta::query()
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->where('metodo_pago', 'stripe')
            ->whereNotNull('referencia_pago')
            ->where('updated_at', '<=', Carbon::now()->subMinutes(self::MINUTOS_DE_GRACIA))
            ->orderBy('id')
            ->get()
            ->filter(fn (CargoRenta $cargo): bool => $this->conciliar($cargo))
            ->count();
    }

    /**
     * Pregunta por el intento de un cargo y aplica la respuesta. `true` si quedó pagado.
     */
    public function conciliar(CargoRenta $cargo): bool
    {
        $referencia = (string) $cargo->referencia_pago;
        try {
            $estado = $this->stripe->estadoIntento($referencia, $this->registro->llaves('stripe'));
        } catch (Throwable $e) {
            // Sin respuesta: se vuelve a preguntar en la próxima vuelta.
            Log::warning('No se pudo conciliar el cargo de renta con Stripe.', ['cargo' => $cargo->ulid, 'error' => $e->getMessage()]);

            return false;
        }

        if ($estado === 'pagado') {
            $this->confirmar->porReferencia($referencia, 'stripe');

            return $cargo->refresh()->estado === EstadoCargoRenta::Pagado;
        }
        if ($estado === 'terminado') {
            $this->confirmar->intentoTerminado($referencia);
        }

        return false;
    }
}
