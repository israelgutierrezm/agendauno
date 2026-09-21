<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Comunicaciones\CanalComunicacion;
use App\Modules\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Application\DifundirComunicacionTenant;
use App\Modules\Tenancy\Application\ResolverSegmentoTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comunicaciones segmentadas (difusiones): envío puntual de un asunto/cuerpo a un
 * SEGMENTO dinámico (todos / por vencer / vencidos / primerizos). Lista los segmentos
 * con su conteo en vivo, dispara la difusión (encola un mensaje por destinatario que el
 * relay R28 entrega) y muestra el historial. Opera SIEMPRE sobre la BD del estudio.
 */
class DifusionesTenantController
{
    private const LIMITE = 100;

    public function segmentos(ResolverSegmentoTenant $resolver): JsonResponse
    {
        $conteos = $resolver->conteos();

        return response()->json([
            'data' => array_map(fn (SegmentoComunicacion $s): array => [
                'clave' => $s->value,
                'etiqueta' => $s->etiqueta(),
                'descripcion' => $s->descripcion(),
                'total' => $conteos[$s->value] ?? 0,
            ], SegmentoComunicacion::cases()),
        ]);
    }

    public function index(): JsonResponse
    {
        $difusiones = DifusionTenant::query()->orderByDesc('id')->limit(self::LIMITE)->get();

        return response()->json([
            'data' => $difusiones->map(fn (DifusionTenant $d): array => $this->presentar($d))->all(),
        ]);
    }

    public function difundir(Request $request, DifundirComunicacionTenant $difundir): JsonResponse
    {
        $validado = $request->validate([
            'segmento' => ['required', Rule::enum(SegmentoComunicacion::class)],
            'canal' => ['required', Rule::enum(CanalComunicacion::class)],
            'asunto' => ['required', 'string', 'max:255'],
            'cuerpo' => ['required', 'string', 'max:5000'],
        ]);

        $difusion = $difundir->ejecutar(
            SegmentoComunicacion::from($validado['segmento']),
            CanalComunicacion::from($validado['canal']),
            $validado['asunto'],
            $validado['cuerpo'],
        );

        return response()->json(['data' => $this->presentar($difusion)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(DifusionTenant $difusion): array
    {
        return [
            'id' => $difusion->ulid,
            'segmento' => $difusion->segmento->value,
            'segmento_etiqueta' => $difusion->segmento->etiqueta(),
            'canal' => $difusion->canal->value,
            'asunto' => $difusion->asunto,
            'cuerpo' => $difusion->cuerpo,
            'total' => $difusion->total,
            'enviada_en' => $difusion->enviada_en?->toIso8601String(),
        ];
    }
}
