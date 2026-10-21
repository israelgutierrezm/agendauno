<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\Exceptions\FueraDeVentana;
use Carbon\CarbonImmutable;

/**
 * Cuándo puede reservar el cliente desde su cuenta (o la app): las reglas que el
 * negocio decide con sus parámetros, no fijas en código (ADR 0042).
 * - Clases: abren `reservas.dias_apertura` días antes (0 = en cuanto se programan) y
 *   cierran `reservas.minutos_cierre` minutos antes de empezar (0 = hasta que empieza).
 * - Citas: con `citas.minutos_anticipacion_minima` de anticipación y hasta
 *   `citas.dias_maximos_adelante` días adelante.
 *
 * Solo aplica al cliente: el negocio reserva y agenda cuando lo necesita. Un plan con
 * acceso anticipado (ADR 0182) le abre las clases esas horas antes que a los demás.
 */
class VentanaDeReservaTenant
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    /**
     * Lanza FueraDeVentana si el cliente aún no puede, o ya no puede, reservar la clase.
     * Con la persona, cuenta el acceso anticipado de sus planes.
     */
    public function exigirParaClase(SesionTenant $sesion, ?PersonaTenant $persona = null): void
    {
        $inicia = CarbonImmutable::instance($sesion->inicia_en);
        $ahora = CarbonImmutable::now();

        $dias = $this->parametros->entero('reservas.dias_apertura');
        if ($dias > 0 && $ahora->lessThan($inicia->subDays($dias))) {
            $antes = $persona !== null ? app(ResolverDerechoTenant::class)->horasDeAccesoAnticipado($persona, $sesion) : 0;
            $abre = $inicia->subDays($dias)->subHours($antes);
            if ($ahora->lessThan($abre)) {
                $local = $abre->setTimezone($this->zona($sesion));
                throw new FueraDeVentana('Las reservas de esta clase abren '.($antes > 0 ? 'para ti ' : '').'el '.$local->format('d/m/Y').' a las '.$local->format('H:i').'.');
            }
        }

        $minutos = $this->parametros->entero('reservas.minutos_cierre');
        if ($minutos > 0 && $ahora->greaterThan($inicia->subMinutes($minutos))) {
            throw new FueraDeVentana("Las reservas de esta clase cierran {$minutos} minutos antes de empezar.");
        }
    }

    /**
     * Desde cuándo y hasta cuándo puede agendar el cliente una cita (UTC).
     *
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}
     */
    public function limitesDeCita(): array
    {
        $ahora = CarbonImmutable::now();

        return [
            'desde' => $ahora->addMinutes($this->parametros->entero('citas.minutos_anticipacion_minima')),
            'hasta' => $ahora->addDays($this->parametros->entero('citas.dias_maximos_adelante')),
        ];
    }

    /** Lanza FueraDeVentana si la cita queda antes de la anticipación mínima o después del horizonte. */
    public function exigirParaCita(CarbonImmutable $inicia): void
    {
        ['desde' => $desde, 'hasta' => $hasta] = $this->limitesDeCita();
        if ($inicia->lessThan($desde)) {
            $minutos = $this->parametros->entero('citas.minutos_anticipacion_minima');
            throw new FueraDeVentana($minutos >= 60 && $minutos % 60 === 0
                ? 'Las citas se agendan con al menos '.intdiv($minutos, 60).' h de anticipación.'
                : "Las citas se agendan con al menos {$minutos} minutos de anticipación.");
        }
        if ($inicia->greaterThan($hasta)) {
            $dias = $this->parametros->entero('citas.dias_maximos_adelante');
            throw new FueraDeVentana("Las citas se agendan hasta {$dias} días adelante.");
        }
    }

    /**
     * Las reglas para que la web y la app dibujen su calendario.
     *
     * @return array{minutos_anticipacion_minima: int, dias_maximos_adelante: int}
     */
    public function reglasDeCita(): array
    {
        return [
            'minutos_anticipacion_minima' => $this->parametros->entero('citas.minutos_anticipacion_minima'),
            'dias_maximos_adelante' => $this->parametros->entero('citas.dias_maximos_adelante'),
        ];
    }

    private function zona(SesionTenant $sesion): string
    {
        return (string) ($sesion->zona_horaria ?: app(FechasNegocioTenant::class)->zona());
    }
}
