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
 * La compra de un paquete de mensajes de WhatsApp (ADR 0186), como la de timbres: un
 * cargo `whatsapp` que se paga en la página de Stripe; al confirmarse el pago los
 * mensajes se suman al saldo del negocio ({@see AcreditarMensajesWhatsAppPagados}).
 * En México, en pesos con el IVA de la tarifa; fuera de México, como la renta, en
 * dólares y sin IVA (exportación de servicios).
 */
class ComprarMensajesWhatsApp
{
    public function __construct(
        private readonly CobrarCargoRenta $cobrar,
        private readonly MensajesWhatsAppTenant $mensajes,
        private readonly RegistroDePasarelasPlataforma $registro,
    ) {}

    public function comprar(Estudio $estudio, int $cantidad, string $retorno = '/renta'): CargoRenta
    {
        $paquetes = ConfiguracionPlataforma::paquetesWhatsApp();
        if (! in_array($cantidad, $paquetes, true)) {
            throw ValidationException::withMessages(['cantidad' => ['Elige un paquete de '.implode(', ', $paquetes).' mensajes.']]);
        }
        if (! $this->registro->activa('stripe')) {
            throw new PasarelaNoDisponible('La plataforma no tiene una pasarela de cobro activa.');
        }

        $moneda = $this->mensajes->moneda($estudio);
        $precio = $this->mensajes->precioMinor($estudio);
        $subtotal = $cantidad * $precio;
        // En México, el IVA de la tarifa; fuera, ninguno (exportación, ADR 0107).
        $definicion = (array) (TarifaSaas::vigente($estudio->modalidad())->definicion ?? []);
        $ivaPorcentaje = $estudio->enMexico() ? (int) ($definicion['iva_porcentaje'] ?? 16) : 0;
        $iva = intdiv($subtotal * $ivaPorcentaje + 50, 100);
        $hoy = CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'));

        $cargo = CargoRenta::query()->create([
            'estudio_id' => $estudio->getKey(),
            'periodo' => $hoy->format('Y-m'),
            'clave' => 'whatsapp:'.Str::lower((string) Str::ulid()),
            'concepto' => 'whatsapp',
            'modo_cobro' => app(CondicionesDeCobroSaas::class)->al($estudio)['modo_cobro']->value,
            'metrica' => 'whatsapp',
            'alumnos_activos' => $cantidad,
            'desglose' => [
                'lineas' => [[
                    'concepto' => "Paquete de {$cantidad} mensajes de WhatsApp",
                    'detalle' => '$'.number_format($precio / 100, 2).' '.$moneda.' por mensaje',
                    'importe_minor' => $subtotal,
                ]],
                'subtotal_minor' => $subtotal,
                'iva_porcentaje' => $ivaPorcentaje,
                'iva_minor' => $iva,
                'total_minor' => $subtotal + $iva,
                'moneda' => $moneda,
                'conversion' => null,
            ],
            'monto_minor' => $subtotal + $iva,
            'moneda' => $moneda,
            'monto_tarifa_minor' => $subtotal + $iva,
            'moneda_tarifa' => $moneda,
            'estado' => EstadoCargoRenta::Pendiente->value,
            'vence_en' => null,
            'emitido_en' => now(),
        ]);

        $cargo->retorno = $retorno;
        try {
            return $this->cobrar->ejecutar($cargo, 'stripe');
        } catch (Throwable $e) {
            $cargo->update(['estado' => EstadoCargoRenta::Cancelado->value]);

            throw $e;
        }
    }
}
