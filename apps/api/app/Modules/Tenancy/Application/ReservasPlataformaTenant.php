<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Integraciones\ClienteTotalPassReservas;
use App\Modules\Tenancy\Integraciones\ClienteWellhubReservas;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Integraciones\ResultadoPlataforma;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Las reservas que llegan desde la app de Wellhub o TotalPass (ADR 0149). La persona
 * sigue siendo visitante de la plataforma y su reserva, un lugar de plataforma
 * (`origen: reserva`): ocupa cupo, no consume créditos ni derechos.
 *
 * - Solicitada: se decide con la clase bloqueada (invariante 8) y se le contesta a la
 *   plataforma: aceptada, o rechazada con su motivo (la clase ya no existe o se canceló,
 *   ya cerraron sus reservas, ya tiene lugar en esa clase o no hay lugar). Wellhub da 15
 *   minutos para contestar y TotalPass 5: si no se le pudo contestar a tiempo, la
 *   plataforma la rechaza y el lugar se libera.
 * - TotalPass no firma sus avisos: antes de decidir se comprueba con TotalPass que la
 *   reserva existe y es de esa clase. Si su sede no pide confirmar, la aceptada ya quedó
 *   y la rechazada se cancela.
 * - Cancelada (a tiempo o tarde): el lugar se libera y queda cómo se canceló.
 * - Una reserva sin check-in queda «no asistió» al terminar la clase.
 *
 * Después de cada cambio se le vuelven a mandar a la plataforma los lugares de la clase.
 */
class ReservasPlataformaTenant
{
    /** Minutos que da Wellhub para contestar una reserva. */
    private const MINUTOS_WELLHUB = 15;

    /** Minutos que da TotalPass para confirmar una reserva. */
    private const MINUTOS_TOTALPASS = 5;

    /** El motivo de TotalPass para cada rechazo. */
    private const MOTIVOS_TOTALPASS = [
        'CLASS_NOT_FOUND' => 'canceled_event',
        'CLASS_HAS_BEEN_CANCELED' => 'canceled_event',
        'CHECK_IN_AND_CANCELATION_WINDOWS_CLOSED' => 'unavailable_spot',
        'USER_IS_ALREADY_BOOKED' => 'user_already_in_class',
        'CLASS_IS_FULL' => 'class_overbooked',
    ];

    /** Lo que se le dice a Wellhub en cada rechazo (su categoría está en `motivo`). */
    private const MOTIVOS = [
        'CLASS_NOT_FOUND' => 'La clase ya no está disponible.',
        'CLASS_HAS_BEEN_CANCELED' => 'La clase se canceló.',
        'CHECK_IN_AND_CANCELATION_WINDOWS_CLOSED' => 'Las reservas de esta clase ya cerraron.',
        'USER_IS_ALREADY_BOOKED' => 'Ya tienes un lugar en esta clase.',
        'CLASS_IS_FULL' => 'La clase está llena.',
    ];

    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly ClienteWellhubReservas $wellhub,
        private readonly ClienteTotalPassReservas $totalpass,
        private readonly VisitantesPlataformaTenant $visitantes,
        private readonly CupoDeSesionTenant $cupo,
        private readonly ParametrosTenant $parametros,
        private readonly PublicarEnPlataformasTenant $publicar,
    ) {}

    /** La referencia con que se guarda una reserva de la plataforma. */
    public static function referencia(ProveedorPartner $proveedor, string $numero): string
    {
        return $proveedor->value.':reserva:'.$numero;
    }

    /** Procesa un aviso de reserva (lo llama la bandeja con la integración activa). */
    public function procesar(EventoPlataformaTenant $evento, ProveedorPartner $proveedor): void
    {
        if ($proveedor === ProveedorPartner::TotalPass) {
            $this->totalPass($evento);

            return;
        }
        $datos = $evento->payload;
        // Las llaves de la conexión (la sede) por la que llegó el aviso (ADR 0202).
        $credenciales = $evento->integracion?->llaves() ?? $this->registro->credenciales($proveedor->value);
        $gimnasio = (string) Arr::get($datos, 'event_data.slot.gym_id', '');
        if ($gimnasio !== '' && ($credenciales['gym_id'] ?? '') !== '' && $gimnasio !== (string) $credenciales['gym_id']) {
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'El aviso es de otro gimnasio de Wellhub.');

            return;
        }
        $numero = (string) Arr::get($datos, 'event_data.slot.booking_number', '');

        match ($evento->tipo) {
            AvisosPlataformaTenant::RESERVA_SOLICITADA => $this->solicitudWellhub($evento, $numero, $credenciales),
            AvisosPlataformaTenant::RESERVA_CANCELADA => $this->cancelar($evento, $proveedor, $numero, LugarPlataformaTenant::CANCELADO),
            AvisosPlataformaTenant::RESERVA_CANCELADA_TARDE => $this->cancelar($evento, $proveedor, $numero, LugarPlataformaTenant::CANCELADO_TARDE),
            default => $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'Aviso de reserva desconocido.'),
        };
    }

    /**
     * Las reservas de la plataforma de clases que ya terminaron sin check-in: no asistió.
     */
    public function cerrarTerminadas(): int
    {
        return LugarPlataformaTenant::query()
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->where('estado', LugarPlataformaTenant::RESERVADO)
            ->whereHas('sesion', fn ($q) => $q->where('termina_en', '<', now()))
            ->update(['estado' => LugarPlataformaTenant::NO_ASISTIO, 'updated_at' => now()]);
    }

    /**
     * Concilia las reservas de TotalPass de las clases publicadas que aún no empiezan
     * (sus avisos no van firmados ni dicen si reintentan): una reserva viva cuyo aviso
     * se perdió entra como si hubiera llegado, y un lugar cuya reserva TotalPass ya no
     * tiene viva (cancelada sin aviso) se libera. Cuántos lugares cambió.
     */
    public function conciliarTotalPass(): int
    {
        $cambios = 0;
        // Cada sede es otro «place» de TotalPass con su llave (ADR 0202).
        IntegracionTenant::query()->where('proveedor', ProveedorPartner::TotalPass->value)->where('reservas_activas', true)->get()
            ->each(function (IntegracionTenant $integracion) use (&$cambios): void {
                if ($this->registro->vigente($integracion)) {
                    $cambios += $this->conciliarConexionTotalPass($integracion);
                }
            });

        return $cambios;
    }

    private function conciliarConexionTotalPass(IntegracionTenant $integracion): int
    {
        $publicados = HorarioPlataformaTenant::query()
            ->where('proveedor', ProveedorPartner::TotalPass->value)
            ->where('estado', HorarioPlataformaTenant::PUBLICADO)
            ->whereHas('publicacion', fn ($q) => $q->where('integracion_id', $integracion->getKey()))
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now()))
            ->with('sesion')
            ->get();
        if ($publicados->isEmpty()) {
            return 0;
        }
        $llave = $integracion->llaves()['place_api_key'] ?? '';
        $ultima = $publicados->max(fn (HorarioPlataformaTenant $h): int => (int) $h->sesion?->inicia_en->getTimestamp());
        // TotalPass da hasta 30 días por consulta.
        $hasta = CarbonImmutable::createFromTimestamp(min((int) $ultima, now()->addDays(29)->getTimestamp()))->addMinute();
        $lista = $this->totalpass->reservas($llave, CarbonImmutable::now()->startOfDay(), $hasta);
        if ($lista === null) {
            return 0;
        }

        $vistas = [];
        foreach ($lista as $reserva) {
            $slot = (string) ($reserva['_id'] ?? $reserva['id'] ?? '');
            if ($slot === '' || preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $slot) !== 1) {
                continue;
            }
            $vistas[$slot] = $reserva;
            $publicado = self::viva($reserva) ? $this->horarioDeTotalPass(self::clasesDe($reserva)) : null;
            if ($publicado === null || LugarPlataformaTenant::query()->where('referencia_externa', self::referencia(ProveedorPartner::TotalPass, $slot))->exists()) {
                continue;
            }
            // Se perdió su aviso: entra como si hubiera llegado (una sola vez).
            EventoPlataformaTenant::query()->firstOrCreate(
                ['proveedor' => ProveedorPartner::TotalPass->value, 'clave' => 'reserva:'.$slot.':'.AvisosPlataformaTenant::RESERVA_SOLICITADA],
                [
                    'integracion_id' => $integracion->getKey(),
                    'tipo' => AvisosPlataformaTenant::RESERVA_SOLICITADA,
                    'payload' => [
                        'event' => ['id' => (string) ($publicado->evento_externo ?? '')],
                        'slot' => ['id' => $slot, 'status' => (string) ($reserva['status'] ?? 'active')],
                        'user' => (array) ($reserva['user'] ?? []),
                        'conciliada' => true,
                    ],
                    'estado' => EventoPlataformaTenant::PENDIENTE,
                    'recibido_en' => now(),
                ],
            );
        }

        $cambios = 0;
        LugarPlataformaTenant::query()
            ->where('proveedor', ProveedorPartner::TotalPass->value)
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->where('estado', LugarPlataformaTenant::RESERVADO)
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now())
                ->when($integracion->sucursal_id !== null, fn ($s) => $s->where('sucursal_id', $integracion->sucursal_id)))
            ->get()
            ->each(function (LugarPlataformaTenant $lugar) use ($vistas, $llave, &$cambios): void {
                $slot = substr((string) $lugar->referencia_externa, strlen(self::referencia(ProveedorPartner::TotalPass, '')));
                $reserva = $vistas[$slot] ?? $this->totalpass->reserva($llave, $slot);
                if ($reserva === null || (is_array($reserva) && self::viva($reserva))) {
                    return;
                }
                $lugar->fill(['estado' => LugarPlataformaTenant::CANCELADO, 'motivo' => 'CANCELADA_EN_TOTALPASS'])->save();
                $this->ofrecerLiberado($lugar);
                $this->alCambiar($lugar);
                $cambios++;
            });

        return $cambios;
    }

    /**
     * @param  array<string, string>  $credenciales
     */
    private function solicitudWellhub(EventoPlataformaTenant $evento, string $numero, array $credenciales): void
    {
        $datos = $evento->payload;
        $solicitada = self::momento(Arr::get($datos, 'event_data.timestamp'), $evento);
        $publicado = HorarioPlataformaTenant::query()
            ->where('proveedor', ProveedorPartner::Wellhub->value)
            ->where('id_externo', (string) Arr::get($datos, 'event_data.slot.id', ''))
            ->first();
        $wellhubId = (string) preg_replace('/\D/', '', (string) Arr::get($datos, 'event_data.user.unique_token', ''));
        $visitante = $wellhubId === '' ? null : $this->visitantes->registrar(ProveedorPartner::Wellhub->value, $wellhubId, [
            'nombre' => Arr::get($datos, 'event_data.user.name'),
            'email' => Arr::get($datos, 'event_data.user.email'),
        ], null);

        if ($this->yaCancelada(ProveedorPartner::Wellhub, $numero)) {
            $evento->terminar(EventoPlataformaTenant::PROCESADO, 'La reserva ya se había cancelado en Wellhub.');

            return;
        }
        [$lugar, $motivo] = $this->decidir(ProveedorPartner::Wellhub, $publicado, $visitante, self::referencia(ProveedorPartner::Wellhub, $numero));
        // Una clase en línea manda su enlace al aceptarla.
        $enlace = $motivo === null ? self::enlaceEnLinea($lugar) : null;
        $respuesta = $this->wellhub->responderReserva($credenciales, $numero, $motivo, $motivo !== null ? (self::MOTIVOS[$motivo] ?? null) : null, $enlace);

        $this->asentar($evento, ProveedorPartner::Wellhub, $lugar, $motivo, $respuesta, $solicitada->addMinutes(self::MINUTOS_WELLHUB));
    }

    /** Los avisos de reserva de TotalPass: se reconocen por el id de la reserva («slot»). */
    private function totalPass(EventoPlataformaTenant $evento): void
    {
        $slot = (string) Arr::get($evento->payload, 'slot.id', '');

        match ($evento->tipo) {
            AvisosPlataformaTenant::RESERVA_SOLICITADA => $this->solicitudTotalPass($evento, $slot),
            AvisosPlataformaTenant::RESERVA_CANCELADA => $this->cancelar($evento, ProveedorPartner::TotalPass, $slot, LugarPlataformaTenant::CANCELADO),
            AvisosPlataformaTenant::RESERVA_CANCELADA_TARDE => $this->cancelar($evento, ProveedorPartner::TotalPass, $slot, LugarPlataformaTenant::CANCELADO_TARDE),
            default => $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'Aviso de reserva desconocido.'),
        };
    }

    private function solicitudTotalPass(EventoPlataformaTenant $evento, string $slot): void
    {
        $datos = $evento->payload;
        $llave = ($evento->integracion?->llaves() ?? $this->registro->credenciales(ProveedorPartner::TotalPass->value))['place_api_key'] ?? '';
        $referencia = self::referencia(ProveedorPartner::TotalPass, $slot);
        $limite = CarbonImmutable::instance($evento->recibido_en)->addMinutes(self::MINUTOS_TOTALPASS);
        $claseDelAviso = (string) Arr::get($datos, 'event.id', '');
        $usuario = (array) Arr::get($datos, 'user', []);

        // Sus avisos no van firmados: la reserva tiene que existir en TotalPass, seguir
        // activa y ser de una clase publicada aquí; la clase y la persona se toman de lo
        // que dice TotalPass, no del aviso (una vez decidida, su lugar ya lo dice).
        if ($this->yaCancelada(ProveedorPartner::TotalPass, $slot)) {
            $evento->terminar(EventoPlataformaTenant::PROCESADO, 'La reserva ya se había cancelado en TotalPass.');

            return;
        }
        $lugar = LugarPlataformaTenant::query()->where('referencia_externa', $referencia)->first();
        if ($lugar instanceof LugarPlataformaTenant) {
            $publicado = HorarioPlataformaTenant::query()->where('proveedor', ProveedorPartner::TotalPass->value)->where('sesion_id', $lugar->sesion_id)->first();
        } else {
            $reserva = $this->totalpass->reserva($llave, $slot);
            if ($reserva === null) {
                $this->asentar($evento, ProveedorPartner::TotalPass, null, null, ResultadoPlataforma::sinRespuesta('No se pudo comprobar la reserva con TotalPass.'), $limite);

                return;
            }
            $publicado = $reserva === false || ! self::viva($reserva) ? null : $this->horarioDeTotalPass(self::clasesDe($reserva));
            $delAviso = $publicado === null ? [] : array_filter([(string) $publicado->evento_externo, (string) $publicado->id_externo, ...self::clasesDe((array) $reserva)]);
            if ($publicado === null || ($claseDelAviso !== '' && ! in_array($claseDelAviso, $delAviso, true))) {
                $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'TotalPass no tiene esa reserva activa en una clase publicada aquí.');

                return;
            }
            $usuario = array_merge($usuario, array_filter((array) Arr::get((array) $reserva, 'user', []), static fn ($v): bool => is_scalar($v) && $v !== ''));
        }
        $codigo = trim((string) ($usuario['code'] ?? ''));
        // Su documento de identidad no se guarda: no hace falta para la clase. Ya
        // decidida, sigue siendo quien era (un aviso repetido no cambia sus datos).
        $visitante = $lugar instanceof LugarPlataformaTenant ? $lugar->visitante : ($codigo === '' ? null : $this->visitantes->registrar(ProveedorPartner::TotalPass->value, $codigo, [
            'nombre' => $usuario['name'] ?? null,
            'email' => $usuario['email'] ?? null,
            'telefono' => $usuario['phone'] ?? null,
            'producto' => Arr::get($datos, 'event.plan_code'),
        ], null));

        [$lugar, $motivo] = $this->decidir(ProveedorPartner::TotalPass, $publicado, $visitante, $referencia);
        $respuesta = $this->totalpass->responderReserva($llave, $slot, $motivo !== null ? (self::MOTIVOS_TOTALPASS[$motivo] ?? 'denied_by_gym') : null);
        if (! $respuesta->ok && ! $respuesta->reintentar) {
            // Su sede no pide confirmar (o ya no se puede): la aceptada ya quedó y la
            // rechazada se cancela.
            $respuesta = $motivo === null ? ResultadoPlataforma::hecho() : $this->totalpass->cancelarReserva($llave, $slot);
        }

        $this->asentar($evento, ProveedorPartner::TotalPass, $lugar, $motivo, $respuesta, $limite);
    }

    /**
     * Los identificadores de clase de una reserva de TotalPass (su evento y su
     * ocurrencia, según venga).
     *
     * @param  array<string, mixed>  $reserva
     * @return list<string>
     */
    private static function clasesDe(array $reserva): array
    {
        $ids = [];
        foreach (['eventId', 'event.id', 'eventOccurrenceUuid', 'event.occurrenceUuid', 'occurrenceUuid'] as $campo) {
            $valor = Arr::get($reserva, $campo);
            if (is_int($valor) || (is_string($valor) && $valor !== '')) {
                $ids[] = (string) $valor;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * ¿La reserva sigue viva en TotalPass? (no cancelada, rechazada ni vencida).
     *
     * @param  array<string, mixed>  $reserva
     */
    private static function viva(array $reserva): bool
    {
        $estado = strtolower((string) ($reserva['status'] ?? $reserva['state'] ?? 'active'));

        return ! str_contains($estado, 'cancel') && ! in_array($estado, ['denied', 'expired', 'deleted', 'removed', 'rejected'], true);
    }

    /**
     * El horario publicado aquí de una de esas clases de TotalPass.
     *
     * @param  list<string>  $clases
     */
    private function horarioDeTotalPass(array $clases): ?HorarioPlataformaTenant
    {
        if ($clases === []) {
            return null;
        }

        return HorarioPlataformaTenant::query()
            ->where('proveedor', ProveedorPartner::TotalPass->value)
            ->where(fn ($q) => $q->whereIn('evento_externo', $clases)->orWhereIn('id_externo', $clases))
            ->first();
    }

    /**
     * Lo que contestó la plataforma: hecho (se cierra el aviso y se le mandan los lugares
     * de la clase) o sin respuesta (se reintenta mientras la plataforma espere; después
     * ella la rechaza y el lugar se libera).
     */
    private function asentar(
        EventoPlataformaTenant $evento,
        ProveedorPartner $proveedor,
        ?LugarPlataformaTenant $lugar,
        ?string $motivo,
        ResultadoPlataforma $respuesta,
        CarbonImmutable $limite,
    ): void {
        if ($respuesta->ok) {
            $evento->terminar(EventoPlataformaTenant::PROCESADO, $motivo === null ? 'Reserva aceptada.' : 'Reserva rechazada: '.(self::MOTIVOS[$motivo] ?? $motivo));
            $this->alCambiar($lugar);

            return;
        }

        $evento->intentos++;
        if ($respuesta->reintentar && $evento->intentos < AvisosPlataformaTenant::TOPE_REINTENTOS && now()->lessThan($limite)) {
            $evento->resultado = (string) $respuesta->error;
            $evento->save();

            return;
        }
        // No se le pudo contestar a tiempo. TotalPass no dice qué hace con ella: se le
        // pregunta; si la dejó viva, el lugar sigue. Si no (o Wellhub, que la rechaza
        // sola), el lugar se libera.
        if ($proveedor === ProveedorPartner::Wellhub && $lugar instanceof LugarPlataformaTenant && $lugar->estado === LugarPlataformaTenant::RESERVADO) {
            // Lo último que se intenta: que Wellhub sepa que fue un error técnico.
            $this->wellhub->responderReserva(
                $evento->integracion?->llaves() ?? $this->registro->credenciales(ProveedorPartner::Wellhub->value),
                substr((string) $lugar->referencia_externa, strlen(self::referencia(ProveedorPartner::Wellhub, ''))),
                'TECHNICAL_ERROR',
                'No pudimos confirmar tu reserva a tiempo. Intenta de nuevo.',
            );
        }
        if ($lugar instanceof LugarPlataformaTenant && $lugar->estado === LugarPlataformaTenant::RESERVADO && ! $this->sigueEnTotalPass($proveedor, $lugar)) {
            $lugar->fill(['estado' => LugarPlataformaTenant::RECHAZADO, 'motivo' => 'SIN_RESPUESTA'])->save();
            $this->ofrecerLiberado($lugar);
            $this->alCambiar($lugar);
        }
        $evento->terminar(EventoPlataformaTenant::FALLIDO, 'No se pudo contestar a '.$proveedor->nombre().': '.$respuesta->error);
    }

    /**
     * Decide una reserva con la clase bloqueada. Devuelve el lugar (reservado o
     * rechazado; sin clase no hay lugar) y, si se rechaza, la categoría del motivo. La
     * misma reserva otra vez recibe la misma respuesta.
     *
     * @return array{0: LugarPlataformaTenant|null, 1: string|null}
     */
    private function decidir(ProveedorPartner $proveedor, ?HorarioPlataformaTenant $publicado, ?VisitantePlataformaTenant $visitante, string $referencia): array
    {
        return DB::connection('tenant')->transaction(function () use ($proveedor, $publicado, $visitante, $referencia): array {
            // Una clase retirada de la plataforma ya no se reserva.
            $sesion = $publicado instanceof HorarioPlataformaTenant && $publicado->estado !== HorarioPlataformaTenant::RETIRADO
                ? SesionTenant::query()->whereKey($publicado->sesion_id)->lockForUpdate()->first()
                : null;
            $previo = LugarPlataformaTenant::query()->where('referencia_externa', $referencia)->first();
            if ($previo instanceof LugarPlataformaTenant) {
                return [$previo, $previo->estado === LugarPlataformaTenant::RECHAZADO ? ($previo->motivo ?? 'CLASS_IS_FULL') : null];
            }
            if (! $sesion instanceof SesionTenant) {
                return [null, 'CLASS_NOT_FOUND'];
            }

            $motivo = match (true) {
                $sesion->estado === EstadoSesionTenant::Cancelada => 'CLASS_HAS_BEEN_CANCELED',
                now()->greaterThanOrEqualTo($sesion->inicia_en->copy()->subMinutes($this->parametros->entero('reservas.minutos_cierre'))) => 'CHECK_IN_AND_CANCELATION_WINDOWS_CLOSED',
                $visitante instanceof VisitantePlataformaTenant && LugarPlataformaTenant::query()
                    ->where('sesion_id', $sesion->getKey())
                    ->where('visitante_id', $visitante->getKey())
                    ->whereIn('estado', LugarPlataformaTenant::OCUPAN_LUGAR)
                    ->exists() => 'USER_IS_ALREADY_BOOKED',
                ! $this->hayLugar($sesion, $proveedor) => 'CLASS_IS_FULL',
                default => null,
            };

            $lugar = LugarPlataformaTenant::query()->create([
                'sesion_id' => $sesion->getKey(),
                'proveedor' => $proveedor->value,
                'visitante_id' => $visitante?->getKey(),
                'origen' => LugarPlataformaTenant::ORIGEN_RESERVA,
                'estado' => $motivo === null ? LugarPlataformaTenant::RESERVADO : LugarPlataformaTenant::RECHAZADO,
                'referencia_externa' => $referencia,
                'motivo' => $motivo,
            ]);

            return [$lugar, $motivo];
        });
    }

    /** ¿Le queda lugar a la plataforma en la clase? Sin cupo, los que se le ofrecen. */
    private function hayLugar(SesionTenant $sesion, ProveedorPartner $proveedor): bool
    {
        $disponibles = $this->cupo->disponibles($sesion, $proveedor->value);
        if ($disponibles !== null) {
            return $disponibles > 0;
        }

        return $this->cupo->ocupadas($sesion) < $this->parametros->entero('plataformas.lugares_sin_tope');
    }

    private function cancelar(EventoPlataformaTenant $evento, ProveedorPartner $proveedor, string $numero, string $estado): void
    {
        $lugar = LugarPlataformaTenant::query()->where('referencia_externa', self::referencia($proveedor, $numero))->first();
        if (! $lugar instanceof LugarPlataformaTenant) {
            // Llegó antes que su solicitud (los avisos pueden llegar fuera de orden): queda
            // anotada y la solicitud, cuando llegue, ya no aparta lugar.
            $evento->terminar(EventoPlataformaTenant::PROCESADO, 'Se canceló antes de llegar su solicitud.');

            return;
        }
        if ($lugar->estado === LugarPlataformaTenant::RESERVADO) {
            $lugar->fill(['estado' => $estado])->save();
            $this->ofrecerLiberado($lugar);
            $this->alCambiar($lugar);
        }
        $evento->terminar(EventoPlataformaTenant::PROCESADO, $estado === LugarPlataformaTenant::CANCELADO_TARDE ? 'Reserva cancelada tarde.' : 'Reserva cancelada.');
    }

    /**
     * El negocio cancela una reserva hecha desde la app de la plataforma (la clase se
     * cambió, la persona avisó por otro medio…): la plataforma le avisa a la persona, el
     * lugar se libera y se ofrece a la lista de espera. Null si quedó; si no, por qué.
     */
    public function cancelarPorElNegocio(LugarPlataformaTenant $lugar, string $motivo): ?string
    {
        if ($lugar->origen !== LugarPlataformaTenant::ORIGEN_RESERVA || $lugar->estado !== LugarPlataformaTenant::RESERVADO) {
            return 'Solo se cancela una reserva vigente hecha desde la app de la plataforma.';
        }
        $proveedor = ProveedorPartner::tryFrom($lugar->proveedor);
        $numero = $proveedor !== null ? substr((string) $lugar->referencia_externa, strlen(self::referencia($proveedor, ''))) : '';
        $sede = $lugar->sesion?->sucursal_id !== null ? (int) $lugar->sesion->sucursal_id : null;
        $resultado = match ($proveedor) {
            ProveedorPartner::Wellhub => $this->wellhub->cancelarReserva($this->registro->credenciales($proveedor->value, $sede), $numero, $motivo),
            ProveedorPartner::TotalPass => $this->totalpass->cancelarReserva($this->registro->credenciales($proveedor->value, $sede)['place_api_key'] ?? '', $numero),
            // Fitpass no tiene API: se cancela en su panel (ADR 0197).
            default => ResultadoPlataforma::hecho(),
        };
        if (! $resultado->ok) {
            return $resultado->error ?? 'La plataforma no canceló la reserva.';
        }
        $lugar->fill(['estado' => LugarPlataformaTenant::CANCELADO, 'motivo' => 'CANCELADA_POR_NEGOCIO'])->save();
        $this->ofrecerLiberado($lugar);
        $this->alCambiar($lugar);

        return null;
    }

    /**
     * Una reserva de Fitpass que recepción captura desde Fitpass Admin (sin API, ADR
     * 0202): con el ID de la reservación, ocupa su lugar en la clase (de lo que Fitpass
     * puede tomar, con su regla de cupo por canal) a nombre de su visitante. La misma
     * reservación otra vez es la misma.
     *
     * @throws DomainException si no cabe o el ID no sirve
     */
    public function apartarFitpass(SesionTenant $sesion, string $id, string $nombre, ?string $correo): LugarPlataformaTenant
    {
        $id = strtoupper(trim((string) preg_replace('/\s+/', '', $id)));
        if ($id === '' || strlen($id) > 64 || preg_match('/^[A-Z0-9_\-]+$/', $id) !== 1) {
            throw new DomainException('El ID de la reservación de Fitpass solo lleva letras, números y guiones.');
        }
        $referencia = self::referencia(ProveedorPartner::Fitpass, $id);
        $existente = LugarPlataformaTenant::query()->where('referencia_externa', $referencia)->first();
        if ($existente instanceof LugarPlataformaTenant) {
            if ((int) $existente->sesion_id !== (int) $sesion->getKey()) {
                throw new DomainException("La reservación {$id} de Fitpass ya está en otra clase.");
            }

            return $existente;
        }
        $correo = $correo !== null && trim($correo) !== '' ? mb_strtolower(trim($correo)) : null;
        $visitante = $this->visitantes->registrar(ProveedorPartner::Fitpass->value, $correo ?? 'reserva-'.$id, [
            'nombre' => trim($nombre) !== '' ? trim($nombre) : null,
            'email' => $correo,
        ], null);

        $lugar = DB::connection('tenant')->transaction(function () use ($sesion, $referencia, $visitante): LugarPlataformaTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoSesionTenant::Programada) {
                throw new DomainException('La clase no está programada: no admite reservas.');
            }
            if (! $this->hayLugar($bloqueada, ProveedorPartner::Fitpass)) {
                throw new DomainException('La clase ya no tiene lugar para Fitpass.');
            }

            return LugarPlataformaTenant::query()->create([
                'sesion_id' => $bloqueada->getKey(),
                'proveedor' => ProveedorPartner::Fitpass->value,
                'visitante_id' => $visitante->getKey(),
                'origen' => LugarPlataformaTenant::ORIGEN_RESERVA,
                'estado' => LugarPlataformaTenant::RESERVADO,
                'referencia_externa' => $referencia,
            ]);
        });
        // Wellhub y TotalPass ven al momento que ese lugar ya no está.
        $this->publicar->alCambiarSesion($sesion);

        return $lugar;
    }

    /**
     * Las reservas de una plataforma sin API cuya asistencia falta marcar en su panel:
     * de clases que ya terminaron en las últimas 48 horas (Fitpass acepta cambios hasta
     * entonces y de eso depende su pago). Las más viejas primero.
     *
     * @return Collection<int, LugarPlataformaTenant>
     */
    public function porMarcarEnPlataforma(ProveedorPartner $proveedor): Collection
    {
        return LugarPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->whereIn('estado', [LugarPlataformaTenant::ASISTIO, LugarPlataformaTenant::NO_ASISTIO])
            ->whereNull('marcado_en_plataforma_en')
            ->whereHas('sesion', fn ($q) => $q->where('termina_en', '<=', now())->where('inicia_en', '>=', now()->subHours(48)))
            ->with(['sesion.oferta', 'visitante'])
            ->get()
            ->sortBy(fn (LugarPlataformaTenant $l): int => (int) $l->sesion?->inicia_en->getTimestamp())
            ->values();
    }

    /** ¿Ya llegó el aviso de que esa reserva se canceló? (antes que su solicitud) */
    private function yaCancelada(ProveedorPartner $proveedor, string $numero): bool
    {
        return EventoPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->whereIn('clave', [
                'reserva:'.$numero.':'.AvisosPlataformaTenant::RESERVA_CANCELADA,
                'reserva:'.$numero.':'.AvisosPlataformaTenant::RESERVA_CANCELADA_TARDE,
            ])
            ->exists();
    }

    /** El enlace de una clase en línea, para la reserva aceptada (ADR 0184). */
    private static function enlaceEnLinea(?LugarPlataformaTenant $lugar): ?string
    {
        $enlace = trim((string) $lugar?->sesion?->oferta?->enlace_en_linea);

        return $enlace !== '' ? $enlace : null;
    }

    /** ¿TotalPass dejó viva la reserva que no se le pudo confirmar a tiempo? */
    private function sigueEnTotalPass(ProveedorPartner $proveedor, LugarPlataformaTenant $lugar): bool
    {
        if ($proveedor !== ProveedorPartner::TotalPass) {
            return false;
        }
        $slot = substr((string) $lugar->referencia_externa, strlen(self::referencia(ProveedorPartner::TotalPass, '')));
        $sede = $lugar->sesion?->sucursal_id !== null ? (int) $lugar->sesion->sucursal_id : null;
        $reserva = $this->totalpass->reserva($this->registro->credenciales(ProveedorPartner::TotalPass->value, $sede)['place_api_key'] ?? '', $slot);

        return is_array($reserva) && self::viva($reserva);
    }

    /** El lugar que dejó la plataforma se ofrece a la lista de espera, en su orden. */
    private function ofrecerLiberado(LugarPlataformaTenant $lugar): void
    {
        if ($lugar->sesion instanceof SesionTenant) {
            app(ReservasTenant::class)->promoverCupos($lugar->sesion);
        }
    }

    /** Los lugares de la clase cambiaron: se le vuelven a mandar a la plataforma. */
    private function alCambiar(?LugarPlataformaTenant $lugar): void
    {
        $sesion = $lugar?->sesion;
        $proveedor = $lugar !== null ? ProveedorPartner::tryFrom($lugar->proveedor) : null;
        if ($sesion instanceof SesionTenant && $proveedor !== null) {
            $this->publicar->sincronizarSesion($sesion, $proveedor);
        }
    }

    private static function momento(mixed $valor, EventoPlataformaTenant $evento): CarbonImmutable
    {
        if (is_numeric($valor)) {
            $segundos = (int) $valor > 100_000_000_000 ? intdiv((int) $valor, 1000) : (int) $valor;

            return CarbonImmutable::createFromTimestamp($segundos);
        }

        return CarbonImmutable::instance($evento->recibido_en);
    }
}
