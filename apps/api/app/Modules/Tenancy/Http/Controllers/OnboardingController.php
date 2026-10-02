<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
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
 * quién atiende y cuándo → publicar»; con clases, «tu negocio → clases → horario →
 * planes → publicar». Cobro en línea, equipo administrativo, productos y políticas se
 * dejan para después (la lista de pendientes del panel). Un paso está hecho cuando
 * existen sus datos en la BD del negocio; solo «publicar» se anota aparte.
 */
class OnboardingController
{
    /** @var list<string> */
    private const PASOS_CITAS = ['negocio', 'servicios', 'equipo', 'publicacion'];

    /** @var list<string> */
    private const PASOS_CLASES = ['negocio', 'clases', 'horario', 'planes', 'publicacion'];

    public function show(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $pasos = $this->pasos($estudio);
        $hechos = $this->hechos($estudio);

        return response()->json(['data' => [
            'modalidad' => $estudio->modalidad()->value,
            'pasos' => $pasos,
            'completados' => $hechos,
            'completo' => $estudio->onboarding_completo || count($hechos) === count($pasos),
            // Con qué suele empezar un negocio de su giro (el dueño lo ajusta).
            'sugerencias' => SugerenciasPerfil::para($estudio->perfil_negocio),
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
        // En citas, el horario es el de atención de los profesionales y los paquetes son
        // opcionales (cada servicio ya tiene precio); en clases, la agenda y la membresía.
        $esCitas = $estudio->modalidad() === ModalidadServicio::Citas;
        $tareas = [
            ['clave' => 'sucursal', 'hecho' => SucursalTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'catalogo', 'hecho' => OfertaTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'horarios', 'hecho' => $this->horariosListos($estudio), 'requerido' => true, 'ruta' => $esCitas ? 'horarios' : 'agenda'],
            ['clave' => 'politica', 'hecho' => PoliticaCancelacionTenant::query()->exists(), 'requerido' => true, 'ruta' => 'onboarding'],
            ['clave' => 'productos', 'hecho' => ProductoTenant::query()->exists(), 'requerido' => ! $esCitas, 'ruta' => 'ventas'],
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
            'paso' => ['required', Rule::in($this->pasos($estudio))],
            'datos' => ['nullable', 'array'],
        ]);
        $paso = (string) $validado['paso'];

        // Un paso con datos solo está hecho si los datos existen (no basta «siguiente»).
        if ($paso !== 'publicacion' && ! in_array($paso, $this->hechos($estudio), true)) {
            throw ValidationException::withMessages(['paso' => [$this->falta($paso, $estudio)]]);
        }

        $anotados = $estudio->onboarding_pasos ?? [];
        $anotados[$paso] = $validado['datos'] ?? true;
        $estudio->onboarding_pasos = $anotados;
        $hechos = $this->hechos($estudio);
        $completo = $estudio->onboarding_completo || count($hechos) === count($this->pasos($estudio));
        $estudio->update(['onboarding_pasos' => $anotados, 'onboarding_completo' => $completo]);

        return response()->json(['data' => [
            'completados' => $hechos,
            'completo' => $completo,
        ]]);
    }

    /**
     * @return list<string>
     */
    private function pasos(Estudio $estudio): array
    {
        return $estudio->modalidad() === ModalidadServicio::Citas ? self::PASOS_CITAS : self::PASOS_CLASES;
    }

    /**
     * Los pasos hechos, según los datos reales del negocio.
     *
     * @return list<string>
     */
    private function hechos(Estudio $estudio): array
    {
        return array_values(array_filter($this->pasos($estudio), fn (string $paso): bool => match ($paso) {
            'negocio' => SucursalTenant::query()->exists(),
            'servicios', 'clases' => OfertaTenant::query()->exists(),
            'equipo', 'horario' => $this->horariosListos($estudio),
            'planes' => ProductoTenant::query()->exists(),
            default => array_key_exists($paso, $estudio->onboarding_pasos ?? []),
        }));
    }

    private function falta(string $paso, Estudio $estudio): string
    {
        return match ($paso) {
            'negocio' => 'Indica el nombre de tu sucursal antes de continuar.',
            'servicios' => 'Agrega al menos un servicio antes de continuar.',
            'clases' => 'Agrega al menos una clase antes de continuar.',
            'equipo' => 'Define quién atiende y su horario antes de continuar.',
            'horario' => 'Programa al menos una clase en tu horario antes de continuar.',
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

    /**
     * ¿Ya hay horarios? En citas: el horario de atención de algún profesional. En
     * clases: una plantilla recurrente o alguna clase programada.
     */
    private function horariosListos(Estudio $estudio): bool
    {
        return $estudio->modalidad() === ModalidadServicio::Citas
            ? HorarioAtencionTenant::query()->exists()
            : PlantillaHorarioTenant::query()->exists() || SesionTenant::query()->exists();
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
