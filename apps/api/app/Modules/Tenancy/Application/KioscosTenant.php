<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Acceso\ResultadoAcceso;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\PaseAccesoInvalido;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\KioscoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\AsistenciaAunNoAbre;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaNoConfirmada;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Kiosco de llegada (ADR 0183): una tableta en recepción donde el cliente muestra el
 * pase de su app (QR) y queda su llegada. Es otra forma de usar el pase de entrada: se
 * registra el acceso (como en recepción) y, si tiene una clase que ya se puede pasar
 * lista, su asistencia. La tableta solo dice el primer nombre y su clase.
 *
 * Cada tableta tiene su propia llave (se muestra una sola vez; se guarda su hash). La
 * llave solo sirve para registrar llegadas en su sede.
 */
class KioscosTenant
{
    private const PREFIJO = 'kio_';

    public function __construct(
        private readonly PaseAccesoTenant $pases,
        private readonly RegistrarAccesoTenant $accesos,
        private readonly AsistenciaTenant $asistencia,
    ) {}

    /**
     * Da de alta una tableta y devuelve su llave en claro (solo se ve aquí).
     *
     * @return array{kiosco: KioscoTenant, llave: string}
     */
    public function crear(string $nombre, ?int $sucursalId, ?Usuario $actor = null): array
    {
        $llave = self::PREFIJO.Str::random(40);
        $kiosco = KioscoTenant::query()->create([
            'nombre' => trim($nombre),
            'sucursal_id' => $sucursalId,
            'hash' => hash('sha256', $llave),
            'prefijo' => mb_substr($llave, 0, 10),
            'creado_por' => $actor?->getKey(),
        ]);

        return ['kiosco' => $kiosco, 'llave' => $llave];
    }

    /** La tableta activa de esa llave (y anota su último uso), o null. */
    public function resolver(#[\SensitiveParameter] string $llave): ?KioscoTenant
    {
        if (! str_starts_with($llave, self::PREFIJO)) {
            return null;
        }
        $kiosco = KioscoTenant::query()->where('hash', hash('sha256', $llave))->whereNull('revocado_en')->first();
        $kiosco?->forceFill(['ultimo_uso_en' => Carbon::now()])->saveQuietly();

        return $kiosco;
    }

    public function revocar(KioscoTenant $kiosco): void
    {
        if ($kiosco->revocado_en === null) {
            $kiosco->update(['revocado_en' => Carbon::now()]);
        }
    }

    /**
     * Registra la llegada de quien muestra su pase.
     *
     * @return array{resultado: string, nombre: string|null, clase: array{nombre: string|null, hora: string, lugar: int|null}|null, mensaje: string}
     */
    public function llegada(KioscoTenant $kiosco, #[\SensitiveParameter] string $codigo): array
    {
        try {
            $persona = $this->pases->resolver($codigo);
        } catch (PaseAccesoInvalido $e) {
            return ['resultado' => 'pase_invalido', 'nombre' => null, 'clase' => null, 'mensaje' => $e->getMessage()];
        }
        $nombre = trim((string) $persona->nombre) ?: null;
        $acceso = $this->accesos->registrar($persona, MetodoAcceso::Qr, $kiosco->sucursal_id, Carbon::now());
        if ($acceso->resultado !== ResultadoAcceso::Permitido) {
            $que = app(GestorDeConexionTenant::class)->actual()?->modalidad() === ModalidadServicio::Citas ? 'una cita tuya' : 'una clase tuya';

            return ['resultado' => 'sin_clase', 'nombre' => $nombre, 'clase' => null,
                'mensaje' => "No encontramos {$que} para esta hora. Pasa a recepción."];
        }
        if ($acceso->sesion_id === null) {
            // Acceso abierto (gimnasio): entra sin clase.
            return ['resultado' => 'bienvenida', 'nombre' => $nombre, 'clase' => null, 'mensaje' => 'Que tengas un buen entrenamiento.'];
        }

        $reserva = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('sesion_id', $acceso->sesion_id)
            ->where('estado', EstadoReserva::Confirmada->value)
            ->with(['sesion.oferta', 'asistencia'])
            ->first();
        $sesion = $reserva?->sesion;
        $clase = $sesion === null ? null : [
            'nombre' => $sesion->oferta?->nombre,
            'hora' => CarbonImmutable::instance($sesion->inicia_en)->setTimezone((string) ($sesion->zona_horaria ?: 'UTC'))->format('H:i'),
            'lugar' => $reserva->lugar !== null ? (int) $reserva->lugar : null,
        ];
        if (! $reserva instanceof ReservaTenant || $sesion === null) {
            return ['resultado' => 'bienvenida', 'nombre' => $nombre, 'clase' => null, 'mensaje' => 'Bienvenido.'];
        }
        if ($reserva->asistencia !== null) {
            return ['resultado' => 'ya_registrada', 'nombre' => $nombre, 'clase' => $clase, 'mensaje' => 'Tu llegada ya estaba registrada.'];
        }

        try {
            // Llegó después de que empezó: con retardo.
            $this->asistencia->marcar($reserva, EstadoAsistencia::Presente, null, $sesion->inicia_en->isPast());
        } catch (AsistenciaAunNoAbre $e) {
            return ['resultado' => 'temprano', 'nombre' => $nombre, 'clase' => $clase, 'mensaje' => $e->getMessage().' Vuelve a mostrar tu pase entonces.'];
        } catch (ReservaNoConfirmada $e) {
            return ['resultado' => 'sin_clase', 'nombre' => $nombre, 'clase' => null, 'mensaje' => $e->getMessage()];
        }

        return ['resultado' => 'llegada', 'nombre' => $nombre, 'clase' => $clase, 'mensaje' => 'Registramos tu llegada.'];
    }
}
