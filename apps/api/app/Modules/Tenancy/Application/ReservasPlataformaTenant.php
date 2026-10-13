<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Integraciones\ClienteWellhubReservas;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Las reservas que llegan desde la app de Wellhub (ADR 0149). La persona sigue siendo
 * visitante de la plataforma y su reserva, un lugar de plataforma (`origen: reserva`):
 * ocupa cupo, no consume créditos ni derechos.
 *
 * - Solicitada: se decide con la clase bloqueada (invariante 8) y se le contesta a la
 *   plataforma: aceptada, o rechazada con su motivo (la clase ya no existe o se canceló,
 *   ya cerraron sus reservas, ya tiene lugar en esa clase o no hay lugar). Wellhub da 15
 *   minutos para contestar: si no se le pudo contestar a tiempo, la rechaza ella y el
 *   lugar se libera.
 * - Cancelada (a tiempo o tarde): el lugar se libera y queda cómo se canceló.
 * - Una reserva sin check-in queda «no asistió» al terminar la clase.
 *
 * Después de cada cambio se le vuelven a mandar a la plataforma los lugares de la clase.
 */
class ReservasPlataformaTenant
{
    /** Minutos que da Wellhub para contestar una reserva. */
    private const MINUTOS_WELLHUB = 15;

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
        if ($proveedor !== ProveedorPartner::Wellhub) {
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'Aún no se reciben reservas de '.$proveedor->nombre().'.');

            return;
        }
        $datos = $evento->payload;
        $credenciales = $this->registro->credenciales($proveedor->value);
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

        [$lugar, $motivo] = $this->decidir(ProveedorPartner::Wellhub, $publicado, $visitante, self::referencia(ProveedorPartner::Wellhub, $numero));
        $respuesta = $this->wellhub->responderReserva($credenciales, $numero, $motivo, $motivo !== null ? (self::MOTIVOS[$motivo] ?? null) : null);

        if ($respuesta->ok) {
            $evento->terminar(EventoPlataformaTenant::PROCESADO, $motivo === null ? 'Reserva aceptada.' : 'Reserva rechazada: '.(self::MOTIVOS[$motivo] ?? $motivo));
            $this->alCambiar($lugar);

            return;
        }

        // Sin respuesta: se reintenta mientras Wellhub espere.
        $evento->intentos++;
        if ($respuesta->reintentar && $evento->intentos < AvisosPlataformaTenant::MAX_INTENTOS
            && now()->lessThan($solicitada->addMinutes(self::MINUTOS_WELLHUB))) {
            $evento->resultado = (string) $respuesta->error;
            $evento->save();

            return;
        }
        // No se le pudo contestar: Wellhub la rechaza sola y el lugar se libera.
        if ($lugar instanceof LugarPlataformaTenant && $lugar->estado === LugarPlataformaTenant::RESERVADO) {
            $lugar->fill(['estado' => LugarPlataformaTenant::RECHAZADO, 'motivo' => 'SIN_RESPUESTA'])->save();
            $this->alCambiar($lugar);
        }
        $evento->terminar(EventoPlataformaTenant::FALLIDO, 'No se pudo contestar a Wellhub: '.$respuesta->error);
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
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'No se encontró la reserva.');

            return;
        }
        if ($lugar->estado === LugarPlataformaTenant::RESERVADO) {
            $lugar->fill(['estado' => $estado])->save();
            $this->alCambiar($lugar);
        }
        $evento->terminar(EventoPlataformaTenant::PROCESADO, $estado === LugarPlataformaTenant::CANCELADO_TARDE ? 'Reserva cancelada tarde.' : 'Reserva cancelada.');
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
