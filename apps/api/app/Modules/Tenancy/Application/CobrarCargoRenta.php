<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CargoRentaNoPagable;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\PasarelaMercadoPagoPlataforma;
use App\Modules\Tenancy\Pasarelas\PasarelaOpenPayPlataforma;
use App\Modules\Tenancy\Pasarelas\PasarelaStripePlataforma;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        private readonly SuspensionPorRenta $suspension,
        private readonly ConciliarCargosRenta $conciliar,
        private readonly AcreditarTimbresPagados $timbres,
        private readonly ConfirmarCargoRenta $confirmar,
        private readonly PasarelaMercadoPagoPlataforma $mercadoPago,
        private readonly PasarelaOpenPayPlataforma $openPay,
    ) {}

    public function ejecutar(CargoRenta $cargo, string $proveedor): CargoRenta
    {
        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela de la plataforma no esta activa.');
        }

        // Un intento anterior ya pagado cuyo aviso no llegó: se confirma y no se cobra
        // de nuevo.
        if ($cargo->estado === EstadoCargoRenta::Pendiente && in_array($cargo->metodo_pago, ['stripe', 'mercadopago', 'openpay'], true)
            && (string) $cargo->referencia_pago !== '' && $this->conciliar->conciliar($cargo)) {
            return $cargo->refresh();
        }

        // Un cobro a la tarjeta domiciliada que quedó sin respuesta pudo hacerse: se busca
        // en Stripe antes de abrir otro pago (si no, se cobraría dos veces).
        if ($proveedor === 'stripe' && $cargo->estado === EstadoCargoRenta::Pendiente && (string) $cargo->referencia_pago === '') {
            $previo = $this->cobroDomiciliadoSinRegistrar($cargo);
            if (($previo['status'] ?? '') === 'succeeded') {
                $this->confirmar->porReferencia($previo['id'], 'stripe', (string) $cargo->ulid, $cargo->monto_minor);
                if ($cargo->refresh()->estado === EstadoCargoRenta::Pagado) {
                    return $cargo;
                }
            }
            if (in_array($previo['status'] ?? '', ['processing', 'requires_capture'], true)) {
                throw new CargoRentaNoPagable('Ya hay un pago en proceso para esta renta; espera su confirmación.');
            }
        }

        $cobrado = DB::transaction(function () use ($cargo, $proveedor): CargoRenta {
            $bloqueado = CargoRenta::query()->whereKey($cargo->getKey())->lockForUpdate()->firstOrFail();
            // A dónde vuelve tras pagar no se guarda: viaja con el cargo que se cobra.
            $bloqueado->retorno = $cargo->retorno;

            if ($bloqueado->estado !== EstadoCargoRenta::Pendiente) {
                throw new CargoRentaNoPagable('El cargo no admite cobro.');
            }

            // Reintento: el intento anterior (sesión abierta) se vence antes de abrir
            // otro; si ya se pagó, se espera su confirmación en vez de cobrar de nuevo.
            $anterior = (string) $bloqueado->referencia_pago;
            if ($anterior !== '' && ! $this->cerrarAnterior($bloqueado, $anterior)) {
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

        // Pagado al momento: si estaba suspendido por renta y ya no debe, se reactiva.
        if ($cobrado->estado === EstadoCargoRenta::Pagado) {
            $this->suspension->reactivarSiPago($cobrado->estudio);
            $this->timbres->aplicar($cobrado);
        }

        return $cobrado;
    }

    /**
     * Cierra el intento anterior, de la pasarela con que se abrió, antes de abrir otro.
     * False si ya se pagó o se está cobrando (ADR 0192).
     */
    private function cerrarAnterior(CargoRenta $cargo, string $anterior): bool
    {
        return match ($cargo->metodo_pago) {
            'stripe' => $this->stripe->cancelarIntento($anterior, $this->registro->llaves('stripe')),
            'mercadopago' => $this->mercadoPago->cancelarIntento($cargo, $this->registro->llaves('mercadopago')),
            // La página de pago de OpenPay no se cierra: solo se espera si ya se cobró.
            'openpay' => ! in_array($this->openPay->cargo($anterior, $this->registro->llaves('openpay'))['estado'], ['completed', 'in_progress'], true),
            default => true,
        };
    }

    /**
     * El cobro a la tarjeta domiciliada del cargo en Stripe, si el negocio tiene tarjeta
     * domiciliada. Si Stripe no responde, se sigue (no se impide pagar).
     *
     * @return array{id: string, status: string}|null
     */
    private function cobroDomiciliadoSinRegistrar(CargoRenta $cargo): ?array
    {
        $cargo->loadMissing('estudio');
        if ((string) $cargo->estudio?->domiciliacion_metodo === '') {
            return null;
        }

        try {
            return $this->stripe->cobroDomiciliadoDe($cargo, $this->registro->llaves('stripe'));
        } catch (Throwable $e) {
            ReporteDeErrores::reportarAtrapado($e);

            return null;
        }
    }
}
