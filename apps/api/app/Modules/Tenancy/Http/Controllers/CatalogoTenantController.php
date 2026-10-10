<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AltaRapidaCatalogoTenant;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    /** Descripción de un servicio o clase para la página pública. */
    private const MAX_DESCRIPCION = 600;

    /** Servicios que puede incluir un paquete. */
    private const MAX_INCLUIDOS = 20;

    /**
     * Límite técnico del precio en unidades menores (cabe en BIGINT y en un Number de
     * JS): no es un tope de negocio, para que monedas como COP o ARS den de alta
     * servicios de millones.
     */
    private const MAX_PRECIO_MINOR = 999_999_999_999;

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

    /**
     * Servicios o clases en una línea (nombre, duración y precio o cupo), en Catálogo
     * y en la configuración inicial (ADR 0088): la estructura del catálogo se arma por
     * dentro ({@see AltaRapidaCatalogoTenant}). Citas o clases según el negocio.
     */
    public function altaRapida(Request $request, AltaRapidaCatalogoTenant $alta): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $esCitas = $estudio->modalidad() === ModalidadServicio::Citas;
        $validado = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.nombre' => ['required', 'string', 'max:120'],
            'items.*.duracion_minutos' => ['required', 'integer', 'min:5', 'max:600'],
            'items.*.precio_minor' => [$esCitas ? 'required' : 'prohibited', 'integer', 'min:0', 'max:'.self::MAX_PRECIO_MINOR],
            'items.*.capacidad' => [$esCitas ? 'prohibited' : 'required', 'integer', 'min:1', 'max:500'],
        ]);
        $items = array_values($validado['items']);
        $ofertas = $esCitas ? $alta->servicios($items) : $alta->clases($items);

        return response()->json(['data' => array_map(static fn (OfertaTenant $o): array => [
            'id' => $o->ulid,
            'nombre' => $o->nombre,
            'duracion_minutos' => $o->duracion_minutos,
            'precio_minor' => $o->precio_clase_minor,
            'capacidad' => $o->capacidad,
        ], $ofertas)], 201);
    }

    public function crearOferta(Request $request): JsonResponse
    {
        $actividad = ActividadTenant::query()->where('ulid', (string) $request->route('actividad'))->firstOrFail();
        $estudio = $request->attributes->get('estudio');
        $esCitas = $estudio instanceof Estudio && $estudio->modalidad() === ModalidadServicio::Citas;
        // La forma la da la modalidad del negocio (ADR 0104): en uno de citas los
        // servicios son individuales; en uno de clases, grupales.
        $forma = $esCitas ? ModalidadOfertaTenant::Individual : ModalidadOfertaTenant::Grupal;
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            // Lo que ve quien la elige en línea (página pública y agendar).
            'descripcion' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPCION],
            'modalidad' => ['required', Rule::enum(ModalidadOfertaTenant::class), Rule::in([$forma->value])],
            'capacidad' => ['nullable', 'integer', 'min:1'],
            'lugares' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Política de reserva (citas): entitlement (default) o pago-para-reservar.
            'politica_reserva' => ['nullable', Rule::enum(PoliticaReservaTenant::class)],
            'precio_clase_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRECIO_MINOR],
            // Duración del servicio como cita (minutos); solo la usan las ofertas de cita.
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
            // Preparación antes y limpieza después: ocupan la agenda, no se le cobran
            // ni se le comunican al cliente (2.3).
            'preparacion_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            'limpieza_min' => ['nullable', 'integer', 'min:0', 'max:240'],
        ], [
            'modalidad.in' => $esCitas
                ? 'En un negocio de citas cada servicio es individual.'
                : 'En un negocio de clases cada clase es grupal.',
        ]);

        // Defaults por modalidad: en un negocio de citas el servicio se agenda y se paga
        // (pago-para-reservar, 30 min); en uno de clases se reserva con la membresía.

        $oferta = $actividad->ofertas()->create([
            'nombre' => $validado['nombre'],
            'descripcion' => $this->descripcion($validado['descripcion'] ?? null),
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
            'descripcion' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPCION],
            'precio_clase_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRECIO_MINOR],
            'politica_reserva' => ['nullable', Rule::enum(PoliticaReservaTenant::class)],
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'preparacion_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            'limpieza_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            // Espacios o equipos que puede usar (2.4); [] = no requiere.
            'recursos' => ['sometimes', 'array'],
            'recursos.*' => ['string'],
            // Servicios que incluye (paquete, ADR 0063), en orden; [] = ninguno.
            'incluye' => ['sometimes', 'array', 'max:'.self::MAX_INCLUIDOS],
            'incluye.*' => ['string', 'distinct'],
        ]);

        $cambios = ['lugares' => (int) $validado['lugares']];
        if (array_key_exists('recursos', $validado)) {
            $ids = RecursoTenant::query()->whereIn('ulid', $validado['recursos'])->pluck('id')->all();
            if (count($ids) !== count(array_unique($validado['recursos']))) {
                throw ValidationException::withMessages(['recursos' => ['Algún espacio no existe.']]);
            }
            $oferta->recursos()->sync($ids);
        }
        if (array_key_exists('incluye', $validado)) {
            $this->incluir($oferta, array_values($validado['incluye']));
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
        // La descripción solo se toca si viene (vacía la quita).
        if ($request->has('descripcion')) {
            $cambios['descripcion'] = $this->descripcion($validado['descripcion'] ?? null);
        }
        // La duración de la cita solo se toca si viene (null la limpia).
        if ($request->has('duracion_minutos')) {
            $cambios['duracion_minutos'] = $validado['duracion_minutos'] !== null ? (int) $validado['duracion_minutos'] : null;
        }
        $oferta->update($cambios);

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    /**
     * Una foto del servicio (ADR 0066). SVG excluido a propósito (riesgo de XSS al
     * servirse en el navegador); carpeta por negocio y nombre no enumerable; la nueva
     * reemplaza a la anterior.
     */
    public function subirFoto(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $archivo = $request->file('foto');
        abort_unless($archivo instanceof UploadedFile, 422);

        $ruta = $archivo->storeAs(
            'servicios/'.$estudio->getKey(),
            Str::lower(Str::random(40)).'.'.$archivo->extension(),
            'public',
        );
        $this->borrarFoto($oferta);
        $oferta->update(['foto_ruta' => (string) $ruta]);

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    public function eliminarFoto(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        $this->borrarFoto($oferta);
        $oferta->update(['foto_ruta' => null]);

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    private function borrarFoto(OfertaTenant $oferta): void
    {
        if ($oferta->foto_ruta !== null && $oferta->foto_ruta !== '') {
            Storage::disk('public')->delete($oferta->foto_ruta);
        }
    }

    public function ofertas(): JsonResponse
    {
        $ofertas = OfertaTenant::query()->with(['actividad', 'recursos', 'incluidas'])->orderBy('nombre')->get();

        return response()->json([
            'data' => $ofertas->map(fn (OfertaTenant $oferta): array => array_merge(
                $this->presentarOferta($oferta),
                ['actividad' => $oferta->actividad?->nombre, 'actividad_id' => $oferta->actividad?->ulid],
            ))->all(),
        ]);
    }

    /**
     * Guarda qué servicios incluye, en el orden recibido. Sin anidar: un paquete no
     * incluye paquetes ni se incluye en otro. Bajo candado de los servicios
     * involucrados (en orden de id), para que dos cambios a la vez no armen uno
     * dentro de otro.
     *
     * @param  list<string>  $ulids
     */
    private function incluir(OfertaTenant $oferta, array $ulids): void
    {
        DB::connection('tenant')->transaction(function () use ($oferta, $ulids): void {
            $incluidas = OfertaTenant::query()->whereIn('ulid', $ulids)->orderBy('id')->lockForUpdate()->get()->keyBy('ulid');
            OfertaTenant::query()->whereKey($oferta->getKey())->lockForUpdate()->first();

            if ($incluidas->count() !== count($ulids)) {
                throw ValidationException::withMessages(['incluye' => ['Algún servicio no existe.']]);
            }
            if ($incluidas->has((string) $oferta->ulid)) {
                throw ValidationException::withMessages(['incluye' => ['Un servicio no puede incluirse a sí mismo.']]);
            }
            if ($ulids !== [] && $oferta->incluidaEn()->exists()) {
                throw ValidationException::withMessages(['incluye' => ['Este servicio ya está incluido en un paquete; no puede incluir otros.']]);
            }
            if ($incluidas->contains(static fn (OfertaTenant $o): bool => $o->incluidas()->exists())) {
                throw ValidationException::withMessages(['incluye' => ['Un paquete no puede incluir otro paquete.']]);
            }

            $oferta->incluidas()->sync(collect($ulids)->mapWithKeys(
                static fn (string $ulid, int $posicion): array => [(int) $incluidas[$ulid]->getKey() => ['posicion' => $posicion]],
            )->all());
        });
    }

    private function descripcion(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
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
            'descripcion' => $oferta->descripcion,
            'modalidad' => $oferta->modalidad->value,
            'capacidad' => $oferta->capacidad,
            'lugares' => $oferta->lugares,
            'precio_clase_minor' => $oferta->precio_clase_minor,
            'politica_reserva' => $oferta->politica_reserva->value,
            'duracion_minutos' => $oferta->duracion_minutos,
            'preparacion_min' => (int) $oferta->preparacion_min,
            'limpieza_min' => (int) $oferta->limpieza_min,
            'recursos' => $oferta->recursos->pluck('ulid')->values()->all(),
            // Servicios que incluye (paquete), en orden.
            'incluye' => $oferta->incluidas->pluck('ulid')->values()->all(),
            'foto_url' => $oferta->fotoUrl(),
        ];
    }
}
