<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AltaRapidaCatalogoTenant;
use App\Modules\Tenancy\Application\ArchivoDeServiciosTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\Usuario;
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

    /** Cupo base de una clase (el de cada fecha se ajusta en la agenda). */
    private const MAX_CUPO = 1000;

    public function __construct(
        private readonly ArchivoDeServiciosTenant $archivo,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function programas(): JsonResponse
    {
        $programas = ProgramaTenant::query()
            ->with(['actividades.ofertas' => fn ($q) => $q->vigentes()])
            ->orderBy('id')
            ->get();

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
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_CUPO],
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
            // Anticipo para apartar en línea (citas, ADR 0161): un monto o un porcentaje.
            'anticipo_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PRECIO_MINOR, 'prohibits:anticipo_pct'],
            'anticipo_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
        ], [
            'modalidad.in' => $esCitas
                ? 'En un negocio de citas cada servicio es individual.'
                : 'En un negocio de clases cada clase es grupal.',
        ]);

        // Defaults por modalidad: en un negocio de citas el servicio se agenda y se paga
        // (pago-para-reservar, 30 min); en uno de clases se reserva con la membresía.
        // Quien elige «se paga al reservar» le pone su precio.
        if (($validado['politica_reserva'] ?? null) === PoliticaReservaTenant::Pago->value) {
            self::exigirPrecioParaPagar($validado['precio_clase_minor'] ?? null);
        }
        $anticipo = self::anticipo($validado, isset($validado['precio_clase_minor']) ? (int) $validado['precio_clase_minor'] : null);

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
            ...($anticipo ?? []),
        ]);

        return response()->json(['data' => $this->presentarOferta($oferta)], 201);
    }

    /**
     * Corrige un servicio o clase: su nombre, su cupo base (lo que se agende desde
     * ahora; cada fecha ya agendada conserva el suyo), el mapa de lugares (R4) y lo
     * demás. Archivado no se edita: primero se vuelve a ofrecer (ADR 0154).
     */
    public function actualizarOferta(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        abort_if($oferta->archivada(), 409, 'Vuelve a ofrecerlo para editarlo.');
        $validado = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            // Solo las clases (grupales) tienen cupo; una cita es de una persona.
            'capacidad' => $oferta->modalidad === ModalidadOfertaTenant::Grupal
                ? ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CUPO]
                : ['prohibited'],
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
            // Para quien llega por primera vez (C03): lo que el negocio valida.
            'para_principiantes' => ['sometimes', 'boolean'],
            'requisitos' => ['sometimes', 'nullable', 'string', 'max:300'],
            'que_llevar' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Cada cuántos días suele regresar un cliente (retención, ADR 0159).
            'regreso_dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
            // Anticipo para apartar en línea (citas, ADR 0161): un monto o un porcentaje.
            'anticipo_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PRECIO_MINOR, 'prohibits:anticipo_pct'],
            'anticipo_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
        ]);

        // Primero se valida todo; después se guarda todo junto (ver abajo).
        $cambios = ['lugares' => (int) $validado['lugares']];
        if (array_key_exists('nombre', $validado)) {
            $cambios['nombre'] = trim((string) $validado['nombre']);
        }
        if (array_key_exists('capacidad', $validado)) {
            $cambios['capacidad'] = $validado['capacidad'] !== null ? (int) $validado['capacidad'] : null;
        }
        if ($request->has('para_principiantes')) {
            $cambios['para_principiantes'] = (bool) $validado['para_principiantes'];
        }
        if (array_key_exists('regreso_dias', $validado)) {
            $cambios['regreso_dias'] = $validado['regreso_dias'] !== null ? (int) $validado['regreso_dias'] : null;
        }
        foreach (['requisitos', 'que_llevar'] as $campo) {
            if ($request->has($campo)) {
                $cambios[$campo] = $this->descripcion($validado[$campo] ?? null);
            }
        }
        $recursos = null;
        if (array_key_exists('recursos', $validado)) {
            $elegidos = RecursoTenant::query()->whereIn('ulid', $validado['recursos'])->get(['id', 'activo']);
            $recursos = $elegidos->pluck('id')->all();
            if (count($recursos) !== count(array_unique($validado['recursos']))) {
                throw ValidationException::withMessages(['recursos' => ['Algún espacio no existe.']]);
            }
            // Solo con espacios inactivos el servicio no se podría agendar.
            if ($elegidos->isNotEmpty() && ! $elegidos->contains('activo', true)) {
                throw ValidationException::withMessages(['recursos' => ['Elige al menos un espacio activo.']]);
            }
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
        // Se paga al reservar: con precio (si se toca la política o el precio).
        $politica = $cambios['politica_reserva'] ?? $oferta->politica_reserva;
        $politica = $politica instanceof PoliticaReservaTenant ? $politica->value : (string) $politica;
        if ((isset($validado['politica_reserva']) || $request->has('precio_clase_minor'))
            && $politica === PoliticaReservaTenant::Pago->value) {
            self::exigirPrecioParaPagar(array_key_exists('precio_clase_minor', $cambios) ? $cambios['precio_clase_minor'] : $oferta->precio_clase_minor);
        }
        $anticipo = self::anticipo($validado, array_key_exists('precio_clase_minor', $cambios) ? $cambios['precio_clase_minor'] : $oferta->precio_clase_minor);
        if ($anticipo !== null) {
            $cambios = [...$cambios, ...$anticipo];
        }

        // Qué era antes, para la bitácora (un nombre o un cupo corregidos).
        $antes = $oferta->only(['nombre', 'capacidad']);

        // Se guarda todo o no cambia nada: espacios, servicios incluidos (que validan
        // bajo candado lo que no se puede saber antes) y los datos del servicio.
        DB::connection('tenant')->transaction(function () use ($oferta, $validado, $recursos, $cambios): void {
            if ($recursos !== null) {
                $oferta->recursos()->sync($recursos);
            }
            if (array_key_exists('incluye', $validado)) {
                $this->incluir($oferta, array_values($validado['incluye']));
            }
            $oferta->update($cambios);
        });

        $despues = $oferta->only(['nombre', 'capacidad']);
        if ($despues !== $antes) {
            $this->auditoria->registrar($this->actor($request), 'oferta.actualizada', 'oferta', (string) $oferta->ulid, $antes, $despues);
        }

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    /**
     * Lo retira del catálogo (ADR 0154): ya no se agenda, no se vende ni aparece en
     * línea; lo ya agendado sigue en pie (`sesiones_futuras`) y su historial se
     * conserva.
     */
    public function archivar(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        $resultado = $this->archivo->archivar($oferta, $this->actor($request));

        return response()->json([
            'data' => $this->presentarOferta($oferta->refresh()),
            'meta' => $resultado,
        ]);
    }

    /** Lo vuelve a ofrecer, como estaba. */
    public function restaurar(Request $request): JsonResponse
    {
        $oferta = OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
        $this->archivo->restaurar($oferta, $this->actor($request));

        return response()->json(['data' => $this->presentarOferta($oferta->refresh())]);
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
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

    /**
     * Los servicios o clases que se ofrecen. Con `archivados=1`, también los
     * archivados (la pantalla del catálogo, para volver a ofrecerlos).
     */
    public function ofertas(Request $request): JsonResponse
    {
        $ofertas = OfertaTenant::query()
            ->with(['actividad', 'recursos', 'incluidas'])
            ->when(! $request->boolean('archivados'), fn ($q) => $q->vigentes())
            ->orderBy('nombre')
            ->get();

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
            if ($incluidas->contains(static fn (OfertaTenant $o): bool => $o->archivada())) {
                throw ValidationException::withMessages(['incluye' => ['Algún servicio está archivado; vuelve a ofrecerlo para incluirlo.']]);
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
            'para_principiantes' => $oferta->para_principiantes,
            'requisitos' => $oferta->requisitos,
            'que_llevar' => $oferta->que_llevar,
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
            'archivado' => $oferta->archivada(),
            'regreso_dias' => $oferta->regreso_dias,
            // Anticipo para apartar en línea (ADR 0161): el monto o el porcentaje, y lo que
            // resulta hoy con su precio (null = se paga completo).
            'anticipo_minor' => $oferta->anticipo_minor,
            'anticipo_pct' => $oferta->anticipo_pct,
            'anticipo_resultante_minor' => $oferta->anticipoMinor(),
        ];
    }

    /**
     * El anticipo que se guarda, si vino en la petición: un monto (menor que el precio)
     * o un porcentaje; elegir uno borra el otro. Null si no vino.
     *
     * @param  array<string, mixed>  $validado
     * @return array{anticipo_minor: int|null, anticipo_pct: int|null}|null
     */
    private static function anticipo(array $validado, ?int $precioMinor): ?array
    {
        if (! array_key_exists('anticipo_minor', $validado) && ! array_key_exists('anticipo_pct', $validado)) {
            return null;
        }
        $monto = isset($validado['anticipo_minor']) ? (int) $validado['anticipo_minor'] : null;
        if ($monto !== null && $monto >= (int) ($precioMinor ?? 0)) {
            throw ValidationException::withMessages(['anticipo_minor' => ['El anticipo debe ser menor que el precio; si es igual, se paga completo al agendar.']]);
        }

        return [
            'anticipo_minor' => $monto,
            'anticipo_pct' => $monto === null && isset($validado['anticipo_pct']) ? (int) $validado['anticipo_pct'] : null,
        ];
    }

    /** Pagar al reservar sin precio dejaría una cuenta en cero: se pide el precio. */
    private static function exigirPrecioParaPagar(mixed $precioMinor): void
    {
        if ((int) ($precioMinor ?? 0) <= 0) {
            throw ValidationException::withMessages(['precio_clase_minor' => ['Un servicio que se paga al reservar necesita un precio mayor a 0.']]);
        }
    }
}
