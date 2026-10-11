<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DifundirComunicacionTenant;
use App\Modules\Tenancy\Application\ResolverSegmentoTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // Push solo aparece si la plataforma tiene FCM configurado.
            'canales' => array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::paraDifusiones()),
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
            'canal' => ['required', Rule::in(array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::paraDifusiones()))],
            'asunto' => ['required', 'string', 'max:255'],
            'cuerpo' => ['required', 'string', 'max:5000'],
        ]);

        $segmento = SegmentoComunicacion::from($validado['segmento']);
        $canal = CanalComunicacion::from($validado['canal']);
        // La misma campaña no se manda dos veces el mismo día (B08).
        $repetida = DifusionTenant::query()
            ->where('huella', DifundirComunicacionTenant::huella($segmento, $canal, $validado['asunto'], $validado['cuerpo']))
            ->where('estado', '!=', DifusionTenant::CANCELADA)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($repetida) {
            throw ValidationException::withMessages(['cuerpo' => ['Ya mandaste esta misma difusión en las últimas 24 horas.']]);
        }

        $difusion = $difundir->ejecutar($segmento, $canal, $validado['asunto'], $validado['cuerpo']);

        return response()->json(['data' => $this->presentar($difusion)], 201);
    }

    /**
     * Pausa una campaña: lo que falta por enviar espera. Los avisos de operación
     * (recordatorios, pagos) siguen saliendo (B08).
     */
    public function pausar(Request $request): JsonResponse
    {
        $difusion = $this->difusion($request);
        abort_unless($difusion->estado === DifusionTenant::ENVIANDO, 422, 'Esta difusión no se está enviando.');
        $difusion->update(['estado' => DifusionTenant::PAUSADA, 'pausada_en' => now()]);

        return response()->json(['data' => $this->presentar($difusion->refresh())]);
    }

    public function reanudar(Request $request): JsonResponse
    {
        $difusion = $this->difusion($request);
        abort_unless($difusion->estado === DifusionTenant::PAUSADA, 422, 'Esta difusión no está en pausa.');
        $difusion->update(['estado' => DifusionTenant::ENVIANDO, 'pausada_en' => null]);

        return response()->json(['data' => $this->presentar($difusion->refresh())]);
    }

    /** Cancela lo que falta por enviar; lo enviado, enviado está. */
    public function cancelar(Request $request): JsonResponse
    {
        $difusion = $this->difusion($request);
        abort_if($difusion->estado === DifusionTenant::CANCELADA, 422, 'Esta difusión ya se canceló.');
        DB::connection('tenant')->transaction(function () use ($difusion): void {
            $difusion->update(['estado' => DifusionTenant::CANCELADA, 'cancelada_en' => now()]);
            MensajeTenant::query()
                ->where('difusion_id', $difusion->getKey())
                ->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
                ->update(['estado' => EstadoMensaje::Descartado->value, 'ultimo_error' => 'Difusión cancelada.']);
        });

        return response()->json(['data' => $this->presentar($difusion->refresh())]);
    }

    private function difusion(Request $request): DifusionTenant
    {
        return DifusionTenant::query()->where('ulid', (string) $request->route('difusion'))->firstOrFail();
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
            // B08: en qué va y cuánto falta.
            'estado' => $difusion->estado ?? DifusionTenant::ENVIANDO,
            'enviados' => $difusion->mensajes()->where('estado', EstadoMensaje::Enviado->value)->count(),
            'pendientes' => $difusion->mensajes()->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])->count(),
            'descartados' => $difusion->mensajes()->where('estado', EstadoMensaje::Descartado->value)->count(),
        ];
    }
}
