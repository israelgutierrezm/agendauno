<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use App\Modules\Tenancy\Pasarelas\PasarelaMercadoPagoPlataforma;
use App\Modules\Tenancy\Pasarelas\PasarelaOpenPayPlataforma;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La renta pagada con Mercado Pago u OpenPay (ADR 0192). Un aviso solo dice qué cambió:
 * el cobro se relee en la API con las llaves de la plataforma antes de confirmar, así
 * un aviso falso no puede dar por pagada una renta. Lo mismo sirve para conciliar un
 * cargo cuyo aviso no llegó. Idempotente: las pasarelas repiten avisos.
 */
class AvisosRentaPlataforma
{
    public function __construct(
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly ConfirmarCargoRenta $confirmar,
        private readonly PasarelaMercadoPagoPlataforma $mercadoPago,
        private readonly PasarelaOpenPayPlataforma $openPay,
    ) {}

    /** Aviso de Mercado Pago: `payment` con el id del cobro. */
    public function mercadoPago(string $tipo, string $id): void
    {
        if ($tipo !== 'payment' || $id === '' || ! $this->registro->activa('mercadopago')) {
            return;
        }
        $cobro = PasarelaMercadoPagoPlataforma::api($this->registro->llaves('mercadopago'))->pago($id);
        $cargo = (string) ($cobro['external_reference'] ?? '');
        if (($cobro['status'] ?? '') === 'approved' && $cargo !== '') {
            $this->confirmar->porReferencia($id, 'mercadopago', $cargo, MontoDecimal::aCentavos((float) ($cobro['transaction_amount'] ?? 0)));
        }
    }

    /**
     * Aviso de OpenPay: `charge.succeeded`, `charge.failed` o `charge.cancelled`.
     *
     * @param  array<string, mixed>  $evento
     */
    public function openPay(array $evento): void
    {
        $tipo = (string) ($evento['type'] ?? '');
        $transaccion = is_array($evento['transaction'] ?? null) ? $evento['transaction'] : [];
        $id = (string) ($transaccion['id'] ?? '');
        if ($id === '' || ! in_array($tipo, ['charge.succeeded', 'charge.failed', 'charge.cancelled'], true)
            || ! $this->registro->activa('openpay')) {
            return;
        }
        $this->aplicarOpenPay($id);
    }

    /**
     * Pregunta por un cargo pendiente pagado con Mercado Pago u OpenPay. `true` si quedó
     * pagado.
     */
    public function conciliar(CargoRenta $cargo): bool
    {
        try {
            if ($cargo->metodo_pago === 'mercadopago' && $this->registro->activa('mercadopago')) {
                $aprobado = $this->mercadoPago->cobrosDe($cargo, $this->registro->llaves('mercadopago'))['aprobado'];
                if ($aprobado !== null && $aprobado['id'] !== '') {
                    $this->confirmar->porReferencia($aprobado['id'], 'mercadopago', (string) $cargo->ulid, $aprobado['monto_minor']);
                }
            }
            if ($cargo->metodo_pago === 'openpay' && (string) $cargo->referencia_pago !== '' && $this->registro->activa('openpay')) {
                $this->aplicarOpenPay((string) $cargo->referencia_pago);
            }
        } catch (Throwable $e) {
            // Sin respuesta: se vuelve a preguntar en la próxima vuelta.
            Log::warning('No se pudo conciliar el cargo de renta.', ['cargo' => $cargo->ulid, 'proveedor' => $cargo->metodo_pago, 'error' => $e->getMessage()]);

            return false;
        }

        return $cargo->refresh()->estado->value === 'pagado';
    }

    /**
     * Un cargo condonado con su intento de OpenPay abierto: si se pagó, se avisa del
     * cobro doble (el cargo sigue condonado); si ya no se puede pagar, se deja de vigilar.
     */
    public function vigilarCondonado(CargoRenta $cargo): void
    {
        $id = (string) $cargo->referencia_condonada;
        if ($id === '' || ! $this->registro->activa('openpay')) {
            return;
        }
        try {
            $intento = $this->openPay->cargo($id, $this->registro->llaves('openpay'));
        } catch (Throwable $e) {
            Log::warning('No se pudo revisar el intento de OpenPay de un cargo condonado.', ['cargo' => $cargo->ulid, 'error' => $e->getMessage()]);

            return;
        }
        if ($intento['estado'] === 'completed') {
            $this->confirmar->porReferencia($id, 'openpay', (string) $cargo->ulid, $intento['monto_minor']);
        }
        if (in_array($intento['estado'], ['completed', 'failed', 'cancelled'], true)) {
            $cargo->forceFill(['referencia_condonada' => null])->save();
        }
    }

    private function aplicarOpenPay(string $id): void
    {
        $cargo = $this->openPay->cargo($id, $this->registro->llaves('openpay'));
        if ($cargo['estado'] === 'completed' && $cargo['cargo_renta'] !== '') {
            $this->confirmar->porReferencia($id, 'openpay', $cargo['cargo_renta'], $cargo['monto_minor']);
        }
        if (in_array($cargo['estado'], ['failed', 'cancelled'], true)) {
            $this->confirmar->intentoTerminado($id);
        }
    }
}
