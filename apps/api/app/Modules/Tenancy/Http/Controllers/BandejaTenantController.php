<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Bandeja\AdjuntosBandejaTenant;
use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La bandeja de WhatsApp e Instagram (ADR 0200): las conversaciones del negocio, leerlas,
 * contestarlas (en las 24 horas que deja Meta), archivarlas y vincularlas con la ficha
 * de un cliente. Con `mensajes.atender`.
 */
class BandejaTenantController
{
    private const POR_PAGINA = 30;

    /** Los mensajes que se muestran de una conversación (los más recientes). */
    private const MENSAJES = 200;

    public function __construct(
        private readonly BandejaTenant $bandeja,
        private readonly AdjuntosBandejaTenant $adjuntos,
    ) {}

    public function resumen(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'sin_leer' => ConversacionTenant::query()->where('estado', ConversacionTenant::ABIERTA)->where('no_leidos', '>', 0)->count(),
            'canales' => CanalMensajeria::query()->where('estudio_id', $estudio->getKey())->pluck('canal')->all(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([ConversacionTenant::ABIERTA, ConversacionTenant::ARCHIVADA])],
            'canal' => ['nullable', Rule::in(CanalMensajeria::CANALES)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = trim((string) ($filtros['q'] ?? ''));
        $digitos = (string) preg_replace('/\D/', '', $q);
        $pagina = ConversacionTenant::query()
            ->where('estado', $filtros['estado'] ?? ConversacionTenant::ABIERTA)
            ->when(isset($filtros['canal']), fn ($c) => $c->where('canal', $filtros['canal']))
            ->when($q !== '', fn ($c) => $c->where(function ($w) use ($q, $digitos): void {
                $w->where('contacto_nombre', 'like', '%'.$q.'%')
                    ->when($digitos !== '', fn ($d) => $d->orWhere('contacto_id', 'like', '%'.$digitos.'%'))
                    ->orWhereHas('persona', fn ($p) => $p->buscar($q));
            }))
            ->with('persona')
            ->orderByDesc('ultimo_mensaje_en')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA, ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (ConversacionTenant $c): array => $this->presentar($c))->all(),
            'meta' => ['total' => $pagina->total(), 'page' => $pagina->currentPage(), 'per_page' => $pagina->perPage(), 'ultima_pagina' => $pagina->lastPage()],
        ]);
    }

    /** Una conversación con sus mensajes; abrirla la deja leída. */
    public function show(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        if ($conversacion->no_leidos > 0) {
            $conversacion->forceFill(['no_leidos' => 0])->save();
        }
        $mensajes = MensajeConversacionTenant::query()
            ->where('conversacion_id', $conversacion->getKey())
            ->with('autor')
            ->orderByDesc('enviado_en')
            ->orderByDesc('id')
            ->limit(self::MENSAJES)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['data' => [
            ...$this->presentar($conversacion),
            'mensajes' => $mensajes->map(fn (MensajeConversacionTenant $m): array => $this->presentarMensaje($m))->all(),
        ]]);
    }

    public function enviar(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            // Instagram acepta hasta 1,000 caracteres; WhatsApp, 4,096.
            'texto' => ['required', 'string', 'max:'.($conversacion->canal === CanalMensajeria::INSTAGRAM ? 1000 : 4096)],
        ]);
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        $mensaje = $this->bandeja->responder($this->estudio($request), $conversacion, $usuario, trim((string) $validado['texto']));

        return response()->json(['data' => $this->presentarMensaje($mensaje->load('autor'))], 201);
    }

    /**
     * Las plantillas aprobadas del número de WhatsApp del negocio que se pueden mandar
     * desde aquí (fuera de las 24 horas es lo único que deja Meta).
     */
    public function plantillas(Request $request): JsonResponse
    {
        try {
            $plantillas = $this->bandeja->plantillas($this->estudio($request));
        } catch (RequestException $e) {
            report($e);
            abort(502, 'Meta no respondió con las plantillas de tu WhatsApp: inténtalo en un momento.');
        }

        return response()->json(['data' => $plantillas]);
    }

    /**
     * Contesta con una plantilla aprobada (también fuera de las 24 horas); Meta la
     * cobra a la cuenta de WhatsApp del negocio.
     */
    public function enviarPlantilla(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:512'],
            'idioma' => ['required', 'string', 'max:20'],
            'variables' => ['present', 'array', 'max:20'],
            // Meta no admite saltos de línea, tabuladores ni más de 4 espacios seguidos.
            'variables.*' => ['required', 'string', 'max:1000', 'not_regex:/[\n\t]| {5,}/'],
        ]);
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        /** @var list<string> $valores */
        $valores = array_values(array_map(static fn (mixed $v): string => trim((string) $v), $validado['variables']));

        $mensaje = $this->bandeja->responderConPlantilla($this->estudio($request), $conversacion, $usuario, (string) $validado['nombre'], (string) $validado['idioma'], $valores);

        return response()->json(['data' => $this->presentarMensaje($mensaje->load('autor'))], 201);
    }

    /**
     * Contesta con una imagen o un PDF (por Instagram, solo imágenes), con un pie
     * opcional, en las 24 horas que deja Meta.
     */
    public function enviarArchivo(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            'archivo' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(AdjuntosBandejaTenant::PARA_MANDAR)), 'max:'.intdiv($this->adjuntos->maximoBytes(), 1024)],
            // El pie de foto de WhatsApp llega a 1,024 caracteres.
            'texto' => ['nullable', 'string', 'max:1000'],
        ]);
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        /** @var UploadedFile $archivo */
        $archivo = $validado['archivo'];
        $pie = trim((string) ($validado['texto'] ?? ''));

        $mensaje = $this->bandeja->responderConArchivo($this->estudio($request), $conversacion, $usuario, $archivo, $pie !== '' ? $pie : null);

        return response()->json(['data' => $this->presentarMensaje($mensaje->load('autor'))], 201);
    }

    /**
     * El archivo de un mensaje: una imagen se abre; lo demás se descarga (un HTML o un
     * SVG nunca se abre en el navegador). Si aún no se bajó de Meta, se intenta ahora.
     */
    public function archivo(Request $request): StreamedResponse
    {
        $mensaje = MensajeConversacionTenant::query()->where('ulid', (string) $request->route('mensaje'))->with('conversacion')->firstOrFail();
        abort_if($mensaje->archivo_ruta === null && ! $this->adjuntos->bajar($mensaje), 404, 'Ese archivo ya no está en Meta o pesa más de lo que se guarda: ábrelo en el teléfono.');
        $mime = (string) ($mensaje->adjunto_mime ?? 'application/octet-stream');
        $enLinea = AdjuntosBandejaTenant::enLinea($mime);

        return DiscosDeArchivos::privado()->response((string) $mensaje->archivo_ruta, AdjuntosBandejaTenant::nombreDe($mensaje), [
            'Content-Type' => $enLinea ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], $enLinea ? 'inline' : 'attachment');
    }

    public function actualizar(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request);
        $validado = $request->validate([
            'estado' => ['sometimes', Rule::in([ConversacionTenant::ABIERTA, ConversacionTenant::ARCHIVADA])],
            'persona_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        if (array_key_exists('estado', $validado)) {
            $conversacion->estado = (string) $validado['estado'];
        }
        if (array_key_exists('persona_id', $validado)) {
            $conversacion->persona_id = $validado['persona_id'] === null
                ? null
                : (int) PersonaTenant::query()->where('ulid', (string) $validado['persona_id'])->firstOrFail()->getKey();
        }
        $conversacion->save();

        return response()->json(['data' => $this->presentar($conversacion->load('persona'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ConversacionTenant $c): array
    {
        return [
            'id' => $c->ulid,
            'canal' => $c->canal,
            'contacto' => $c->contacto_nombre,
            // El número (WhatsApp) sirve para reconocerlo; el id de Instagram no dice nada.
            'telefono' => $c->canal === CanalMensajeria::WHATSAPP ? '+'.$c->contacto_id : null,
            'persona' => $c->persona === null ? null : ['id' => $c->persona->ulid, 'nombre' => $c->persona->nombreCompleto()],
            'estado' => $c->estado,
            'no_leidos' => $c->no_leidos,
            'ultimo_texto' => $c->ultimo_texto,
            'ultimo_mensaje_en' => $c->ultimo_mensaje_en?->toIso8601String(),
            'puede_contestar' => $c->puedeContestar(),
            'contestar_hasta' => $c->ultimo_entrante_en?->copy()->addHours(ConversacionTenant::HORAS_PARA_CONTESTAR)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarMensaje(MensajeConversacionTenant $m): array
    {
        return [
            'id' => $m->ulid,
            'direccion' => $m->direccion,
            'tipo' => $m->tipo,
            'texto' => $m->texto,
            'estado' => $m->estado,
            'enviado_en' => $m->enviado_en->toIso8601String(),
            'enviado_por' => $m->autor?->nombreCorto(),
            // Su archivo (se pide aparte con la sesión): si ya está guardado y si se abre
            // en el navegador (imágenes) o se descarga.
            'archivo' => $m->adjunto_ref === null && $m->archivo_ruta === null ? null : [
                'nombre' => AdjuntosBandejaTenant::nombreDe($m),
                'mime' => $m->adjunto_mime,
                'bytes' => $m->archivo_bytes,
                'listo' => $m->archivo_ruta !== null,
                'en_linea' => AdjuntosBandejaTenant::enLinea($m->adjunto_mime),
            ],
        ];
    }

    private function conversacion(Request $request): ConversacionTenant
    {
        return ConversacionTenant::query()->where('ulid', (string) $request->route('conversacion'))->with('persona')->firstOrFail();
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
