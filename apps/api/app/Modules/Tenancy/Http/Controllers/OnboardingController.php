<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wizard de onboarding del estudio (guardar y continuar) y publicación en el
 * directorio. El progreso vive en el control plane; los datos de cada paso se
 * materializan en la BD del tenant a medida que esos módulos migran. Opera sobre
 * el estudio ya resuelto (autenticado tenant-local).
 */
class OnboardingController
{
    /**
     * @var list<string>
     */
    private const PASOS = ['marca', 'sucursal', 'horarios', 'actividades', 'productos', 'politicas', 'pasarela', 'personal', 'publicacion'];

    public function show(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'pasos' => self::PASOS,
            'completados' => array_keys($estudio->onboarding_pasos ?? []),
            'completo' => $estudio->onboarding_completo,
            // Estado REAL de la config que ciertos pasos exigen, para que el asistente
            // guíe (no deje callejones) en vez de fallar con "siguiente".
            'config' => [
                'horarios' => PlantillaHorarioTenant::query()->exists() || SesionTenant::query()->exists(),
                'politicas' => PoliticaCancelacionTenant::query()->exists(),
            ],
        ]]);
    }

    /**
     * Quickstart (R36): checklist DERIVADO del estado real de configuración (no del
     * JSON de pasos), para que el dueño active su estudio saltando a lo que falta. Cada
     * tarea trae si está hecha, si es requerida para operar, y la ruta para completarla.
     */
    public function quickstart(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        // [clave, hecho, requerido, ruta] — el estado sale de datos reales del tenant.
        $tareas = [
            ['clave' => 'sucursal', 'hecho' => SucursalTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'catalogo', 'hecho' => OfertaTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'horarios', 'hecho' => PlantillaHorarioTenant::query()->exists() || SesionTenant::query()->exists(), 'requerido' => true, 'ruta' => 'agenda'],
            ['clave' => 'politica', 'hecho' => PoliticaCancelacionTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'productos', 'hecho' => ProductoTenant::query()->exists(), 'requerido' => true, 'ruta' => 'ventas'],
            ['clave' => 'miembros', 'hecho' => PersonaTenant::query()->where('tipo', TipoPersonaTenant::Miembro->value)->exists(), 'requerido' => false, 'ruta' => 'miembros'],
            ['clave' => 'publicado', 'hecho' => (bool) $estudio->publicado, 'requerido' => false, 'ruta' => 'configuracion'],
        ];

        $requeridas = array_filter($tareas, fn (array $t): bool => $t['requerido']);
        $hechasReq = array_filter($requeridas, fn (array $t): bool => $t['hecho']);

        return response()->json(['data' => [
            'tareas' => $tareas,
            'progreso' => ['hechas' => count($hechasReq), 'total' => count($requeridas)],
            'listo' => count($hechasReq) === count($requeridas),
        ]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'paso' => ['required', Rule::in(self::PASOS)],
            'datos' => ['nullable', 'array'],
        ]);

        // No marcar como listos los pasos que requieren configuración real solo con
        // "siguiente": deben existir los datos del módulo en la BD del tenant.
        $this->exigirConfiguracion((string) $validado['paso']);

        $completados = $estudio->onboarding_pasos ?? [];
        $completados[(string) $validado['paso']] = $validado['datos'] ?? true;

        $completo = count(array_intersect(self::PASOS, array_keys($completados))) === count(self::PASOS);
        $estudio->update(['onboarding_pasos' => $completados, 'onboarding_completo' => $completo]);

        return response()->json(['data' => [
            'completados' => array_keys($completados),
            'completo' => $completo,
        ]]);
    }

    /**
     * Los pasos "horarios" y "politicas" no se pueden dar por terminados sin haberlos
     * configurado de verdad (no basta un "siguiente").
     */
    private function exigirConfiguracion(string $paso): void
    {
        if ($paso === 'horarios'
            && ! PlantillaHorarioTenant::query()->exists()
            && ! SesionTenant::query()->exists()) {
            throw ValidationException::withMessages([
                'paso' => ['Programa al menos un horario recurrente o una clase antes de continuar.'],
            ]);
        }

        if ($paso === 'politicas' && ! PoliticaCancelacionTenant::query()->exists()) {
            throw ValidationException::withMessages([
                'paso' => ['Define tu política de cancelación antes de continuar.'],
            ]);
        }
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
        ]]);
    }

    /**
     * Cambia el perfil de negocio del estudio (R35): solo ajusta
     * defaults/terminologia/feature-flags, sin forks.
     */
    public function perfil(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'perfil_negocio' => ['required', Rule::enum(PerfilNegocio::class)],
        ]);

        $estudio->update(['perfil_negocio' => $validado['perfil_negocio']]);

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
