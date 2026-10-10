<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\MedirUsoSaas;
use App\Modules\Tenancy\Application\MonedaDeCobroSaas;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\TiposDeCambio;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\ModoCobroSaas;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de facturación SaaS del estudio: plan, modalidad de cobro, estado de la
 * suscripción, prueba y uso del periodo con su cargo estimado (ADR 0019). Es
 * facturación de AgendaUno (control plane), SEPARADA de los pagos que los alumnos
 * hacen al estudio. El uso se mide en vivo en la BD del tenant; aquí no se cobra nada
 * (el pago de la renta lo maneja {@see PagoRentaController}).
 */
class FacturacionController
{
    public function __construct(
        private readonly MedirUsoSaas $medir,
        private readonly GenerarCargoRenta $cargos,
        private readonly PlanCitasSaas $planes,
        private readonly TiposDeCambio $tipos,
        private readonly RegistroDePasarelasPlataforma $pasarelas,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'plan' => $estudio->plan,
            'estado_facturacion' => $estudio->estado_facturacion->value,
            'trial_termina_en' => $estudio->trial_termina_en?->toDateString(),
            'modalidad' => $estudio->modalidad()->value,
            'modo_cobro' => $estudio->modo_cobro->value,
            'cuota_fija_minor' => $estudio->cuota_fija_minor,
            'moneda' => $estudio->moneda,
            'uso' => $this->estimacion($estudio),
        ]]);
    }

    /**
     * Apartado de RENTA del dueño: histórico de cargos de la suscripción SaaS más la
     * estimación de lo que sigue con su desglose: el periodo en curso (mes vencido) o,
     * con plan de citas, el siguiente periodo por adelantado y el plan (ADR 0107).
     */
    public function renta(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $cargos = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->orderByDesc('periodo')
            ->orderByDesc('id')
            ->limit(36)
            ->get();

        // Facturas (CFDI) emitidas de esos cargos, para saber cuáles ya están timbradas.
        $facturas = FacturaPlataforma::query()
            ->whereIn('cargo_renta_id', $cargos->pluck('id')->all())
            ->get()
            ->keyBy('cargo_renta_id');

        $porPlan = $this->planes->aplica($estudio);

        return response()->json(['data' => [
            'modalidad' => $estudio->modalidad()->value,
            'modo_cobro' => $estudio->modo_cobro->value,
            'moneda' => $estudio->moneda,
            'cuota_fija_minor' => $estudio->cuota_fija_minor,
            'cuota_fija_moneda' => $estudio->cuota_fija_moneda,
            'trial_termina_en' => $estudio->trial_termina_en?->toDateString(),
            // Cómo se cobra: por plan contratado, por adelantado (citas), o por uso, mes vencido.
            'cobro' => $porPlan ? 'plan' : 'uso',
            'plan' => $porPlan ? $this->planes->resumen($estudio) : null,
            'tarifa' => $porPlan ? null : $this->tarifa($estudio),
            'ventas' => ['correo' => ConfiguracionPlataforma::ventasCorreo($estudio->producto()), 'whatsapp' => ConfiguracionPlataforma::ventasWhatsApp()],
            // Domiciliación: la tarjeta con que se cobra sola (ADR 0107).
            'tarjeta' => DomiciliacionRenta::tarjeta($estudio),
            'domiciliacion_posible' => $this->pasarelas->activa('stripe'),
            // ¿Puede recibir la factura (CFDI) de la renta? Si no, se ofrece el recibo sin valor fiscal.
            'factura_renta_posible' => $this->facturaRentaPosible(),
            'actual' => $this->estimacion($estudio),
            'cargos' => $cargos->map(function (CargoRenta $c) use ($facturas): array {
                $factura = $facturas->get($c->getKey());

                return [
                    'id' => $c->ulid,
                    'periodo' => $c->periodo,
                    // `renta` (mes vencido), `plan` (por adelantado), `ajuste` (cambio de plan) o `timbres`.
                    'concepto' => $c->concepto ?? 'renta',
                    'cubre_desde' => $c->cubre_desde?->toDateString(),
                    'cubre_hasta' => $c->cubre_hasta?->toDateString(),
                    'monto_tarifa_minor' => $c->monto_tarifa_minor,
                    'moneda_tarifa' => $c->moneda_tarifa,
                    'tipo_cambio' => $c->tipo_cambio_diezmilesimas !== null ? [
                        'valor' => TiposDeCambio::formatear($c->tipo_cambio_diezmilesimas),
                        'fecha' => $c->tipo_cambio_fecha?->toDateString(),
                        'fuente' => $c->tipo_cambio_fuente,
                    ] : null,
                    'modo_cobro' => $c->modo_cobro->value,
                    'metrica' => $c->metrica ?? 'alumnos_activos',
                    'cantidad' => $c->alumnos_activos,
                    'alumnos_activos' => $c->alumnos_activos,
                    'tarifa_version' => $c->tarifa_version,
                    // Con qué se calculó y cuándo se emitió (no cambia después).
                    'regla' => $c->regla_version,
                    'emitido_en' => $c->emitido_en?->toIso8601String(),
                    'desglose' => $c->desglose,
                    'monto_minor' => $c->monto_minor,
                    'moneda' => $c->moneda,
                    'estado' => $c->estado->value,
                    'vence_en' => $c->vence_en?->toDateString(),
                    'pagado_en' => $c->pagado_en?->toIso8601String(),
                    // Cobro a la tarjeta domiciliada: el último rechazo y el siguiente intento.
                    'error_cobro' => $c->error_cobro,
                    'proximo_intento_en' => $c->proximo_intento_en?->toIso8601String(),
                    'factura' => $factura instanceof FacturaPlataforma ? [
                        'id' => $factura->ulid,
                        'estado' => $factura->estado->value,
                        'uuid' => $factura->uuid,
                    ] : null,
                ];
            })->all(),
        ]]);
    }

    /**
     * La factura de la renta se emite con el RFC del negocio: la recibe quien ya lo
     * capturó o quien puede capturarlo (pesos mexicanos y en México, ADR 0099).
     */
    private function facturaRentaPosible(): bool
    {
        $rfc = DatosFiscalesTenant::query()->value('rfc');

        return (is_string($rfc) && $rfc !== '') || app(RegionNegocioTenant::class)->factura();
    }

    /**
     * Quién cuenta en el cobro del periodo (transparencia): los alumnos con actividad o
     * los profesionales que atendieron, con la regla aplicada. Solo para el dueño.
     */
    public function quienCuenta(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate(['periodo' => ['nullable', 'date_format:Y-m']]);
        $periodo = (string) ($validado['periodo'] ?? $this->periodoActual($estudio));

        $uso = $this->medir->calcular($estudio, $periodo, quienes: true);

        return response()->json(['data' => [
            'periodo' => $periodo,
            'metrica' => $uso['metrica'],
            'regla' => $uso['regla_version'],
            'descripcion' => $uso['evidencia']['regla'] ?? null,
            'cantidad' => $uso['cantidad'],
            'detalle' => $uso['detalle'],
            'quienes' => $uso['quienes'] ?? [],
        ]]);
    }

    /**
     * La tarifa por uso que le aplica (para mostrarla): su moneda, la de cobro, el
     * tipo de cambio y los escalones.
     *
     * @return array<string, mixed>|null
     */
    private function tarifa(Estudio $estudio): ?array
    {
        if ($estudio->modo_cobro === ModoCobroSaas::Fijo) {
            return null;
        }
        $definicion = TarifaSaas::vigente($estudio->modalidad())->definicion ?? [];
        $monedaTarifa = MonedaDeCobroSaas::deTarifa($definicion);
        $tipo = $estudio->enMexico() && $monedaTarifa === 'USD' ? $this->tipos->ultimo() : null;

        return [
            'moneda_tarifa' => $monedaTarifa,
            'moneda_cobro' => MonedaDeCobroSaas::deCobro($estudio, $monedaTarifa),
            'iva_porcentaje' => (int) (MonedaDeCobroSaas::conIvaDelPais($definicion, $estudio)['iva_porcentaje'] ?? 16),
            'tipo_cambio' => $tipo === null ? null : [
                'valor' => TiposDeCambio::formatear($tipo['diezmilesimas']), 'fecha' => $tipo['fecha'], 'fuente' => $tipo['fuente'],
            ],
            'bandas' => $estudio->modalidad() === ModalidadServicio::Clases ? ($definicion['bandas'] ?? []) : null,
        ];
    }

    /**
     * Uso del periodo en curso y su cargo estimado (se cobra al cerrar el mes); con
     * plan de citas, el siguiente periodo por adelantado.
     *
     * @return array<string, mixed>
     */
    private function estimacion(Estudio $estudio): array
    {
        if ($this->planes->aplica($estudio)) {
            $siguiente = $this->planes->estimarSiguiente($estudio);

            return [
                'periodo' => substr($siguiente['desde'], 0, 7),
                'metrica' => 'profesionales_contratados',
                'regla' => null,
                'cantidad' => $siguiente['plan']['profesionales'],
                'detalle' => [],
                'alumnos_activos' => $siguiente['plan']['profesionales'],
                'tarifa_version' => $this->planes->tarifa()?->version,
                'cubre_desde' => $siguiente['desde'],
                'cubre_hasta' => $siguiente['hasta'],
                'desglose' => $siguiente['desglose'],
                'cargo_estimado_minor' => $siguiente['desglose']['total_minor'],
            ];
        }

        $periodo = $this->periodoActual($estudio);
        $uso = $this->medir->calcular($estudio, $periodo);
        [$desglose, $version] = $this->cargos->estimar($estudio, $uso['metrica'], $uso['cantidad'], $uso['detalle'], $periodo);

        return [
            'periodo' => $periodo,
            'metrica' => $uso['metrica'],
            'regla' => $uso['regla_version'],
            'cantidad' => $uso['cantidad'],
            'detalle' => $uso['detalle'],
            // Compatibilidad: antes solo se medían alumnos.
            'alumnos_activos' => $uso['cantidad'],
            'tarifa_version' => $version,
            'desglose' => $desglose,
            'cargo_estimado_minor' => $desglose['total_minor'],
        ];
    }

    private function periodoActual(Estudio $estudio): string
    {
        return now((string) ($estudio->zona_horaria ?: 'UTC'))->format('Y-m');
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
