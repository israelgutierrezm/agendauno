<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CargoRentaNoPagable;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que el superadmin registra a mano en la renta (ADR 0214):
 *
 * - **Un pago recibido fuera de la pasarela** (transferencia, depósito, efectivo o
 *   cheque): el cargo queda pagado con su método y su referencia, y sigue lo de
 *   cualquier pago (reactivar, timbres, referidos). Su CFDI sale con esa forma de pago.
 * - **Un cargo manual** (una configuración, una migración, un servicio aparte): con su
 *   descripción e importe IVA incluido; se paga como cualquier otro.
 */
class CobrosManualesRenta
{
    /** Métodos de un pago registrado a mano y su forma de pago del SAT. */
    public const METODOS = [
        'transferencia' => '03',
        'deposito' => '01',
        'efectivo' => '01',
        'cheque' => '02',
    ];

    public function __construct(private readonly ConfirmarCargoRenta $confirmar) {}

    public function registrarPago(CargoRenta $cargo, string $metodo, ?string $referencia, ?Carbon $pagadoEn): CargoRenta
    {
        $pagado = DB::transaction(function () use ($cargo, $metodo, $referencia, $pagadoEn): CargoRenta {
            $bloqueado = CargoRenta::query()->whereKey($cargo->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoCargoRenta::Pendiente) {
                throw new CargoRentaNoPagable('Solo se registra el pago de un cargo pendiente.');
            }
            $bloqueado->update([
                'estado' => EstadoCargoRenta::Pagado->value,
                'pagado_en' => $pagadoEn ?? Carbon::now(),
                'metodo_pago' => $metodo,
                // Con su prefijo: nunca coincide con la de una pasarela.
                'referencia_pago' => 'manual:'.($referencia !== null && $referencia !== '' ? $referencia : Str::lower((string) Str::ulid())),
                'proximo_intento_en' => null,
            ]);

            return $bloqueado;
        });
        $this->confirmar->despuesDelPago($pagado);

        return $pagado->refresh();
    }

    public function crearCargo(Estudio $estudio, string $descripcion, int $totalMinor, string $moneda, Carbon $venceEn): CargoRenta
    {
        // IVA incluido: en México, el general; fuera, sin IVA (exportación).
        $ivaPorcentaje = $estudio->enMexico() ? EmitirFacturaPlataforma::IVA_POR_OMISION : 0;
        $subtotal = intdiv($totalMinor * 100, 100 + $ivaPorcentaje);
        $hoy = Carbon::now((string) ($estudio->zona_horaria ?: 'America/Mexico_City'));

        return CargoRenta::query()->create([
            'estudio_id' => $estudio->getKey(),
            'periodo' => $hoy->format('Y-m'),
            'clave' => 'manual:'.Str::lower((string) Str::ulid()),
            'concepto' => 'manual',
            'modo_cobro' => app(CondicionesDeCobroSaas::class)->al($estudio)['modo_cobro']->value,
            'metrica' => 'manual',
            'alumnos_activos' => 0,
            'desglose' => [
                'descripcion' => $descripcion,
                'lineas' => [['concepto' => $descripcion, 'detalle' => null, 'importe_minor' => $subtotal]],
                'subtotal_minor' => $subtotal,
                'iva_porcentaje' => $ivaPorcentaje,
                'iva_minor' => $totalMinor - $subtotal,
                'total_minor' => $totalMinor,
                'moneda' => $moneda,
                'conversion' => null,
            ],
            'monto_minor' => $totalMinor,
            'moneda' => $moneda,
            'monto_tarifa_minor' => $totalMinor,
            'moneda_tarifa' => $moneda,
            'estado' => EstadoCargoRenta::Pendiente->value,
            'vence_en' => $venceEn->toDateString(),
            'emitido_en' => Carbon::now(),
        ]);
    }

    /**
     * La forma de pago del SAT con que se cobró el cargo: la del pago registrado a mano;
     * con una pasarela, la configurada (tarjeta, por omisión).
     */
    public static function formaPagoSat(CargoRenta $cargo): string
    {
        return self::METODOS[(string) $cargo->metodo_pago] ?? (string) config('agendauno.facturapi.renta.forma_pago');
    }
}
