<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PoliticaAlumnosActivos;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de facturación SaaS del estudio: plan, precio por alumno, estado de la
 * suscripción, trial y uso del periodo. Es facturación de AgendaUno (control plane),
 * SEPARADA de los pagos que los alumnos hacen al estudio (esos viven en la BD del
 * tenant con las pasarelas del propio estudio). El conteo se hace en vivo sobre la
 * BD del tenant ya resuelta; aquí no se cobra nada (el pago de la renta lo maneja
 * {@see PagoRentaController} con la pasarela de la plataforma).
 */
class FacturacionController
{
    public function __construct(private readonly PoliticaAlumnosActivos $politica) {}

    public function show(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $periodo = now($estudio->zona_horaria)->format('Y-m');
        $activos = $this->politica->contar($periodo); // conexión tenant ya activa
        // El cargo depende del modo de cobro (por activos o cuota fija mensual).
        $cargo = $estudio->cargoDelPeriodo($activos);

        return response()->json(['data' => [
            'plan' => $estudio->plan,
            'estado_facturacion' => $estudio->estado_facturacion->value,
            'trial_termina_en' => $estudio->trial_termina_en?->toDateString(),
            'modo_cobro' => $estudio->modo_cobro->value,
            'precio_por_alumno_minor' => $estudio->precio_por_alumno_minor,
            'cuota_fija_minor' => $estudio->cuota_fija_minor,
            'moneda' => $estudio->moneda,
            'uso' => [
                'periodo' => $periodo,
                'alumnos_activos' => $activos,
                'regla' => $this->politica->version(),
                'cargo_estimado_minor' => $cargo,
            ],
        ]]);
    }

    /**
     * Apartado de RENTA del dueño: histórico de cargos de la suscripción SaaS (pendientes
     * y pagados) más la estimación del periodo en curso. Los cargos los genera el
     * scheduler (o el admin); aquí solo se listan.
     */
    public function renta(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $periodo = now($estudio->zona_horaria)->format('Y-m');
        $activos = $this->politica->contar($periodo);

        $cargos = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->orderByDesc('periodo')
            ->limit(24)
            ->get();

        // Facturas (CFDI) emitidas de esos cargos, para saber cuáles ya están timbradas.
        $facturas = FacturaPlataforma::query()
            ->whereIn('cargo_renta_id', $cargos->pluck('id')->all())
            ->get()
            ->keyBy('cargo_renta_id');

        return response()->json(['data' => [
            'modo_cobro' => $estudio->modo_cobro->value,
            'moneda' => $estudio->moneda,
            'precio_por_alumno_minor' => $estudio->precio_por_alumno_minor,
            'cuota_fija_minor' => $estudio->cuota_fija_minor,
            'actual' => [
                'periodo' => $periodo,
                'alumnos_activos' => $activos,
                'cargo_estimado_minor' => $estudio->cargoDelPeriodo($activos),
            ],
            'cargos' => $cargos->map(function (CargoRenta $c) use ($facturas): array {
                $factura = $facturas->get($c->getKey());

                return [
                    'id' => $c->ulid,
                    'periodo' => $c->periodo,
                    'modo_cobro' => $c->modo_cobro->value,
                    'alumnos_activos' => $c->alumnos_activos,
                    'monto_minor' => $c->monto_minor,
                    'moneda' => $c->moneda,
                    'estado' => $c->estado->value,
                    'vence_en' => $c->vence_en?->toDateString(),
                    'pagado_en' => $c->pagado_en?->toIso8601String(),
                    'factura' => $factura instanceof FacturaPlataforma ? [
                        'id' => $factura->ulid,
                        'estado' => $factura->estado->value,
                        'uuid' => $factura->uuid,
                    ] : null,
                ];
            })->all(),
        ]]);
    }
}
