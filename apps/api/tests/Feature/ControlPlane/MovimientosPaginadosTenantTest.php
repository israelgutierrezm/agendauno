<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Movimientos de cobro por páginas: búsqueda por persona, filtro por estado y por
| fechas (días del negocio), y un resumen de todo lo encontrado para los indicadores.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Cobra en caja un pack para un alumno nuevo.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cobroParaMovimientos(array $e, string $alumno): void
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, $alumno), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
}

it('pagina los movimientos, busca por persona, acota por fechas y resume todo lo encontrado', function (): void {
    $e = estudioConSesion('estudio-mov', 'a@correo.mx');
    cobroParaMovimientos($e, 'Ana');
    cobroParaMovimientos($e, 'Bea');
    $this->travel(3)->days();
    cobroParaMovimientos($e, 'Carla');
    $url = "/api/v1/app/{$e['slug']}/pagos";

    $primera = $this->getJson("{$url}?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($primera->json('data.*.persona'))->toBe(['Carla', 'Bea'])
        ->and($primera->json('meta.total'))->toBe(3)
        ->and($primera->json('meta.ultima_pagina'))->toBe(2)
        // El resumen es de los tres, no solo de la página.
        ->and($primera->json('meta.resumen.cobros'))->toBe(3)
        ->and($primera->json('meta.resumen.cobrado'))->toBe([['moneda' => 'MXN', 'total_minor' => 3 * 89900]])
        ->and($primera->json('meta.resumen.reembolsado'))->toBe([])
        ->and($primera->json('meta.resumen.sin_aprobar'))->toBe(0);
    expect($this->getJson("{$url}?per_page=2&page=2", conBearer($e['bearer']))->json('data.*.persona'))->toBe(['Ana']);

    expect($this->getJson("{$url}?q=bea", conBearer($e['bearer']))->json('data.*.persona'))->toBe(['Bea']);
    // Los dos primeros fueron el 1 de octubre; el de Carla, el 4.
    $delPrimero = $this->getJson("{$url}?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))->assertOk();
    expect($delPrimero->json('meta.total'))->toBe(2)
        ->and($delPrimero->json('meta.resumen.cobrado.0.total_minor'))->toBe(2 * 89900);
    expect($this->getJson("{$url}?estado=otros", conBearer($e['bearer']))->json('meta.total'))->toBe(0);

    $this->getJson("{$url}?desde=2026-10-05&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
    $this->getJson("{$url}?estado=raro", conBearer($e['bearer']))->assertUnprocessable();
});

it('descarga en CSV lo que encuentra con los mismos filtros, en la hora del negocio', function (): void {
    $e = estudioConSesion('estudio-mov', 'a@correo.mx');
    cobroParaMovimientos($e, 'Ana');
    cobroParaMovimientos($e, 'Bea');
    $url = "/api/v1/app/{$e['slug']}/pagos";

    $csv = $this->get("{$url}?formato=csv&q=bea", conBearer($e['bearer']))->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('movimientos.csv');
    $lineas = explode("\n", trim(ltrim((string) $csv->getContent(), "\u{FEFF}")));
    expect($lineas)->toHaveCount(2)
        ->and($lineas[0])->toBe('Fecha,Persona,Monto,Moneda,Devuelto,Estado,Forma de pago,Registró')
        // El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
        ->and($lineas[1])->toStartWith('2026-10-01 06:00,Bea,899.00,MXN,0.00,Aprobado,efectivo');
});
