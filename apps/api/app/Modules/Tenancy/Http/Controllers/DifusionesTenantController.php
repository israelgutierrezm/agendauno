<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DifundirComunicacionTenant;
use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Application\PlantillasWhatsAppNegocio;
use App\Modules\Tenancy\Application\ResolverSegmentoTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(private readonly PlantillasWhatsAppNegocio $plantillas) {}

    public function segmentos(Request $request, ResolverSegmentoTenant $resolver): JsonResponse
    {
        $conteos = $resolver->conteos();

        return response()->json([
            // Solo los de su modalidad (ADR 0104): los que no cuentan no se ofrecen.
            'data' => array_values(array_map(fn (SegmentoComunicacion $s): array => [
                'clave' => $s->value,
                'etiqueta' => $s->etiqueta(),
                'descripcion' => $s->descripcion(),
                'total' => $conteos[$s->value] ?? 0,
            ], array_filter(SegmentoComunicacion::cases(), static fn (SegmentoComunicacion $s): bool => array_key_exists($s->value, $conteos)))),
            // Push solo aparece si la plataforma tiene FCM configurado; WhatsApp, si el
            // negocio puede usar sus plantillas (ADR 0190).
            'canales' => $this->canales($request),
        ]);
    }

    /**
     * Antes de enviar (ADR 0166): a cuántos llegaría por ese canal y quiénes quedan fuera
     * y por qué (no quieren promociones, sin correo, sin la app).
     */
    public function alcance(Request $request, DifundirComunicacionTenant $difundir): JsonResponse
    {
        $validado = $request->validate([
            'segmento' => ['required', Rule::enum(SegmentoComunicacion::class)],
            'canal' => ['required', Rule::in($this->canales($request))],
            'plantilla_whatsapp' => ['nullable', 'string', 'max:40'],
        ]);
        $canal = CanalComunicacion::from($validado['canal']);
        $plantilla = $canal === CanalComunicacion::WhatsApp && isset($validado['plantilla_whatsapp'])
            ? $this->plantillaAprobada($request, (string) $validado['plantilla_whatsapp'])
            : null;

        return response()->json(['data' => $difundir->alcance(SegmentoComunicacion::from($validado['segmento']), $canal, $plantilla)]);
    }

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $consulta = DifusionTenant::query()->orderByDesc('id');
        // Con `page`, por páginas y con su total; sin ella, las últimas 100.
        if (isset($validado['page'])) {
            $pagina = $consulta->paginate((int) ($validado['per_page'] ?? 20), ['*'], 'page', (int) $validado['page']);

            return response()->json([
                'data' => collect($pagina->items())->map(fn (DifusionTenant $d): array => $this->presentar($d))->values()->all(),
                'meta' => [
                    'total' => $pagina->total(),
                    'page' => $pagina->currentPage(),
                    'per_page' => $pagina->perPage(),
                    'ultima_pagina' => $pagina->lastPage(),
                ],
            ]);
        }
        $difusiones = $consulta->limit(self::LIMITE)->get();

        return response()->json([
            'data' => $difusiones->map(fn (DifusionTenant $d): array => $this->presentar($d))->all(),
        ]);
    }

    public function difundir(Request $request, DifundirComunicacionTenant $difundir): JsonResponse
    {
        $validado = $request->validate([
            'segmento' => ['required', Rule::enum(SegmentoComunicacion::class)],
            'canal' => ['required', Rule::in($this->canales($request))],
            // Por WhatsApp el texto es el de la plantilla aprobada (ADR 0190).
            'plantilla_whatsapp' => ['required_if:canal,whatsapp', 'nullable', 'string', 'max:40'],
            'asunto' => ['exclude_if:canal,whatsapp', 'required', 'string', 'max:255'],
            'cuerpo' => ['exclude_if:canal,whatsapp', 'required', 'string', 'max:5000'],
        ]);

        $segmento = SegmentoComunicacion::from($validado['segmento']);
        $canal = CanalComunicacion::from($validado['canal']);
        $plantilla = $canal === CanalComunicacion::WhatsApp ? $this->plantillaAprobada($request, (string) $validado['plantilla_whatsapp']) : null;
        if ($plantilla instanceof PlantillaWhatsAppNegocio) {
            $validado['asunto'] = $plantilla->titulo;
            $validado['cuerpo'] = $plantilla->cuerpo;
        }
        $huella = DifundirComunicacionTenant::huella($segmento, $canal, $validado['asunto'], $validado['cuerpo']);
        // La misma campaña no se manda dos veces el mismo día (B08). Con el candado, dos
        // envíos a la vez (un doble clic, un reintento) no pasan los dos la revisión.
        $difusion = Cache::lock('difusion:'.$huella, 30)->block(10, function () use ($huella, $difundir, $segmento, $canal, $validado, $plantilla): DifusionTenant {
            $repetida = DifusionTenant::query()
                ->where('huella', $huella)
                ->where('estado', '!=', DifusionTenant::CANCELADA)
                ->where('created_at', '>=', now()->subDay())
                ->exists();
            if ($repetida) {
                throw ValidationException::withMessages(['cuerpo' => ['Ya mandaste esta misma difusión en las últimas 24 horas.']]);
            }

            return $difundir->ejecutar($segmento, $canal, $validado['asunto'], $validado['cuerpo'], $plantilla);
        });

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

    /**
     * Los canales de una campaña: los de siempre y WhatsApp si el negocio puede usar sus
     * plantillas.
     *
     * @return list<string>
     */
    private function canales(Request $request): array
    {
        $canales = array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::paraDifusiones());
        $estudio = $request->attributes->get('estudio');
        if ($estudio instanceof Estudio && $this->plantillas->disponible($estudio)) {
            $canales[] = CanalComunicacion::WhatsApp->value;
        }

        return $canales;
    }

    private function plantillaAprobada(Request $request, string $ulid): PlantillaWhatsAppNegocio
    {
        $estudio = $request->attributes->get('estudio');
        $plantilla = $estudio instanceof Estudio ? $this->plantillas->aprobada($estudio, $ulid) : null;
        if (! $plantilla instanceof PlantillaWhatsAppNegocio) {
            throw ValidationException::withMessages(['plantilla_whatsapp' => ['Elige una plantilla de WhatsApp aprobada.']]);
        }

        return $plantilla;
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
            // Lo que aún se intentará; lo que agotó sus intentos ya no está «enviándose».
            'pendientes' => $difusion->mensajes()->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
                ->where('intentos', '<', EnviarMensajesTenant::MAX_INTENTOS)->count(),
            'fallidos' => $difusion->mensajes()->where('estado', EstadoMensaje::Fallido->value)
                ->where('intentos', '>=', EnviarMensajesTenant::MAX_INTENTOS)->count(),
            'descartados' => $difusion->mensajes()->where('estado', EstadoMensaje::Descartado->value)->count(),
        ];
    }
}
