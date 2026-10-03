<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CalcularRentaSaas;

/*
| Cálculo del cobro del SaaS por modalidad (ADR 0019), con la tarifa v1 acordada:
| clases por bandas de alumnos activos; citas por profesional (precio marginal) con
| personas incluidas fuera de cita. Dinero en enteros; IVA 16% sobre el subtotal.
*/

function tarifaClases(): array
{
    return [
        'iva_porcentaje' => 16,
        'bandas' => [
            ['hasta' => 40, 'monto_minor' => 33900],
            ['hasta' => 80, 'monto_minor' => 63900],
            ['hasta' => 120, 'monto_minor' => 90900],
            ['hasta' => 200, 'monto_minor' => 135900],
            ['hasta' => 300, 'monto_minor' => 178900],
            ['hasta' => 500, 'monto_minor' => 264900],
            ['hasta' => null, 'monto_minor' => 288900],
        ],
    ];
}

function tarifaCitas(): array
{
    return [
        'iva_porcentaje' => 16,
        'tramos' => [
            ['hasta' => 1, 'unitario_minor' => 26900],
            ['hasta' => 2, 'unitario_minor' => 22600],
            ['hasta' => 10, 'unitario_minor' => 13500],
            ['hasta' => 20, 'unitario_minor' => 8900],
            ['hasta' => null, 'unitario_minor' => 0],
        ],
        'personas_incluidas_por_profesional' => 10,
        'tope_personas_incluidas' => 100,
        'extra_por_persona_minor' => 900,
    ];
}

it('clases: cobra la banda del número de alumnos activos, con IVA', function (): void {
    $calc = new CalcularRentaSaas;

    $d = $calc->clases(tarifaClases(), 25);
    expect($d['subtotal_minor'])->toBe(33900)
        ->and($d['iva_minor'])->toBe(5424)
        ->and($d['total_minor'])->toBe(39324);

    expect($calc->clases(tarifaClases(), 40)['subtotal_minor'])->toBe(33900);
    expect($calc->clases(tarifaClases(), 41)['subtotal_minor'])->toBe(63900);
    expect($calc->clases(tarifaClases(), 500)['subtotal_minor'])->toBe(264900);
});

it('clases: arriba de la última banda se cobra el techo, y sin alumnos no hay cargo', function (): void {
    $calc = new CalcularRentaSaas;

    expect($calc->clases(tarifaClases(), 750)['subtotal_minor'])->toBe(288900);
    expect($calc->clases(tarifaClases(), 5000)['subtotal_minor'])->toBe(288900);

    $cero = $calc->clases(tarifaClases(), 0);
    expect($cero['total_minor'])->toBe(0)->and($cero['lineas'])->toBe([]);
});

it('citas: precio marginal por profesional activo', function (): void {
    $calc = new CalcularRentaSaas;

    expect($calc->citas(tarifaCitas(), 1, 0)['subtotal_minor'])->toBe(26900);
    expect($calc->citas(tarifaCitas(), 2, 0)['subtotal_minor'])->toBe(49500);
    expect($calc->citas(tarifaCitas(), 3, 0)['subtotal_minor'])->toBe(63000);
    // 12 = 269 + 226 + 8×135 + 2×89
    expect($calc->citas(tarifaCitas(), 12, 0)['subtotal_minor'])->toBe(175300);
    // Del 21 en adelante no cuestan: 25 = 269 + 226 + 8×135 + 10×89
    expect($calc->citas(tarifaCitas(), 25, 0)['subtotal_minor'])->toBe(246500);
    expect($calc->citas(tarifaCitas(), 0, 0)['total_minor'])->toBe(0);
});

it('citas: cada profesional cuenta completo, con una línea por tramo (ADR 0094)', function (): void {
    $d = (new CalcularRentaSaas)->citas(tarifaCitas(), 4, 0);

    // 269 + 226 + 2×135
    expect($d['subtotal_minor'])->toBe(76500);
    expect(array_column($d['lineas'], 'concepto'))->toBe([
        'Profesionales 1º: 1',
        'Profesionales 2º: 1',
        'Profesionales 3º a 10º: 2',
    ]);
});

it('citas: regla híbrida — personas fuera de cita incluidas por profesional, con tope', function (): void {
    $calc = new CalcularRentaSaas;

    // 2 profesionales incluyen 20 personas; 26 atendidas → 6 adicionales × $9.
    $d = $calc->citas(tarifaCitas(), 2, 26);
    expect($d['subtotal_minor'])->toBe(49500 + 5400);
    expect(end($d['lineas'])['detalle'])->toBe('20 incluidas con tus profesionales; 6 adicionales');

    // Dentro de lo incluido no hay cargo extra.
    expect($calc->citas(tarifaCitas(), 2, 20)['subtotal_minor'])->toBe(49500);

    // Tope de 100 incluidas aunque haya 15 profesionales (150 teóricas).
    $tope = $calc->citas(tarifaCitas(), 15, 130);
    expect(end($tope['lineas'])['importe_minor'])->toBe(30 * 900);
});

it('prorratea el mes en que termina la prueba gratis', function (): void {
    $calc = new CalcularRentaSaas;
    $d = $calc->prorratear($calc->clases(tarifaClases(), 25), 10, 30);

    expect($d['subtotal_minor'])->toBe(11300)
        ->and($d['iva_minor'])->toBe(1808)
        ->and($d['total_minor'])->toBe(13108)
        ->and($d['prorrateo'])->toBe(['dias_cobrables' => 10, 'dias_periodo' => 30]);

    // Un periodo completo no se toca.
    expect($calc->prorratear($calc->clases(tarifaClases(), 25), 30, 30)['total_minor'])->toBe(39324);
});

it('la cuota fija acordada es el total (IVA incluido)', function (): void {
    $d = (new CalcularRentaSaas)->fija(149900);

    expect($d['total_minor'])->toBe(149900)->and($d['iva_minor'])->toBe(0);
});
