<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| El dinero del reporte de negocio: nunca suma monedas distintas, distingue lo vendido
| (fecha de la compra), lo cobrado (fecha del cobro), lo devuelto (también parcial) y
| el neto, igual que los movimientos de Cobros.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un producto en esa moneda y una compra de él (sin cobrar); devuelve la orden.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function comprarReporteDinero(array $e, string $persona, int $precio, string $moneda): string
{
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => "Paquete {$moneda}", 'tipo' => 'paquete', 'precio_minor' => $precio,
        'moneda' => $moneda, 'ilimitado' => false, 'creditos_incluidos' => 4000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('separa monedas (sin convertir) y resta lo devuelto del neto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    foreach ([comprarReporteDinero($e, $ana, 10000, 'MXN'), comprarReporteDinero($e, $ana, 1000, 'USD')] as $orden) {
        $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    }
    $pagoMxn = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('moneda', 'MXN')['id'];
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pagoMxn}/reembolsos", ['monto_minor' => 2500, 'motivo' => 'Ajuste', 'revertir_creditos' => false], conBearer($e['bearer']))
        ->assertCreated();

    $hoy = CarbonImmutable::now('America/Mexico_City')->toDateString();
    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde={$hoy}&hasta={$hoy}", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['dinero_por_moneda'])->toBe([
        ['moneda' => 'MXN', 'ventas_minor' => 10000, 'cobrado_minor' => 10000, 'devuelto_minor' => 2500, 'neto_minor' => 7500],
        ['moneda' => 'USD', 'ventas_minor' => 1000, 'cobrado_minor' => 1000, 'devuelto_minor' => 0, 'neto_minor' => 1000],
    ])
        // Nada de «110 MXN»: el ingreso es el neto de la moneda principal.
        ->and($r['moneda'])->toBe('MXN')
        ->and($r['ingresos_minor'])->toBe(7500)
        ->and($r['ordenes_pagadas'])->toBe(2);
});

it('lo vendido cuenta el día de la compra y lo cobrado, el día del cobro', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $orden = comprarReporteDinero($e, $ana, 10000, 'MXN');

    // Se paga tres días después.
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $dia1 = $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))->assertOk()->json('data');
    expect($dia1['dinero_por_moneda'][0])->toMatchArray(['ventas_minor' => 10000, 'cobrado_minor' => 0])
        ->and($dia1['ingresos_minor'])->toBe(0)
        ->and($dia1['ordenes_pagadas'])->toBe(0);

    $dia4 = $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde=2026-10-04&hasta=2026-10-04", conBearer($e['bearer']))->assertOk()->json('data');
    expect($dia4['dinero_por_moneda'][0])->toMatchArray(['ventas_minor' => 0, 'cobrado_minor' => 10000])
        ->and($dia4['ingresos_minor'])->toBe(10000)
        ->and($dia4['ordenes_pagadas'])->toBe(1);
});
