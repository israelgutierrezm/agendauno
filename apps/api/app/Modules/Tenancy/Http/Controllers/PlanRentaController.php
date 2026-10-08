<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El dueño de un negocio de citas elige o cambia su plan (ADR 0107): nivel,
 * profesionales y mensual o anual. Subir se cobra al momento (la diferencia de los
 * días que faltan); bajar aplica desde el siguiente periodo.
 */
class PlanRentaController
{
    public function __construct(
        private readonly PlanCitasSaas $planes,
        private readonly RegistrarAuditoria $auditoria,
        private readonly DomiciliacionRenta $domiciliacion,
    ) {}

    public function cambiar(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $validado = $request->validate([
            'nivel' => ['required', Rule::in(PlanCitasSaas::NIVELES)],
            'profesionales' => ['required', 'integer', 'min:1', 'max:1000'],
            'periodicidad' => ['required', Rule::in(PlanCitasSaas::PERIODICIDADES)],
        ]);

        $antes = $this->planes->planActual($estudio);
        $resultado = $this->planes->cambiar($estudio, (string) $validado['nivel'], (int) $validado['profesionales'], (string) $validado['periodicidad']);

        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'plan.cambiado', 'plan', null,
            ['nivel' => $antes['nivel'], 'profesionales' => $antes['profesionales'], 'periodicidad' => $antes['periodicidad']],
            ['nivel' => $validado['nivel'], 'profesionales' => (int) $validado['profesionales'], 'periodicidad' => $validado['periodicidad'], 'aplica' => $resultado['aplica']],
        );

        $ajuste = $resultado['ajuste'];
        if ($ajuste !== null) {
            $this->domiciliacion->cobrar($ajuste);
            $ajuste->refresh();
        }

        return response()->json(['data' => [
            'aplica' => $resultado['aplica'],
            'ajuste' => $ajuste === null ? null : [
                'id' => $ajuste->ulid,
                'monto_minor' => $ajuste->monto_minor,
                'moneda' => $ajuste->moneda,
                'estado' => $ajuste->estado->value,
            ],
            'plan' => $this->planes->resumen($estudio->refresh()),
        ]]);
    }
}
