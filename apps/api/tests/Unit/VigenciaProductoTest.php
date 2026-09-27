<?php

declare(strict_types=1);

use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;

/*
| Vigencia de un producto (ADR 0050): el último día en que se puede usar lo que se
| compra en una fecha. Ese día cuenta.
*/

it('calcula hasta cuándo sirve lo comprado', function (TipoVigencia $tipo, int $cantidad, string $compra, string $hasta): void {
    expect((new VigenciaProducto($tipo, $cantidad))->hasta($compra))->toBe($hasta);
})->with([
    '30 días' => [TipoVigencia::Dias, 30, '2026-10-14', '2026-11-13'],
    'un mes a la misma fecha' => [TipoVigencia::Meses, 1, '2026-10-14', '2026-11-14'],
    'un mes sin desbordar febrero' => [TipoVigencia::Meses, 1, '2027-01-31', '2027-02-28'],
    'tres meses' => [TipoVigencia::Meses, 3, '2026-10-14', '2027-01-14'],
    'hasta fin de este mes' => [TipoVigencia::FinDeMes, 1, '2026-10-14', '2026-10-31'],
    'hasta fin del mes siguiente' => [TipoVigencia::FinDeMes, 2, '2026-10-14', '2026-11-30'],
    'comprando el último día del mes' => [TipoVigencia::FinDeMes, 1, '2026-10-31', '2026-10-31'],
]);
