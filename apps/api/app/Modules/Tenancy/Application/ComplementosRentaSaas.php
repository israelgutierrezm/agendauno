<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\ModuloNegocio;
use Carbon\CarbonImmutable;

/**
 * Lo que se cobra aparte de la renta por uso (ADR 0188), con precio publicado que fija
 * el superadmin (en pesos, sin IVA):
 *
 * - App en tiendas con la marca del negocio: alta (una vez, al pedirla) y una
 *   mensualidad mientras el módulo está encendido, al precio del día en que se encendió.
 *   La mensualidad se emite cada mes cerrado como cargo `complemento`, aparte de la renta.
 * - Arranque acompañado (sin o con migración): un pago único; gratis con el plan anual.
 */
class ComplementosRentaSaas
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly MonedaDeCobroSaas $moneda,
    ) {}

    /**
     * Los precios de lo que se ofrece aparte (en centavos de peso, sin IVA).
     *
     * @return array{arranque_minor: int, arranque_migracion_minor: int, app_marca_alta_minor: int, app_marca_mensual_minor: int, moneda: string}
     */
    public function precios(): array
    {
        return [
            'arranque_minor' => $this->parametros->entero('servicios.arranque_centavos'),
            'arranque_migracion_minor' => $this->parametros->entero('servicios.arranque_migracion_centavos'),
            'app_marca_alta_minor' => $this->parametros->entero('servicios.app_marca_alta_centavos'),
            'app_marca_mensual_minor' => $this->parametros->entero('servicios.app_marca_mensual_centavos'),
            'moneda' => 'MXN',
        ];
    }

    /**
     * ¿Tuvo la app con su marca encendida en algún momento de ese mes? Encendida antes de
     * guardar las fechas (sin `app_marca_desde`), cuenta como encendida desde siempre.
     */
    public static function encendidaEn(Estudio $estudio, string $periodo): bool
    {
        $desde = $estudio->getAttribute('app_marca_desde');
        $hasta = $estudio->getAttribute('app_marca_hasta');
        if ($desde === null && $hasta === null) {
            return $estudio->moduloHabilitado(ModuloNegocio::AppConMarca);
        }
        $inicio = $periodo.'-01';
        $fin = CarbonImmutable::parse($inicio)->endOfMonth()->toDateString();
        $desde = $desde !== null ? substr((string) $desde, 0, 10) : null;
        $hasta = $hasta !== null ? substr((string) $hasta, 0, 10) : null;

        return ($desde === null || $desde <= $fin) && ($hasta === null || $hasta >= $inicio);
    }

    /** ¿El arranque le sale gratis? Con el plan anual, sí. */
    public function arranqueGratis(Estudio $estudio): bool
    {
        return $estudio->plan_periodicidad === 'anual';
    }

    /**
     * Se enciende la app con su marca: guarda el precio de hoy, que es el que paga mientras
     * siga encendida. Si la había apagado, antes se emite lo que faltaba de esa vez, a su
     * precio, porque las fechas nuevas ya no lo cubren.
     */
    public function encenderAppMarca(Estudio $estudio): void
    {
        $hoy = CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'));
        if ($estudio->getAttribute('app_marca_hasta') !== null) {
            $this->emitirMensualidades($estudio, $hoy->subMonthNoOverflow()->format('Y-m'));
        }
        $estudio->forceFill([
            'app_marca_desde' => $hoy->toDateString(),
            'app_marca_hasta' => null,
            'app_marca_mensual_minor' => $this->precios()['app_marca_mensual_minor'],
        ])->save();
    }

    /**
     * Las mensualidades de la app con su marca hasta ese mes cerrado, sin saltarse
     * ninguno: una corrida que no llegó o un mes en que el negocio no se revisó se emiten
     * en la siguiente. Una vez por mes (clave `app_marca:AAAA-MM`).
     *
     * @return list<CargoRenta> las que se emitieron ahora
     */
    public function emitirMensualidades(Estudio $estudio, string $hastaPeriodo): array
    {
        $emitidas = [];
        $mes = $this->primerMesPorEmitir($estudio, $hastaPeriodo);
        for (; $mes <= $hastaPeriodo; $mes = CarbonImmutable::parse($mes.'-01')->addMonthNoOverflow()->format('Y-m')) {
            $cargo = $this->emitirMensualidad($estudio, $mes);
            if ($cargo?->wasRecentlyCreated === true) {
                $emitidas[] = $cargo;
            }
        }

        return $emitidas;
    }

    /**
     * Desde el mes en que se encendió. Encendida antes de guardar las fechas no se sabe
     * desde cuándo: se sigue desde la última que se emitió.
     */
    private function primerMesPorEmitir(Estudio $estudio, string $hastaPeriodo): string
    {
        $desde = $estudio->getAttribute('app_marca_desde');
        if ($desde !== null) {
            return substr((string) $desde, 0, 7);
        }
        $ultima = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('metrica', 'app_marca')
            ->max('periodo');

        return is_string($ultima) && $ultima !== '' ? $ultima : $hastaPeriodo;
    }

    /** El precio guardado al encenderla; si se encendió sin guardarlo, se fija hoy. */
    private function precioMensualDe(Estudio $estudio): int
    {
        $guardado = $estudio->getAttribute('app_marca_mensual_minor');
        if ($guardado !== null) {
            return (int) $guardado;
        }
        $precio = $this->precios()['app_marca_mensual_minor'];
        $estudio->forceFill(['app_marca_mensual_minor' => $precio])->save();

        return $precio;
    }

    private function emitirMensualidad(Estudio $estudio, string $periodo): ?CargoRenta
    {
        if (! self::encendidaEn($estudio, $periodo)) {
            return null;
        }
        $subtotal = $this->precioMensualDe($estudio);
        if ($subtotal <= 0) {
            return null;
        }
        $ivaPorcentaje = (int) (TarifaSaas::vigente($estudio->modalidad())->definicion['iva_porcentaje'] ?? 16);
        $iva = $estudio->enMexico() ? intdiv($subtotal * $ivaPorcentaje + 50, 100) : 0;
        $hoy = CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'));
        $dias = max(1, $this->parametros->entero('renta.dias_para_pagar'));

        return CargoRenta::query()->firstOrCreate(
            ['estudio_id' => $estudio->getKey(), 'periodo' => $periodo, 'clave' => 'app_marca:'.$periodo],
            [
                'concepto' => 'complemento',
                'modo_cobro' => app(CondicionesDeCobroSaas::class)->en($estudio, $periodo)['modo_cobro']->value,
                'metrica' => 'app_marca',
                'alumnos_activos' => 1,
                'desglose' => [
                    'lineas' => [[
                        'concepto' => 'App en tiendas con tu marca',
                        'detalle' => $periodo,
                        'importe_minor' => $subtotal,
                    ]],
                    'subtotal_minor' => $subtotal,
                    'iva_porcentaje' => $estudio->enMexico() ? $ivaPorcentaje : 0,
                    'iva_minor' => $iva,
                    'total_minor' => $subtotal + $iva,
                    'moneda' => 'MXN',
                    'conversion' => null,
                ],
                'monto_minor' => $subtotal + $iva,
                'moneda' => 'MXN',
                'monto_tarifa_minor' => $subtotal + $iva,
                'moneda_tarifa' => 'MXN',
                'estado' => $this->moneda->cobrable($subtotal + $iva, 'MXN') ? EstadoCargoRenta::Pendiente->value : EstadoCargoRenta::SinCargo->value,
                'vence_en' => $hoy->addDays($dias)->toDateString(),
                'emitido_en' => now(),
            ],
        );
    }
}
