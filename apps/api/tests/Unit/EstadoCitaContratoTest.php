<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\SesionTenantPresenter;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoAtencionCita;
use App\Modules\Tenancy\Reservas\EstadoPagoCita;
use Carbon\CarbonImmutable;

/*
| Contrato de la agenda (ADR 0104): el servidor calcula en qué va una cita (atención y
| pago) y la ocupación que se muestra, para que la web y la app no lo deduzcan cada una
| a su modo. La cita es de 10:00 a 11:00 UTC.
*/

/**
 * Sesión sin guardar (tipo, estado y horario), con sus conteos ya puestos.
 */
function sesionContratoUnit(string $tipo = 'cita', string $estado = 'programada', ?int $capacidad = 1, int $ocupados = 0): SesionTenant
{
    return (new SesionTenant)->forceFill([
        'tipo' => $tipo,
        'estado' => $estado,
        'capacidad' => $capacidad,
        'inicia_en' => CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'),
        'termina_en' => CarbonImmutable::parse('2026-10-03 11:00:00', 'UTC'),
        'ocupados' => $ocupados,
        'en_espera' => 0,
    ]);
}

/**
 * Reserva sin guardar del titular, con su asistencia y su orden (o sin ellas).
 */
function reservaContratoUnit(string $estado = 'confirmada', ?string $asistencia = null, ?string $orden = null): ReservaTenant
{
    $reserva = (new ReservaTenant)->forceFill(['estado' => $estado]);
    $reserva->setRelation('asistencia', $asistencia !== null ? (new AsistenciaTenant)->forceFill(['estado' => $asistencia]) : null);
    $reserva->setRelation('orden', $orden !== null ? (new OrdenTenant)->forceFill(['estado' => $orden]) : null);

    return $reserva;
}

it('la atención de una cita depende de si llegó y de la hora', function (?string $asistencia, string $hora, string $esperado): void {
    $ahora = CarbonImmutable::parse("2026-10-03 {$hora}", 'UTC');

    expect(EstadoAtencionCita::de(sesionContratoUnit(), reservaContratoUnit('confirmada', $asistencia), $ahora)->value)->toBe($esperado);
})->with([
    'por atender' => [null, '09:00', 'confirmada'],
    'durante, sin registrar aún' => [null, '10:30', 'confirmada'],
    'terminó sin registrar' => [null, '11:00', 'sin_registrar'],
    'llegó antes de empezar' => ['presente', '09:50', 'llego'],
    'en servicio' => ['presente', '10:15', 'en_servicio'],
    'completada' => ['presente', '11:05', 'completada'],
    'no asistió' => ['ausente', '11:05', 'no_asistio'],
]);

it('una cita cancelada (la sesión o su reserva) está cancelada', function (): void {
    $ahora = CarbonImmutable::parse('2026-10-03 09:00:00', 'UTC');

    expect(EstadoAtencionCita::de(sesionContratoUnit(estado: 'cancelada'), reservaContratoUnit(), $ahora))->toBe(EstadoAtencionCita::Cancelada)
        ->and(EstadoAtencionCita::de(sesionContratoUnit(), reservaContratoUnit('cancelada'), $ahora))->toBe(EstadoAtencionCita::Cancelada)
        ->and(EstadoAtencionCita::de(sesionContratoUnit(), reservaContratoUnit('expirada'), $ahora))->toBe(EstadoAtencionCita::Cancelada);
});

it('el pago de una cita va aparte de su atención', function (string $reserva, ?string $orden, ?string $esperado): void {
    expect(EstadoPagoCita::de(reservaContratoUnit($reserva, null, $orden))?->value)->toBe($esperado);
})->with([
    'apartada en línea' => ['pendiente_pago', 'pendiente', 'por_pagar'],
    'por cobrar en caja' => ['confirmada', 'pendiente', 'por_cobrar'],
    'pagada' => ['confirmada', 'pagada', 'pagada'],
    'con su plan, sin cobro' => ['confirmada', null, null],
    'su orden se canceló' => ['cancelada', 'cancelada', null],
]);

it('la ocupación que se muestra es la del cupo de una clase, con tope de 100', function (): void {
    expect(SesionTenantPresenter::ocupacion(sesionContratoUnit('clase', capacidad: 8, ocupados: 2)))
        ->toBe(['ocupados' => 2, 'capacidad' => 8, 'porcentaje' => 25])
        // Con sobrecupo (bajaron el cupo después de reservar), se queda en 100.
        ->and(SesionTenantPresenter::ocupacion(sesionContratoUnit('clase', capacidad: 2, ocupados: 3)))
        ->toBe(['ocupados' => 3, 'capacidad' => 2, 'porcentaje' => 100])
        // Sin cupo no hay porcentaje; en una cita tampoco (es de una persona).
        ->and(SesionTenantPresenter::ocupacion(sesionContratoUnit('clase', capacidad: null)))->toBeNull()
        ->and(SesionTenantPresenter::ocupacion(sesionContratoUnit('cita', ocupados: 1)))->toBeNull();
});
