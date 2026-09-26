<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\CobroDeSuscripcion;
use App\Modules\Tenancy\Pasarelas\PasarelaConSuscripcion;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pago automático por suscripción (Mercado Pago, OpenPay): la pasarela cobra cada
 * periodo por su cuenta y aquí se asienta lo que cobró. Cada cobro aprobado paga la
 * deuda del periodo (orden de renovación → fulfillment → la fecha avanza y la mora
 * se regulariza); cada rechazo abre o avanza la mora con el motivo. Se registra una
 * sola vez por cobro (su referencia en la pasarela), la pida el aviso (webhook) o la
 * corrida diaria del cobro de renovaciones.
 */
class ConciliarSuscripcionTenant
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly FulfillmentTenant $fulfillment,
        private readonly GestionarDunningTenant $dunning,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    /**
     * La pasarela avisó que la suscripción cambió: se activa al autorizarse, se da
     * por cancelada si ya no cobra, y se concilian sus cobros.
     */
    public function actualizar(DomiciliacionTenant $domiciliacion): void
    {
        $pasarela = $this->pasarela($domiciliacion);
        if (! $pasarela instanceof PasarelaConSuscripcion) {
            return;
        }

        $consulta = $pasarela->consultarSuscripcion($domiciliacion, $this->registro->llaves($domiciliacion->proveedor));
        if ($consulta['estado'] === 'activa' && $domiciliacion->estado === DomiciliacionTenant::PENDIENTE) {
            $this->domiciliaciones->activarSuscripcion($domiciliacion, $consulta['tarjeta']);
        } elseif ($consulta['estado'] === 'cancelada' && $domiciliacion->estado !== DomiciliacionTenant::CANCELADA) {
            $domiciliacion->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);
        }

        if ($domiciliacion->estado === DomiciliacionTenant::ACTIVA) {
            $this->conciliar($domiciliacion);
        }
    }

    /**
     * Asienta los cobros que la pasarela hizo y aún no están registrados.
     * Devuelve 'cobrado', 'fallido' (lo último asentado) o 'sin_cambios'.
     */
    public function conciliar(DomiciliacionTenant $domiciliacion): string
    {
        $pasarela = $this->pasarela($domiciliacion);
        $acuerdo = $domiciliacion->acuerdo;
        if (! $pasarela instanceof PasarelaConSuscripcion || ! $acuerdo instanceof AcuerdoTenant) {
            return 'sin_cambios';
        }

        $resultado = 'sin_cambios';
        foreach ($pasarela->cobrosDeSuscripcion($domiciliacion, $this->registro->llaves($domiciliacion->proveedor)) as $cobro) {
            $asentado = $this->asentar($domiciliacion, $acuerdo, $cobro);
            if ($asentado !== null) {
                $resultado = $asentado;
            }
        }

        return $resultado;
    }

    /**
     * Registra un cobro (una sola vez). Devuelve 'cobrado', 'fallido' o null si ya
     * estaba o no hay deuda a la cual aplicarlo.
     */
    private function asentar(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, CobroDeSuscripcion $cobro): ?string
    {
        if ($cobro->id === '') {
            return null;
        }

        $resultado = DB::connection('tenant')->transaction(function () use ($domiciliacion, $acuerdo, $cobro): ?string {
            // Serializa por domiciliación: el aviso y la corrida diaria pueden coincidir.
            DomiciliacionTenant::query()->whereKey($domiciliacion->getKey())->lockForUpdate()->first();
            $registrado = PagoTenant::query()
                ->where('proveedor', $domiciliacion->proveedor)
                ->where('referencia_externa', $cobro->id)
                ->exists();
            $orden = $registrado ? null : $this->deudas->de($acuerdo);
            if ($orden === null) {
                return null;
            }

            PagoTenant::query()->create([
                'orden_id' => $orden->getKey(),
                'proveedor' => $domiciliacion->proveedor,
                'metodo' => MetodoPago::Tarjeta->value,
                'estado' => ($cobro->aprobado ? EstadoPago::Aprobado : EstadoPago::Rechazado)->value,
                'monto_minor' => $cobro->montoMinor > 0 ? $cobro->montoMinor : $orden->total_minor,
                'moneda' => $orden->moneda,
                'referencia_externa' => $cobro->id,
                'domiciliacion_id' => $domiciliacion->getKey(),
                'aprobado_en' => $cobro->aprobado ? now() : null,
            ]);
            if ($cobro->aprobado) {
                // Paga la deuda del periodo: la fecha avanza y la mora se regulariza.
                $this->fulfillment->cumplir($orden);
            }

            return $cobro->aprobado ? 'cobrado' : 'fallido';
        });

        if ($resultado === 'cobrado') {
            $this->domiciliaciones->registrarCargo($domiciliacion, null);
        } elseif ($resultado === 'fallido') {
            $motivo = $cobro->motivo ?? 'El banco rechazó el cargo.';
            $this->domiciliaciones->registrarCargo($domiciliacion, $motivo);
            $this->dunning->registrarFallo($acuerdo, 'No pudimos cobrar tu pago automático: '.$motivo);
        }

        return $resultado;
    }

    private function pasarela(DomiciliacionTenant $domiciliacion): ?PasarelaConSuscripcion
    {
        $pasarela = $this->registro->resolver($domiciliacion->proveedor);

        return $pasarela instanceof PasarelaConSuscripcion ? $pasarela : null;
    }
}
