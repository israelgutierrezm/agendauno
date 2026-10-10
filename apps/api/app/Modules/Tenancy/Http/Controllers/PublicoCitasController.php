<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\CalcularDisponibilidadTenant;
use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\CobroDeCitasTenant;
use App\Modules\Tenancy\Application\OpcionesCitaTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use App\Modules\Tenancy\Exceptions\ModalidadNoDisponible;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\OrigenCliente;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\CuentaRequerida;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Citas PÚBLICAS (guest, sin cuenta): un cliente reserva y paga una cita desde el
 * escaparate del estudio, sin registrarse. Crea (o reutiliza por correo) una persona
 * guest, agenda la cita (reserva pendiente + orden por la sesión) y la cobra en línea.
 * Solo con la página pública abierta (aunque no esté en el directorio); el `orden_id`
 * (ULID aleatorio) es la capacidad para pagar sin sesión. El webhook de la pasarela
 * confirma la reserva (fulfillment).
 */
class PublicoCitasController
{
    /** Días que se piden a la vez para el calendario. */
    private const MAX_DIAS = 62;

    public function __construct(
        private readonly AgendarCitaTenant $agendar,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly CalcularDisponibilidadTenant $disponibilidad,
        private readonly OpcionesCitaTenant $opciones,
        private readonly RegistroDePasarelasTenant $pasarelas,
        private readonly CobroDeCitasTenant $cobro,
        private readonly ParametrosTenant $parametros,
        private readonly WhatsAppTenant $whatsapp,
    ) {}

    /**
     * Opciones para agendar una cita (guest): los servicios que se cobran al agendar
     * (política = pago), sucursales y proveedores (barberos), todos por ULID —
     * el identificador público. Solo con la página pública abierta de un negocio de
     * citas.
     */
    public function opciones(Request $request): JsonResponse
    {
        $estudio = $this->paginaDeCitas($request);

        return response()->json(['data' => [
            'estudio' => [
                'slug' => $estudio->slug,
                'nombre' => $estudio->nombre,
                'logo_url' => $estudio->logo_url,
                // Cómo llama el negocio a quien atiende (p. ej. «Barbero»).
                'profesional' => (string) ($estudio->perfilConfig()['terminologia']['instructor'] ?? ''),
                // Su país y su lada (ADR 0103): la que se propone para el celular del cliente.
                'pais' => app(RegionNegocioTenant::class)->pais(),
                'lada' => app(RegionNegocioTenant::class)->lada(),
                // ¿Publicó su aviso de privacidad? (se enlaza antes de pedir los datos).
                'aviso_privacidad' => app(WaiversTenant::class)->avisoPrivacidad() !== null,
            ],
            ...$this->opciones->listar(),
        ]]);
    }

    /**
     * Huecos libres de un proveedor en una fecha (guest), para elegir hora antes de
     * agendar. Reusa el mismo motor que la vista de staff. Sin proveedor («cualquier
     * profesional disponible»), los de todo el equipo de la sede. Solo página abierta.
     */
    public function disponibilidad(Request $request): JsonResponse
    {
        $this->paginaDeCitas($request);

        $validado = $request->validate([
            'instructor_id' => ['nullable', 'string'],
            'sucursal_id' => ['required', 'string'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            // Con el servicio, su duración y sus márgenes (2.3); sin él, la duración.
            'oferta_id' => ['nullable', 'string'],
            'duracion_minutos' => ['required_without:oferta_id', 'nullable', 'integer', 'min:5', 'max:1440'],
            'paso_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $instructor = $this->profesionalElegido($validado);
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $oferta = ($validado['oferta_id'] ?? '') !== '' ? OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail() : null;
        [$duracion, $margenes] = $this->disponibilidad->duracionYMargenes($oferta, isset($validado['duracion_minutos']) ? (int) $validado['duracion_minutos'] : null);
        $paso = isset($validado['paso_minutos']) ? (int) $validado['paso_minutos'] : null;

        $slots = $instructor instanceof Usuario
            ? $this->disponibilidad->paraCliente()->paraFecha((int) $instructor->getKey(), $sucursal, $validado['fecha'], $duracion, $paso, $margenes, $oferta)
            : $this->disponibilidad->paraCliente()->paraCualquiera($sucursal, $validado['fecha'], $duracion, $paso, $margenes, $oferta);

        return response()->json(['data' => ['fecha' => $validado['fecha'], 'slots' => $slots]]);
    }

    /**
     * Días en que se puede agendar en la sede (desde hoy), para el calendario: los
     * que ya pasaron, en que nadie atiende o que el negocio cerró van como no
     * disponibles. Con `instructor_id`, los de esa persona. Solo página abierta.
     */
    public function dias(Request $request): JsonResponse
    {
        $this->paginaDeCitas($request);

        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DIAS],
            'instructor_id' => ['nullable', 'string'],
        ]);
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = $this->profesionalElegido($validado);

        return response()->json(['data' => $this->disponibilidad->paraCliente()->diasConAtencion(
            $sucursal,
            $validado['desde'],
            (int) ($validado['dias'] ?? 14),
            $instructor instanceof Usuario ? (int) $instructor->getKey() : null,
        )]);
    }

    public function agendar(Request $request): JsonResponse
    {
        $this->paginaDeCitas($request);
        // El negocio decide si recibe citas sin cuenta (o solo de sus clientes con cuenta).
        if (! $this->parametros->siNo('citas.agendar_sin_cuenta')) {
            throw new CuentaRequerida('Para agendar, entra a tu cuenta o pídele una al negocio.');
        }

        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellidos' => ['nullable', 'string', 'max:120'],
            // Para mandarle la confirmación y ligar sus citas si luego crea su cuenta.
            'email' => ['required', 'email', 'max:255'],
            // Con «+» ya trae su lada (+57 300…); sin ella, la de `lada` o, al avisarle,
            // la del país del negocio (ADR 0103).
            'celular' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]*$/'],
            // Lada del país del celular.
            'lada' => ['nullable', 'string', 'regex:/^\+[0-9]{1,4}$/'],
            'como_nos_conocio' => ['nullable', Rule::enum(OrigenCliente::class)],
            // Para el negocio: alergias, preferencias, si es su primera vez… (ADR 0067).
            'nota' => ['nullable', 'string', 'max:500'],
            // Para otra persona: quién asiste (la cita es de quien agenda, ADR 0068).
            'asiste' => ['nullable', 'string', 'max:120'],
            // Aceptó recibir los avisos de su cita por WhatsApp (ADR 0069).
            'acepta_whatsapp' => ['boolean'],
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        // Desde la página pública solo se agendan servicios que se pagan: uno que se toma
        // con la membresía se reserva desde la cuenta (con sesión).
        if ($oferta->politica_reserva !== PoliticaReservaTenant::Pago) {
            throw new SesionNoReservable('Este servicio se reserva desde tu cuenta.');
        }
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = $this->profesionalElegido($validado);
        $persona = $this->personaGuest($validado);
        if (($validado['acepta_whatsapp'] ?? false) && $this->whatsapp->enUso()) {
            $this->whatsapp->aceptar($persona, true);
        }
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();

        $reserva = $instructor instanceof Usuario
            ? $this->agendar->agendar($oferta, $sucursal, $persona, (int) $instructor->getKey(), $inicia, (int) $validado['duracion_minutos'])
            : $this->agendar->agendarConCualquiera($oferta, $sucursal, $persona, $inicia, (int) $validado['duracion_minutos']);
        $this->anotar($reserva, $validado['nota'] ?? null, $validado['asiste'] ?? null);
        $reserva->load(['orden', 'sesion.instructor']);
        $profesional = $reserva->sesion?->instructor;

        return response()->json(['data' => [
            'reserva' => $reserva->ulid,
            'estado' => $reserva->estado->value,
            'orden_id' => $reserva->orden?->ulid,
            'total_minor' => $reserva->orden?->total_minor,
            'moneda' => $reserva->orden?->moneda,
            // Quién atenderá: el elegido o el que se asignó.
            'profesional' => $profesional instanceof Usuario ? ['id' => $profesional->ulid, 'nombre' => (string) $profesional->name] : null,
        ]], 201);
    }

    /**
     * La cita de una orden por la sesión (el enlace del correo de apartado): qué es,
     * dónde, cuánto y hasta cuándo se puede pagar. El ULID de la orden es la
     * capacidad, igual que al pagar; no expone datos de la persona. Solo página abierta.
     */
    public function orden(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        abort_unless($estudio->paginaPublica(), 404);

        $orden = OrdenTenant::query()
            ->where('ulid', (string) $request->route('orden'))
            ->whereNotNull('sesion_id')
            ->firstOrFail();
        $reserva = ReservaTenant::query()
            ->where('orden_id', $orden->getKey())
            ->with(['sesion.oferta', 'sesion.sucursal'])
            ->latest('id')
            ->firstOrFail();
        $sesion = $reserva->sesion;
        $sede = $sesion?->sucursal;
        // Apartada: se libera si no se paga a tiempo (ver expirarReservasPendientes).
        $vence = $reserva->estado === EstadoReserva::PendientePago && $reserva->created_at !== null
            ? CarbonImmutable::instance($reserva->created_at)->addMinutes($this->parametros->entero('reservas.minutos_para_pagar'))
            : null;

        return response()->json(['data' => [
            'orden_id' => $orden->ulid,
            'estado_orden' => $orden->estado->value,
            'estado_reserva' => $reserva->estado->value,
            'servicio' => $sesion?->oferta?->nombre,
            'inicia_en' => $sesion?->inicia_en->toIso8601String(),
            'zona_horaria' => $sesion->zona_horaria ?? $sede?->zona_horaria,
            'sucursal' => $sede instanceof SucursalTenant ? [
                'nombre' => $sede->nombre,
                'direccion' => $sede->direccion,
                'mapa_url' => $sede->enlaceMapa(),
            ] : null,
            'total_minor' => $orden->total_minor,
            'moneda' => $orden->moneda,
            'vence_en' => $vence?->toIso8601String(),
            'pago_en_linea' => $this->cobro->pagoEnLinea(),
        ]]);
    }

    public function pagar(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        abort_unless($estudio->paginaPublica(), 404);

        $validado = $request->validate([
            'orden_id' => ['required', 'string'],
            'proveedor' => ['nullable', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        // Sin proveedor, la pasarela en línea con la que cobra el estudio.
        $validado['proveedor'] = ($validado['proveedor'] ?? '') !== '' ? $validado['proveedor'] : $this->pasarelas->enLinea();
        if ($validado['proveedor'] === null) {
            throw ValidationException::withMessages([
                'proveedor' => ['Este negocio todavía no cobra en línea; paga en el estudio.'],
            ]);
        }

        // El pago público solo admite pasarelas en línea (no efectivo/ventanilla/manual).
        if (in_array($validado['proveedor'], ['manual', 'efectivo', 'ventanilla'], true)) {
            throw ValidationException::withMessages([
                'proveedor' => ['El pago en efectivo se hace en el estudio; en línea usa una tarjeta.'],
            ]);
        }

        // La orden debe ser una CITA (por sesión) pendiente; el ULID es la capacidad.
        $orden = OrdenTenant::query()
            ->where('ulid', $validado['orden_id'])
            ->whereNotNull('sesion_id')
            ->where('estado', EstadoOrden::Pendiente->value)
            ->firstOrFail();

        $metodo = isset($validado['metodo']) ? MetodoPago::from((string) $validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? (string) $validado['idempotency_key'] : null;
        $pago = $this->cobrar->ejecutar($orden, (string) $validado['proveedor'], $metodo, $key, '/agendar/'.$estudio->slug);

        return response()->json(['data' => [
            'pago' => $pago->ulid,
            'estado' => $pago->estado->value,
            'checkout' => $pago->checkout,
        ]], 201);
    }

    /**
     * El negocio con su página pública abierta y que trabaja con citas: uno de clases
     * no agenda citas (ADR 0104). Pagar y ver la orden de una sesión no pasan por aquí:
     * también los usa la clase de pago suelto (ADR 0065).
     */
    private function paginaDeCitas(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        abort_unless($estudio->paginaPublica(), 404);
        if ($estudio->modalidad() !== ModalidadServicio::Citas) {
            throw new ModalidadNoDisponible($estudio->modalidad());
        }

        return $estudio;
    }

    /**
     * El profesional que eligió el cliente; null si no eligió («cualquier profesional
     * disponible»).
     *
     * @param  array<string, mixed>  $validado
     */
    private function profesionalElegido(array $validado): ?Usuario
    {
        $ulid = (string) ($validado['instructor_id'] ?? '');

        return $ulid !== '' ? Usuario::query()->where('ulid', $ulid)->firstOrFail() : null;
    }

    /**
     * Persona guest. Escribir un correo no demuestra que sea suyo, así que: si es de
     * una ficha vigente, la cita queda en su historial pero sin cambiar sus datos ni
     * darle acceso a nada (el servicio se paga; no usa su membresía); a alguien dado
     * de baja no se le reactiva: es un cliente nuevo. Sin usuario/login.
     *
     * @param  array<string, mixed>  $datos
     */
    private function personaGuest(array $datos): PersonaTenant
    {
        $email = isset($datos['email']) && $datos['email'] !== '' ? mb_strtolower(trim((string) $datos['email'])) : null;

        if ($email !== null) {
            $existente = PersonaTenant::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($existente instanceof PersonaTenant) {
                return $existente;
            }
        }

        // Apellidos: el primero es el paterno; lo demás, el materno.
        $apellidos = preg_split('/\s+/', trim((string) ($datos['apellidos'] ?? '')), 2) ?: [];
        $celular = trim((string) ($datos['celular'] ?? ''));
        $lada = (string) ($datos['lada'] ?? '');

        return PersonaTenant::query()->create([
            'nombre' => (string) $datos['nombre'],
            'primer_apellido' => ($apellidos[0] ?? '') !== '' ? $apellidos[0] : null,
            'segundo_apellido' => $apellidos[1] ?? null,
            'email' => $email,
            'celular' => $celular === '' ? null : ($lada !== '' && ! str_starts_with($celular, '+') ? $lada.' '.$celular : $celular),
            'como_nos_conocio' => $datos['como_nos_conocio'] ?? null,
            'tipo' => TipoPersonaTenant::Miembro->value,
            'activo' => true,
            'es_facturable' => true,
            'archivado' => false,
        ]);
    }

    /**
     * Lo que el cliente dejó para su cita: la nota para el negocio (ADR 0067) y, si es
     * para otra persona, quién asiste (ADR 0068).
     */
    private function anotar(ReservaTenant $reserva, mixed $nota, mixed $asiste): void
    {
        $cambios = array_filter([
            'nota_cliente' => trim((string) $nota),
            'asiste' => trim((string) $asiste),
        ], static fn (string $v): bool => $v !== '');
        if ($cambios !== []) {
            $reserva->update($cambios);
        }
    }
}
