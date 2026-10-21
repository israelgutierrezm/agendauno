<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * La bandeja de avisos de Wellhub y TotalPass (ADR 0148 y 0149). Cada aviso se guarda al
 * llegar, una sola vez, con su tipo, y se procesa aparte (Wellhub espera respuesta en un
 * segundo):
 *
 * - `checkin`: la persona hizo check-in en la app ({@see CheckinsAutomaticosTenant}).
 * - `checkin_reserva`: hizo check-in a la clase que reservó (Wellhub).
 * - `reserva_solicitada`, `reserva_cancelada`, `reserva_cancelada_tarde`: sus reservas
 *   desde la app ({@see ReservasPlataformaTenant}). TotalPass avisa de cada reserva con
 *   su evento y su «slot»; el estado del slot dice si se reservó o se canceló.
 *
 * Un error inesperado se reintenta; después de varios intentos queda «fallido».
 */
class AvisosPlataformaTenant
{
    public const CHECKIN = 'checkin';

    public const CHECKIN_RESERVA = 'checkin_reserva';

    public const RESERVA_SOLICITADA = 'reserva_solicitada';

    public const RESERVA_CANCELADA = 'reserva_cancelada';

    public const RESERVA_CANCELADA_TARDE = 'reserva_cancelada_tarde';

    /** Intentos ante un error inesperado antes de darlo por fallido. */
    public const MAX_INTENTOS = 5;

    /** Los avisos de reserva de Wellhub y su tipo aquí. */
    private const RESERVAS_WELLHUB = [
        'booking-requested' => self::RESERVA_SOLICITADA,
        'booking-canceled' => self::RESERVA_CANCELADA,
        'booking-late-canceled' => self::RESERVA_CANCELADA_TARDE,
    ];

    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly CheckinsAutomaticosTenant $checkins,
        private readonly ReservasPlataformaTenant $reservas,
    ) {}

    /**
     * Guarda el aviso una sola vez. Null si no es un aviso que se atienda (se contesta que
     * llegó, sin guardar nada).
     *
     * @param  array<string, mixed>  $datos
     */
    public function recibir(ProveedorPartner $proveedor, array $datos): ?EventoPlataformaTenant
    {
        $clasificado = match ($proveedor) {
            ProveedorPartner::Wellhub => self::wellhub($datos),
            ProveedorPartner::TotalPass => self::totalPass($datos),
            // Fitpass no manda avisos (sin API, ADR 0197).
            ProveedorPartner::Fitpass => null,
        };
        if ($clasificado === null) {
            return null;
        }
        [$tipo, $clave] = $clasificado;

        $existente = EventoPlataformaTenant::query()->where('proveedor', $proveedor->value)->where('clave', $clave)->first();
        if ($existente instanceof EventoPlataformaTenant) {
            return $existente;
        }
        try {
            return EventoPlataformaTenant::query()->create([
                'proveedor' => $proveedor->value,
                'tipo' => $tipo,
                'clave' => $clave,
                'payload' => $datos,
                'estado' => EventoPlataformaTenant::PENDIENTE,
                'recibido_en' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // La plataforma reintentó mientras se guardaba el primero.
            return EventoPlataformaTenant::query()->where('proveedor', $proveedor->value)->where('clave', $clave)->firstOrFail();
        }
    }

    /** Procesa los avisos pendientes del negocio conectado; cuántos terminó. */
    public function procesarPendientes(): int
    {
        $terminados = 0;
        EventoPlataformaTenant::query()
            ->where('estado', EventoPlataformaTenant::PENDIENTE)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (EventoPlataformaTenant $evento) use (&$terminados): void {
                $this->procesar($evento);
                if ($evento->estado !== EventoPlataformaTenant::PENDIENTE) {
                    $terminados++;
                }
            });

        return $terminados;
    }

    public function procesar(EventoPlataformaTenant $evento): void
    {
        if ($evento->estado !== EventoPlataformaTenant::PENDIENTE) {
            return;
        }
        $proveedor = ProveedorPartner::tryFrom($evento->proveedor);
        if ($proveedor === null || ! $this->registro->activa($proveedor->value)) {
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'La integración no está activa.');

            return;
        }

        try {
            if (in_array($evento->tipo, [self::CHECKIN, self::CHECKIN_RESERVA], true)) {
                $this->checkins->procesar($evento, $proveedor);
            } else {
                $this->reservas->procesar($evento, $proveedor);
            }
        } catch (Throwable $e) {
            report($e);
            $evento->intentos++;
            $evento->resultado = Str::limit($e->getMessage(), 290);
            if ($evento->intentos >= self::MAX_INTENTOS) {
                $evento->estado = EventoPlataformaTenant::FALLIDO;
                $evento->procesado_en = now();
            }
            $evento->save();
        }
    }

    /**
     * Los avisos de Wellhub: el de check-in se reconoce por el Wellhub ID con el momento;
     * los de reserva, por su número de reserva y lo que pasó.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: string, 1: string}|null
     */
    private static function wellhub(array $datos): ?array
    {
        $tipo = Arr::get($datos, 'event_type');
        if (! is_string($tipo)) {
            return null;
        }
        if ($tipo === 'checkin') {
            $wellhubId = (string) preg_replace('/\D/', '', (string) Arr::get($datos, 'event_data.user.unique_token', ''));

            return $wellhubId === '' ? null
                : [self::CHECKIN, 'checkin:'.$wellhubId.':'.(string) Arr::get($datos, 'event_data.timestamp', '')];
        }
        if ($tipo === 'checkin-booking-occurred') {
            $reserva = self::numeroDeReserva(Arr::get($datos, 'event_data.booking.booking_number'));

            return $reserva === null ? null : [self::CHECKIN_RESERVA, 'checkin-reserva:'.$reserva];
        }
        if (isset(self::RESERVAS_WELLHUB[$tipo])) {
            $reserva = self::numeroDeReserva(Arr::get($datos, 'event_data.slot.booking_number'));

            return $reserva === null ? null : [self::RESERVAS_WELLHUB[$tipo], 'reserva:'.$reserva.':'.self::RESERVAS_WELLHUB[$tipo]];
        }

        return null;
    }

    /**
     * Los avisos de TotalPass: el de una reserva trae su evento y su «slot» (se reconoce
     * por el id del slot y lo que pasó, según su estado); el de check-in, por la huella
     * de su token de confirmación.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: string, 1: string}|null
     */
    private static function totalPass(array $datos): ?array
    {
        $slot = Arr::get($datos, 'slot');
        if (is_array($slot) && is_array(Arr::get($datos, 'event'))) {
            $numero = self::numeroDeReserva($slot['id'] ?? $slot['_id'] ?? null);
            $estado = strtolower(is_string($slot['status'] ?? null) ? $slot['status'] : '');
            $tipo = match (true) {
                str_contains($estado, 'cancel') && str_contains($estado, 'late') => self::RESERVA_CANCELADA_TARDE,
                str_contains($estado, 'cancel') => self::RESERVA_CANCELADA,
                in_array($estado, ['active', 'reserved', 'confirmed', 'pending', 'booked'], true) => self::RESERVA_SOLICITADA,
                default => null,
            };

            return $numero === null || $tipo === null ? null : [$tipo, 'reserva:'.$numero.':'.$tipo];
        }

        $tipo = '';
        foreach (['event', 'event_type', 'type'] as $campo) {
            $valor = Arr::get($datos, $campo);
            if (is_string($valor)) {
                $tipo = strtoupper($valor);
                break;
            }
        }
        $token = CheckinsAutomaticosTenant::tokenTotalPass($datos);
        if (! str_contains($tipo, 'CHECK') || $token === null) {
            return null;
        }

        return [self::CHECKIN, 'checkin:'.hash('sha256', $token)];
    }

    private static function numeroDeReserva(mixed $valor): ?string
    {
        $numero = is_scalar($valor) ? trim((string) $valor) : '';

        return preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $numero) === 1 ? $numero : null;
    }
}
