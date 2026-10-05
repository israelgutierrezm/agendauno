<?php

declare(strict_types=1);

use App\Modules\Tenancy\Membresias\Aniversario;

/*
| La regla única de aniversarios mensuales: el mismo día del ancla; si el mes no lo
| tiene, su último día; nunca se desborda al mes siguiente y se vuelve al día del ancla.
*/

it('el siguiente aniversario no desborda febrero y vuelve al día del ancla', function (string $desde, int $ancla, string $esperado): void {
    expect(Aniversario::siguiente($desde, $ancla)->toDateString())->toBe($esperado);
})->with([
    'del 31 de enero, el último de febrero' => ['2027-01-31', 31, '2027-02-28'],
    'en bisiesto, el 29 de febrero' => ['2028-01-31', 31, '2028-02-29'],
    'del 28 de febrero, de vuelta al 31' => ['2027-02-28', 31, '2027-03-31'],
    'del 31 de marzo, el 30 de abril' => ['2027-03-31', 31, '2027-04-30'],
    'ancla 30 en febrero' => ['2027-01-30', 30, '2027-02-28'],
    'ancla 29 en febrero no bisiesto' => ['2027-01-29', 29, '2027-02-28'],
    'ancla 29 de vuelta en marzo' => ['2027-02-28', 29, '2027-03-29'],
    'un día cualquiera' => ['2027-05-15', 15, '2027-06-15'],
    'cambio de año' => ['2026-12-31', 31, '2027-01-31'],
]);

it('un ciclo va de su inicio a un día antes del siguiente aniversario', function (string $inicio, int $ancla, string $fin): void {
    expect(Aniversario::ventana($inicio, $ancla))->toBe([$inicio, $fin]);
})->with([
    'empieza el 31 de enero' => ['2027-01-31', 31, '2027-02-27'],
    'sigue el 28 de febrero' => ['2027-02-28', 31, '2027-03-30'],
    'sigue el 31 de marzo' => ['2027-03-31', 31, '2027-04-29'],
    'un mes normal' => ['2027-05-15', 15, '2027-06-14'],
]);

it('salta varios meses sin perder el ancla', function (): void {
    expect(Aniversario::siguiente('2027-01-31', 31, 3)->toDateString())->toBe('2027-04-30')
        ->and(Aniversario::siguiente('2027-01-31', 31, 2)->toDateString())->toBe('2027-03-31')
        ->and(Aniversario::diaDe('2027-01-31'))->toBe(31);
});
