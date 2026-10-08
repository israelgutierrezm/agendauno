<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La compra de un paquete de timbres (ADR 0107): un cargo `timbres` en pesos (con
 * IVA) que se paga en la página de Stripe; al confirmarse el pago los timbres se suman
 * al saldo del negocio ({@see AcreditarTimbresPagados}). Una compra no pagada no
 * vence ni suspende al negocio; si su pago caduca, se cancela.
 */
class ComprarTimbres
{
    public function __construct(
        private readonly CobrarCargoRenta $cobrar,
        private readonly TimbresTenant $timbres,
        private readonly RegistroDePasarelasPlataforma $registro,
    ) {}

    public function comprar(Estudio $estudio, int $cantidad, string $retorno = '/renta'): CargoRenta
    {
        $paquetes = ConfiguracionPlataforma::paquetesTimbres();
        if (! in_array($cantidad, $paquetes, true)) {
            throw ValidationException::withMessages(['cantidad' => ['Elige un paquete de '.implode(', ', $paquetes).' timbres.']]);
        }
        if (! $this->registro->activa('stripe')) {
            throw new PasarelaNoDisponible('La plataforma no tiene una pasarela de cobro activa.');
        }

        $precio = $this->timbres->precioTimbreMinor();
        $subtotal = $cantidad * $precio;
        $ivaPorcentaje = (int) (TarifaSaas::vigente($estudio->modalidad())->definicion['iva_porcentaje'] ?? 16);
        $iva = intdiv($subtotal * $ivaPorcentaje + 50, 100);
        $hoy = CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'));

        $cargo = CargoRenta::query()->create([
            'estudio_id' => $estudio->getKey(),
            'periodo' => $hoy->format('Y-m'),
            'clave' => 'timbres:'.Str::lower((string) Str::ulid()),
            'concepto' => 'timbres',
            'modo_cobro' => $estudio->modo_cobro->value,
            'metrica' => 'timbres',
            'alumnos_activos' => $cantidad,
            'desglose' => [
                'lineas' => [[
                    'concepto' => "Paquete de {$cantidad} timbres",
                    'detalle' => '$'.number_format($precio / 100, 2).' por timbre',
                    'importe_minor' => $subtotal,
                ]],
                'subtotal_minor' => $subtotal,
                'iva_porcentaje' => $ivaPorcentaje,
                'iva_minor' => $iva,
                'total_minor' => $subtotal + $iva,
                'moneda' => 'MXN',
                'conversion' => null,
            ],
            'monto_minor' => $subtotal + $iva,
            'moneda' => 'MXN',
            'monto_tarifa_minor' => $subtotal + $iva,
            'moneda_tarifa' => 'MXN',
            'estado' => EstadoCargoRenta::Pendiente->value,
            'vence_en' => null,
            'emitido_en' => now(),
        ]);

        $cargo->retorno = $retorno;
        try {
            return $this->cobrar->ejecutar($cargo, 'stripe');
        } catch (Throwable $e) {
            // Sin página de pago, la compra no queda colgada.
            $cargo->update(['estado' => EstadoCargoRenta::Cancelado->value]);

            throw $e;
        }
    }
}
