<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PuestaEnMarchaTenant;
use App\Modules\Tenancy\Exceptions\ModalidadBloqueada;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\SugerenciasPerfil;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuración inicial del negocio (ADR 0088) y publicación en el directorio. Los
 * pasos dependen de cómo trabaja el negocio: con citas, «tu negocio → servicios →
 * quién atiende y cuándo → reglas → publicar»; con clases, «tu negocio → clases →
 * horario → planes → reglas → publicar». El asistente y «Pon tu negocio en marcha»
 * del panel usan el mismo criterio ({@see PuestaEnMarchaTenant}, ADR 0090): mismos
 * pasos, mismo «hecho» y los mismos estados (configurado, publicado, recibe reservas).
 */
class OnboardingController
{
    public function __construct(
        private readonly PuestaEnMarchaTenant $marcha,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $pasos = $this->marcha->pasos($estudio);
        $hechos = $this->marcha->hechos($estudio);

        return response()->json(['data' => [
            'modalidad' => $estudio->modalidad()->value,
            // Los giros que puede elegir: solo los de su modalidad (ADR 0104).
            'perfiles' => array_map(static fn (PerfilNegocio $p): string => $p->value, $estudio->modalidad()->perfiles()),
            'pasos' => $pasos,
            'completados' => $hechos,
            'completo' => $estudio->onboarding_completo || count($hechos) === count($pasos),
            'estado' => $this->marcha->estado($estudio),
            'publicacion' => ['publicado' => (bool) $estudio->publicado, 'privado' => (bool) $estudio->privado],
            // Con qué suele empezar un negocio de su giro (el dueño lo ajusta).
            'sugerencias' => SugerenciasPerfil::para($estudio->perfil_negocio),
        ]]);
    }

    /**
     * «Pon tu negocio en marcha» (R36) en el Inicio: los MISMOS pasos del asistente,
     * cada uno lleva a su paso, y lo opcional (cobro en línea, primer cliente) aparte.
     */
    public function quickstart(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $hechos = $this->marcha->hechos($estudio);
        $tareas = array_map(static fn (string $paso): array => [
            'clave' => $paso,
            'hecho' => in_array($paso, $hechos, true),
            'requerido' => true,
            'ruta' => 'onboarding',
        ], $this->marcha->pasos($estudio));
        $tareas[] = ['clave' => 'cobro', 'hecho' => ConfiguracionPasarelaTenant::query()->where('activa', true)->exists(), 'requerido' => false, 'ruta' => 'pasarelas'];
        $tareas[] = ['clave' => 'miembros', 'hecho' => PersonaTenant::query()->where('tipo', TipoPersonaTenant::Miembro->value)->exists(), 'requerido' => false, 'ruta' => 'miembros'];

        $requeridas = array_filter($tareas, static fn (array $t): bool => $t['requerido']);
        $hechasReq = array_filter($requeridas, static fn (array $t): bool => $t['hecho']);
        $estado = $this->marcha->estado($estudio);

        return response()->json(['data' => [
            'tareas' => $tareas,
            'progreso' => ['hechas' => count($hechasReq), 'total' => count($requeridas)],
            'listo' => $estado['listo'],
            'estado' => $estado,
        ]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'paso' => ['required', Rule::in($this->marcha->pasos($estudio))],
            'datos' => ['nullable', 'array'],
        ]);
        $paso = (string) $validado['paso'];

        // Las reglas se aceptan como están (o como se ajustaron): deben existir.
        if ($paso === 'reglas' && ! PoliticaCancelacionTenant::query()->whereNull('actividad_id')->exists()) {
            throw ValidationException::withMessages(['paso' => [$this->falta($paso)]]);
        }
        // Un paso con datos solo está hecho si los datos existen (no basta «siguiente»).
        if (! in_array($paso, ['reglas', 'publicacion'], true) && ! $this->marcha->hecho($estudio, $paso)) {
            throw ValidationException::withMessages(['paso' => [$this->falta($paso)]]);
        }

        $anotados = $estudio->onboarding_pasos ?? [];
        $anotados[$paso] = $validado['datos'] ?? true;
        $estudio->onboarding_pasos = $anotados;
        $hechos = $this->marcha->hechos($estudio);
        $completo = $estudio->onboarding_completo || count($hechos) === count($this->marcha->pasos($estudio));
        $estudio->update(['onboarding_pasos' => $anotados, 'onboarding_completo' => $completo]);

        return response()->json(['data' => [
            'completados' => $hechos,
            'completo' => $completo,
            'estado' => $this->marcha->estado($estudio),
        ]]);
    }

    private function falta(string $paso): string
    {
        return match ($paso) {
            'negocio' => 'Indica el nombre de tu sucursal antes de continuar.',
            'servicios' => 'Agrega al menos un servicio antes de continuar.',
            'clases' => 'Agrega al menos una clase antes de continuar.',
            'equipo' => 'Define quién atiende y su horario antes de continuar.',
            'horario' => 'Programa al menos una clase en tu horario antes de continuar.',
            'reglas' => 'Guarda tus reglas de cancelación antes de continuar.',
            default => 'Agrega al menos un plan antes de continuar.',
        };
    }

    public function publicacion(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'publicado' => ['required', 'boolean'],
            'privado' => ['nullable', 'boolean'],
        ]);

        $estudio->update([
            'publicado' => (bool) $validado['publicado'],
            'privado' => (bool) ($validado['privado'] ?? false),
        ]);

        return response()->json(['data' => [
            'publicado' => $estudio->publicado,
            'privado' => $estudio->privado,
            'en_directorio' => $estudio->enDirectorio(),
            'estado' => $this->marcha->estado($estudio),
        ]]);
    }

    /**
     * Cambia el giro del negocio (R35) dentro de su modalidad: ajusta terminología,
     * flags y sugerencias, nunca la modalidad ni el cobro. Un giro de la otra
     * modalidad se rechaza: ese cambio lo hace AgendaUno (ADR 0104).
     */
    public function perfil(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'perfil_negocio' => ['required', Rule::enum(PerfilNegocio::class)],
        ]);
        $perfil = PerfilNegocio::from((string) $validado['perfil_negocio']);
        if (ModalidadServicio::paraPerfil($perfil) !== $estudio->modalidad()) {
            throw new ModalidadBloqueada($estudio->modalidad());
        }

        $estudio->update(['perfil_negocio' => $perfil->value]);

        return response()->json(['data' => [
            'perfil' => $estudio->perfil_negocio->value,
            'perfil_config' => $estudio->perfilConfig(),
        ]]);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
