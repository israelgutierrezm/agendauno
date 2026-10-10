<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Support\MarcaProducto;

/**
 * Avisos al alumno cuando su lugar se cancela, con los datos de la clase o cita y qué
 * pasó con su crédito:
 * - `reserva.cancelada`: se canceló su reserva (la canceló él o el negocio);
 * - `reserva.sesion_cancelada`: el negocio canceló la clase o cita completa (uno por
 *   persona afectada, con el enlace para reservar otro horario).
 *
 * Debe llamarse dentro de la transacción de la cancelación.
 */
class EmitirReservaCanceladaTenant
{
    public const CREDITO_DEVUELTO = 'Tu crédito regresó a tu cuenta.';

    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @param  string  $credito  qué pasó con su crédito, en una frase ('' si no usó crédito)
     */
    public function reservaCancelada(ReservaTenant $reserva, string $credito): void
    {
        $this->emitir('reserva.cancelada', $reserva, ['credito' => $credito]);
    }

    public function sesionCancelada(ReservaTenant $reserva, bool $creditoDevuelto): void
    {
        $this->emitir('reserva.sesion_cancelada', $reserva, [
            'credito' => $creditoDevuelto ? self::CREDITO_DEVUELTO : '',
            'enlace' => MarcaProducto::actual()->urlWeb().'/entrar?estudio='.rawurlencode((string) $this->gestor->actual()?->slug),
        ]);
    }

    /**
     * Frase del crédito de una cancelación tardía que sí se cobró.
     */
    public static function creditoCobrado(int $horas): string
    {
        return "Como cancelaste con menos de {$horas} h de anticipación, tu crédito no se devuelve.";
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function emitir(string $tipo, ReservaTenant $reserva, array $extra): void
    {
        $reserva->loadMissing(['sesion', 'persona']);
        $sesion = $reserva->sesion;
        $persona = $reserva->persona;
        if (! $sesion instanceof SesionTenant || ! $persona instanceof PersonaTenant) {
            return;
        }

        $this->eventos->registrar($tipo, 'reserva', (string) $reserva->ulid, [
            'persona_id' => (string) $persona->ulid,
            ...DatosDeSesion::para($sesion),
            ...$extra,
        ]);
    }
}
