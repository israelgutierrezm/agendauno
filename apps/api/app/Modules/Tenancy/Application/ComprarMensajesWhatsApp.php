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
 * cargo `whatsapp` en pesos (con IVA) que se paga en la página de Stripe; al
 * confirmarse el pago los mensajes se suman al saldo del negocio
 * ({@see AcreditarMensajesWhatsAppPagados}).
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

        $precio = $this->mensajes->precioMinor();
        $subtotal = $cantidad * $precio;
        // Como la renta: el IVA del país del negocio (ADR 0107).
        $definicion = MonedaDeCobroSaas::conIvaDelPais((array) (TarifaSaas::vigente($estudio->modalidad())->definicion ?? []), $estudio);
        $ivaPorcentaje = (int) ($definicion['iva_porcentaje'] ?? 16);
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
                    'detalle' => '$'.number_format($precio / 100, 2).' por mensaje',
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
            $cargo->update(['estado' => EstadoCargoRenta::Cancelado->value]);

            throw $e;
        }
    }
}
