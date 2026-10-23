<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Por qué no se cobró una membresía (dunning, R10). La clave es para quien administra
 * (la web la rotula con sus textos); al alumno le llega {@see paraCliente()}, la frase
 * de siempre en segunda persona. El detalle (el motivo del banco, o lo interno de por
 * qué no se pudo intentar) se guarda aparte. Un fallo registrado a mano por el equipo
 * no lleva clave: su texto es el detalle.
 */
enum MotivoFalloCobro: string
{
    /** El cargo no se pudo intentar: la pasarela no está lista o la orden no admite cobro. */
    case SinCobroEnLinea = 'sin_cobro_en_linea';

    /** El cobro quedó pendiente: el alumno debe completarlo en la página de la pasarela. */
    case PagoSinCompletar = 'pago_sin_completar';

    /** La pasarela rechazó el cobro en línea. */
    case Rechazado = 'rechazado';

    /** El banco rechazó el cargo del pago automático (tarjeta guardada o suscripción). */
    case TarjetaRechazada = 'tarjeta_rechazada';

    /** La suscripción de la pasarela no cobró en los días de espera. */
    case SuscripcionSinCobro = 'suscripcion_sin_cobro';

    /**
     * Lo que se le dice al alumno en el aviso: en sus palabras, sin el detalle interno
     * (M07). Solo el rechazo del pago automático dice la tarjeta y el motivo del banco.
     */
    public function paraCliente(?string $detalle = null, ?string $tarjeta = null): string
    {
        return match ($this) {
            self::SinCobroEnLinea => 'No pudimos cobrar en línea. Puedes pagar desde tu cuenta o en el negocio.',
            self::PagoSinCompletar => 'Falta completar el pago en línea.',
            self::Rechazado => 'El cobro fue rechazado.',
            self::TarjetaRechazada => 'No pudimos cobrar tu pago automático'
                .(trim((string) $tarjeta) !== '' ? ' a la tarjeta '.trim((string) $tarjeta) : '')
                .': '.(trim((string) $detalle) !== '' ? trim((string) $detalle) : 'El banco rechazó el cargo.'),
            self::SuscripcionSinCobro => 'No hemos recibido el cobro automático de tu suscripción.',
        };
    }
}
