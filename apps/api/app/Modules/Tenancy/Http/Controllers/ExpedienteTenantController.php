<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AccesoExpedienteTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WaiverTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expediente de una persona (miembro o instructor): todo lo que se le ha cargado o
 * ha llenado, en un solo lugar: sus documentos (con su validación), los
 * consentimientos vigentes (firmados o pendientes) y los formularios que le aplican
 * (con sus respuestas legibles: etiqueta → valor).
 */
class ExpedienteTenantController
{
    public function __construct(private readonly WaiversTenant $waivers) {}

    public function show(Request $request): JsonResponse
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario && AccesoExpedienteTenant::puedeVer($usuario, $persona), 403);

        return response()->json(['data' => [
            'persona' => [
                'id' => $persona->ulid,
                'nombre' => $persona->nombreCompleto(),
                'tipo' => $persona->tipo->value,
            ],
            'documentos' => $this->documentos($persona),
            'consentimientos' => $this->consentimientos($persona),
            'formularios' => $this->formularios($persona),
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentos(PersonaTenant $persona): array
    {
        return Documento::query()
            ->with('tipo')
            ->where('persona_id', $persona->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Documento $d): array => [
                'id' => $d->ulid,
                'nombre' => $d->nombre,
                'tipo' => $d->tipo?->nombre,
                'estado' => $d->estado->value,
                'motivo' => $d->motivo,
                'subido_en' => $d->subido_en?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Los consentimientos vigentes del estudio y, por cada uno, si la persona ya lo
     * firmó (esa versión) y cuándo. Son de los alumnos (responsivas de clase): al
     * personal no le aplican.
     *
     * @return list<array<string, mixed>>
     */
    private function consentimientos(PersonaTenant $persona): array
    {
        if ($persona->tipo !== TipoPersonaTenant::Miembro) {
            return [];
        }

        $aceptaciones = AceptacionWaiverTenant::query()
            ->where('persona_id', $persona->getKey())
            ->get()
            ->keyBy('waiver_id');

        return $this->waivers->vigentes()
            ->map(static function (WaiverTenant $w) use ($aceptaciones): array {
                $aceptacion = $aceptaciones->get($w->getKey());

                return [
                    'id' => $w->ulid,
                    'titulo' => $w->titulo,
                    'version' => $w->version,
                    'aceptado_en' => $aceptacion instanceof AceptacionWaiverTenant
                        ? $aceptacion->aceptado_en->toIso8601String()
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Formularios activos que aplican a su tipo (miembro/instructor) con su respuesta.
     *
     * @return list<array<string, mixed>>
     */
    private function formularios(PersonaTenant $persona): array
    {
        $respuestas = RespuestaFormulario::query()
            ->where('persona_id', $persona->getKey())
            ->get()
            ->keyBy('formulario_id');

        return Formulario::query()
            ->with('campos')
            ->where('activo', true)
            ->whereIn('aplica_a', [$persona->tipo->value, 'todos'])
            ->orderBy('nombre')
            ->get()
            ->map(static function (Formulario $f) use ($respuestas): array {
                $respuesta = $respuestas->get($f->getKey());
                $valores = $respuesta instanceof RespuestaFormulario ? ($respuesta->valores ?? []) : [];

                return [
                    'id' => $f->ulid,
                    'nombre' => $f->nombre,
                    'respondido_en' => $respuesta instanceof RespuestaFormulario
                        ? $respuesta->updated_at?->toIso8601String()
                        : null,
                    'respuestas' => $f->campos
                        ->map(static fn (CampoFormulario $c): array => [
                            'campo' => $c->etiqueta,
                            'valor' => $valores[$c->ulid] ?? null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
