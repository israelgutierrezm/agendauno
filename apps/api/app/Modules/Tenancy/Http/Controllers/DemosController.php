<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\DemosAgendables;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Mail\CorreoDemoAgendada;
use App\Modules\Tenancy\Models\DemoAgendada;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La demo de 15 minutos agendable desde la landing (ADR 0193): sus horarios libres,
 * agendarla (público, con captcha y el aviso de privacidad aceptado) y cancelarla con
 * el enlace de su correo. El superadmin las ve y marca cómo fueron.
 */
class DemosController
{
    public function __construct(
        private readonly DemosAgendables $demos,
        private readonly VerificarRecaptcha $recaptcha,
        private readonly DocumentosLegales $legales,
        private readonly ClienteWhatsApp $whatsapp,
    ) {}

    public function horarios(): JsonResponse
    {
        return response()->json(['data' => [
            'activas' => $this->demos->activas(),
            'minutos' => $this->demos->minutos(),
            'zona' => $this->demos->zona(),
            // Solo si la plataforma confirma por WhatsApp se ofrece.
            'whatsapp' => $this->whatsapp->activoParaDuenos(),
            'dias' => $this->demos->horarios(),
        ]]);
    }

    public function agendar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'producto' => ['required', Rule::enum(ProductoComercial::class)],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'nombre' => ['required', 'string', 'max:120'],
            'negocio' => ['required', 'string', 'max:120'],
            'giro' => ['nullable', Rule::enum(PerfilNegocio::class)],
            'correo' => ['required', 'email', 'max:190'],
            'telefono' => ['required', 'string', 'max:30'],
            'acepta_whatsapp' => ['nullable', 'boolean'],
            'acepta_aviso' => ['accepted'],
            'aviso_version' => ['nullable', 'integer', 'min:1'],
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
            // Campo trampa: un bot lo llena.
            'sitio_web' => ['prohibited'],
        ]);
        if (! $this->demos->activas()) {
            throw ValidationException::withMessages(['fecha' => ['Por ahora no estamos agendando demos.']]);
        }
        if (! $this->recaptcha->aprobado((string) ($validado['recaptcha_token'] ?? ''), $request->ip())) {
            throw ValidationException::withMessages(['recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.']]);
        }
        $aviso = $this->legales->vigente(DocumentoLegal::AVISO);
        if ($aviso === null && app()->environment('production')) {
            throw ValidationException::withMessages(['acepta_aviso' => ['Las demos abren cuando el aviso de privacidad esté publicado.']]);
        }
        $telefono = TelefonoWhatsApp::normalizar((string) $validado['telefono'], '52');
        if ($telefono === null) {
            throw ValidationException::withMessages(['telefono' => ['Escribe un teléfono de 10 dígitos.']]);
        }

        $demo = $this->demos->agendar([
            'producto' => (string) $validado['producto'],
            'fecha' => (string) $validado['fecha'],
            'hora' => (string) $validado['hora'],
            'nombre' => (string) $validado['nombre'],
            'negocio' => (string) $validado['negocio'],
            'giro' => isset($validado['giro']) ? (string) $validado['giro'] : null,
            'correo' => (string) $validado['correo'],
            'telefono' => $telefono,
            'acepta_whatsapp' => (bool) ($validado['acepta_whatsapp'] ?? false) && $this->whatsapp->activoParaDuenos(),
            'aviso_version' => $aviso?->version,
        ]);
        Log::info('demo.agendada', ['demo' => $demo->ulid, 'producto' => $demo->producto]);
        $this->avisar($demo);

        return response()->json(['data' => [
            'id' => $demo->ulid,
            'inicia_en' => $demo->inicia_en->toIso8601String(),
            'whatsapp' => $demo->acepta_whatsapp,
        ]], 201);
    }

    /** Con el enlace de su correo: la cancela y libera el horario. */
    public function cancelar(Request $request): JsonResponse
    {
        $demo = DemoAgendada::query()->where('ulid', (string) $request->route('demo'))->firstOrFail();
        abort_unless(hash_equals($demo->token, (string) $request->input('token', '')), 404);
        if ($demo->estado === 'agendada') {
            $this->demos->cancelar($demo);
        }

        return response()->json(['data' => ['estado' => $demo->estado]]);
    }

    /** Para el superadmin: las próximas primero y luego las pasadas. */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $pagina = DemoAgendada::query()
            ->orderByRaw('case when inicia_en >= ? then 0 else 1 end', [now()])
            ->orderByRaw('case when inicia_en >= ? then inicia_en end asc', [now()])
            ->orderByDesc('inicia_en')
            // A la misma hora, la agendada antes que la cancelada.
            ->orderByRaw("case when estado = 'agendada' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'page', (int) ($validado['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (DemoAgendada $d): array => $this->presentar($d))->all(),
            'meta' => ['page' => $pagina->currentPage(), 'ultima_pagina' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $validado = $request->validate(['estado' => ['required', Rule::in(['cancelada', 'atendida', 'no_asistio'])]]);
        $demo = DemoAgendada::query()->where('ulid', (string) $request->route('demo'))->firstOrFail();
        if ($validado['estado'] === 'cancelada') {
            $this->demos->cancelar($demo);
        } else {
            $demo->forceFill(['estado' => $validado['estado']])->save();
        }

        return response()->json(['data' => $this->presentar($demo)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(DemoAgendada $d): array
    {
        return [
            'id' => $d->ulid,
            'producto' => $d->producto,
            'inicia_en' => $d->inicia_en->toIso8601String(),
            'nombre' => $d->nombre,
            'negocio' => $d->negocio,
            'giro' => $d->giro,
            'correo' => $d->correo,
            'telefono' => $d->telefono,
            'acepta_whatsapp' => $d->acepta_whatsapp,
            'estado' => $d->estado,
        ];
    }

    /**
     * Confirmación por correo (y por WhatsApp, si la pidió) y aviso a ventas. Si algo
     * falla, la demo queda agendada: se reporta.
     */
    private function avisar(DemoAgendada $demo): void
    {
        $producto = ProductoComercial::from($demo->producto);
        $cuando = $this->demos->cuando($demo);
        $texto = $this->demos->textoCuando($demo);
        $cancelar = $this->demos->enlaceCancelar($demo);
        $minutos = (string) $this->demos->minutos();
        try {
            Mail::to($demo->correo)->send(new CorreoDemoAgendada($producto, $texto, $minutos, $cancelar));
            $ventas = trim((string) config('agendauno.ventas.correo'));
            if ($ventas !== '') {
                Mail::to($ventas)->send(new CorreoDemoAgendada($producto, $texto, $minutos, null, [
                    'Nombre' => $demo->nombre,
                    'Negocio' => $demo->negocio,
                    'Giro' => $demo->giro ?? '—',
                    'Correo' => $demo->correo,
                    'Teléfono' => $demo->telefono,
                    'Confirmar por WhatsApp' => $demo->acepta_whatsapp ? 'Sí' : 'No',
                ]));
            }
        } catch (Throwable $e) {
            report($e);
        }

        $plantilla = PlantillasWhatsApp::demoAgendada();
        if ($demo->acepta_whatsapp && $this->whatsapp->activoParaDuenos()) {
            try {
                $this->whatsapp->enviarPlantilla($demo->telefono, $plantilla['nombre'], PlantillasWhatsApp::parametros($plantilla['texto'], [
                    'nombre' => trim(explode(' ', $demo->nombre)[0]),
                    'marca' => $producto->nombre(),
                    'fecha' => $cuando->locale('es')->translatedFormat('l j \d\e F'),
                    'hora' => $cuando->format('H:i'),
                    'enlace' => $cancelar,
                ]));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
