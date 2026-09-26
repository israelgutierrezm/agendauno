<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo del estudio (Programa → Actividad → Nivel/Oferta), tenant-local. Primer
 * módulo operativo en el data plane del tenant: opera SIEMPRE sobre la BD del
 * estudio resuelto, sin `tenant_id`, gateado por permisos tenant-local.
 */
class CatalogoTenantController
{
    public function programas(): JsonResponse
    {
        $programas = ProgramaTenant::query()->with('actividades.ofertas')->orderBy('id')->get();

        return response()->json([
            'data' => $programas->map(fn (ProgramaTenant $programa): array => [
                'id' => $programa->ulid,
                'nombre' => $programa->nombre,
                'actividades' => $programa->actividades->map(fn (ActividadTenant $actividad): array => [
                    'id' => $actividad->ulid,
                    'nombre' => $actividad->nombre,
                    'ofertas' => $actividad->ofertas->map(fn (OfertaTenant $oferta): array => $this->presentarOferta($oferta))->all(),
                ])->all(),
            ])->all(),
        ]);
    }

    public function crearPrograma(Request $request): JsonResponse
    {
        $validado = $request->validate(['nombre' => ['required', 'string', 'max:255']]);

        $programa = ProgramaTenant::query()->create([
            'nombre' => $validado['nombre'],
            'slug' => $this->slug($validado['nombre']),
        ]);

        return response()->json(['data' => ['id' => $programa->ulid, 'nombre' => $programa->nombre]], 201);
    }

    public function crearActividad(Request $request): JsonResponse
    {
        $programa = ProgramaTenant::query()->where('ulid', (string) $request->route('programa'))->firstOrFail();
        $validado = $request->validate(['nombre' => ['required', 'string', 'max:255']]);

        $actividad = $programa->actividades()->create([
            'nombre' => $validado['nombre'],
            'slug' => $this->slug($validado['nombre']),
        ]);

        return response()->json(['data' => ['id' => $actividad->ulid, 'nombre' => $actividad->nombre]], 201);
    }

    public function crearNivel(Request $request): JsonResponse
    {
        $actividad = ActividadTenant::query()->where('ulid', (string) $request->route('actividad'))->firstOrFail();
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        $nivel = $actividad->niveles()->create([
            'nombre' => $validado['nombre'],
            'orden' => (int) ($validado['orden'] ?? 0),
        ]);

        return response()->json(['data' => ['id' => $nivel->ulid, 'nombre' => $nivel->nombre]], 201);
    }

    public function crearOferta(Request $request): JsonResponse
    {
        $actividad = ActividadTenant::query()->where('ulid', (string) $request->route('actividad'))->firstOrFail();
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'modalidad' => ['required', Rule::enum(ModalidadOfertaTenant::class)],
            'capacidad' => ['nullable', 'integer', 'min:1'],
            'lugares' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Política de reserva (citas): entitlement (default) o pago-para-reservar.
            'politica_reserva' => ['nullable', Rule::enum(PoliticaReservaTenant::class)],
            'precio_clase_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // Duración del servicio como cita (minutos); solo la usan las ofertas de cita.
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
            // Preparación antes y limpieza después: ocupan la agenda, no se le cobran
            // ni se le comunican al cliente (2.3).
            'preparacion_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            'limpieza_min' => ['nullable', 'integer', 'min:0', 'max:240'],
        ]);

        // Defaults por modalidad: en un negocio de citas el servicio se agenda y se paga
        // (pago-para-reservar, 30 min); en uno de clases se reserva con la membresía.
        $estudio = $request->attributes->get('estudio');
        $esCitas = $estudio instanceof Estudio && $estudio->modalidad() === ModalidadServicio::Citas;

        $oferta = $actividad->ofertas()->create([
            'nombre' => $validado['nombre'],
            'modalidad' => $validado['modalidad'],
            'capacidad' => $validado['capacidad'] ?? null,
            'lugares' => (int) ($validado['lugares'] ?? 0),
            'politica_reserva' => $validado['politica_reserva']
                ?? ($esCitas ? PoliticaReservaTenant::Pago->value : PoliticaReservaTenant::Entitlement->value),
            'precio_clase_minor' => isset($validado['precio_clase_minor']) ? (int) $validado['precio_clase_minor'] : null,
            'duracion_minutos' => isset($validado['duracion_minutos'])
                ? (int) $validado['duracion_minutos']
                : ($esCitas ? 30 : null),
            'preparacion_min' => (int) ($validado['preparacion_min'] ?? 0),
            'limpieza_min' => (int) ($validado['limpieza_min'] ?? 0),
        ]);

        return response()->json(['data' => $this->presentarOferta($oferta)], 201);
    }

    /**
     * Actualiza el mapa de lugares (y datos básicos) de una oferta (R4).
     */
    public function actualizarOferta(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        $validado = $request->validate([
            'lugares' => ['required', 'integer', 'min:0', 'max:1000'],
            'precio_clase_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'politica_reserva' => ['nullable', Rule::enum(PoliticaReservaTenant::class)],
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'preparacion_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            'limpieza_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            // Espacios o equipos que puede usar (2.4); [] = no requiere.
            'recursos' => ['sometimes', 'array'],
            'recursos.*' => ['string'],
        ]);

        $cambios = ['lugares' => (int) $validado['lugares']];
        if (array_key_exists('recursos', $validado)) {
            $ids = RecursoTenant::query()->whereIn('ulid', $validado['recursos'])->pluck('id')->all();
            if (count($ids) !== count(array_unique($validado['recursos']))) {
                throw ValidationException::withMessages(['recursos' => ['Algún espacio no existe.']]);
            }
            $oferta->recursos()->sync($ids);
        }
        // Los márgenes solo se tocan si vienen; aplican a lo que se agende desde ahora.
        foreach (['preparacion_min', 'limpieza_min'] as $campo) {
            if ($request->has($campo)) {
                $cambios[$campo] = (int) ($validado[$campo] ?? 0);
            }
        }
        // El precio por clase (R30/citas) solo se toca si viene en la petición (null lo limpia).
        if ($request->has('precio_clase_minor')) {
            $cambios['precio_clase_minor'] = $validado['precio_clase_minor'] !== null ? (int) $validado['precio_clase_minor'] : null;
        }
        if (isset($validado['politica_reserva'])) {
            $cambios['politica_reserva'] = $validado['politica_reserva'];
        }
        // La duración de la cita solo se toca si viene (null la limpia).
        if ($request->has('duracion_minutos')) {
            $cambios['duracion_minutos'] = $validado['duracion_minutos'] !== null ? (int) $validado['duracion_minutos'] : null;
        }
        $oferta->update($cambios);

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    public function ofertas(): JsonResponse
    {
        $ofertas = OfertaTenant::query()->with(['actividad', 'recursos'])->orderBy('nombre')->get();

        return response()->json([
            'data' => $ofertas->map(fn (OfertaTenant $oferta): array => array_merge(
                $this->presentarOferta($oferta),
                ['actividad' => $oferta->actividad?->nombre, 'actividad_id' => $oferta->actividad?->ulid],
            ))->all(),
        ]);
    }

    private function slug(string $nombre): string
    {
        return Str::slug($nombre).'-'.Str::lower(Str::random(5));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarOferta(OfertaTenant $oferta): array
    {
        return [
            'id' => $oferta->ulid,
            'nombre' => $oferta->nombre,
            'modalidad' => $oferta->modalidad->value,
            'capacidad' => $oferta->capacidad,
            'lugares' => $oferta->lugares,
            'precio_clase_minor' => $oferta->precio_clase_minor,
            'politica_reserva' => $oferta->politica_reserva->value,
            'duracion_minutos' => $oferta->duracion_minutos,
            'preparacion_min' => (int) $oferta->preparacion_min,
            'limpieza_min' => (int) $oferta->limpieza_min,
            'recursos' => $oferta->recursos->pluck('ulid')->values()->all(),
        ];
    }
}
