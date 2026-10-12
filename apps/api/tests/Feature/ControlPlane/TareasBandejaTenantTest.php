<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La bandeja de tareas por páginas: búsqueda por título, detalle o persona; filtros por
| tipo, por vencimiento (días del negocio) y por fechas; conteos de las pendientes por
| vencimiento. Una fecha sola vence al terminar ese día en el negocio.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una tarea y devuelve su id.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function tareaEnBandeja(array $e, string $titulo, array $datos = []): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/tareas", ['titulo' => $titulo, ...$datos], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

it('pagina las pendientes, las cuenta por vencimiento y filtra por cuándo vencen', function (): void {
    $e = estudioConSesion('fluo-tareas', 'f@correo.mx');
    // El reloj de las pruebas: 1 de octubre de 2026 en la Ciudad de México.
    tareaEnBandeja($e, 'Ayer', ['vence_en' => '2026-09-30']);
    tareaEnBandeja($e, 'Hoy', ['vence_en' => '2026-10-01']);
    tareaEnBandeja($e, 'El domingo', ['vence_en' => '2026-10-04']);
    tareaEnBandeja($e, 'Sin fecha');
    tareaEnBandeja($e, 'Más adelante', ['vence_en' => '2026-10-20']);
    $url = "/api/v1/app/{$e['slug']}/tareas";

    $primera = $this->getJson("{$url}?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($primera->json('data.*.titulo'))->toBe(['Ayer', 'Hoy'])
        ->and($primera->json('meta.total'))->toBe(5)
        ->and($primera->json('meta.ultima_pagina'))->toBe(3)
        ->and($primera->json('meta.conteos'))->toBe(['vencidas' => 1, 'hoy' => 1, 'semana' => 1, 'sin_fecha' => 1])
        ->and($primera->json('pendientes'))->toBe(5)
        // Vence al terminar el 1 de octubre en el negocio (UTC-6), no a medianoche UTC.
        ->and($primera->json('data.1.vence_en'))->toStartWith('2026-10-02T05:59:59');
    expect($this->getJson("{$url}?per_page=2&page=3", conBearer($e['bearer']))->json('data.*.titulo'))->toBe(['Sin fecha']);

    foreach (['vencidas' => ['Ayer'], 'hoy' => ['Hoy'], 'semana' => ['El domingo'], 'sin_fecha' => ['Sin fecha']] as $cuando => $titulos) {
        expect($this->getJson("{$url}?vencimiento={$cuando}", conBearer($e['bearer']))->json('data.*.titulo'))->toBe($titulos);
    }
    expect($this->getJson("{$url}?desde=2026-10-02&hasta=2026-10-31", conBearer($e['bearer']))->json('data.*.titulo'))
        ->toBe(['El domingo', 'Más adelante']);
    $this->getJson("{$url}?desde=2026-10-05&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
});

it('busca por título, detalle o persona y filtra por tipo', function (): void {
    $e = estudioConSesion('fluo-tareas', 'f@correo.mx');
    $paulina = crearMiembroTenant($e, 'Paulina');
    tareaEnBandeja($e, 'Llamarle', ['persona_id' => $paulina]);
    tareaEnBandeja($e, 'Pedir calcetas', ['detalle' => 'Al proveedor de grip']);
    $url = "/api/v1/app/{$e['slug']}/tareas";

    expect($this->getJson("{$url}?q=paulina", conBearer($e['bearer']))->json('data.*.titulo'))->toBe(['Llamarle'])
        ->and($this->getJson("{$url}?q=grip", conBearer($e['bearer']))->json('data.*.titulo'))->toBe(['Pedir calcetas'])
        ->and($this->getJson("{$url}?tipo=manual", conBearer($e['bearer']))->json('meta.total'))->toBe(2)
        ->and($this->getJson("{$url}?tipo=seguimiento", conBearer($e['bearer']))->json('meta.total'))->toBe(0);
    $this->getJson("{$url}?tipo=otra", conBearer($e['bearer']))->assertUnprocessable();
});

it('las completadas van de la más reciente a la más antigua y se acotan por cuándo se completaron', function (): void {
    $e = estudioConSesion('fluo-tareas', 'f@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/tareas";
    $primera = tareaEnBandeja($e, 'Primera');
    $segunda = tareaEnBandeja($e, 'Segunda');
    $this->postJson("{$url}/{$primera}/completar", [], conBearer($e['bearer']))->assertOk();
    $this->travel(2)->days();
    $this->postJson("{$url}/{$segunda}/completar", [], conBearer($e['bearer']))->assertOk();

    expect($this->getJson("{$url}?estado=completada", conBearer($e['bearer']))->json('data.*.titulo'))->toBe(['Segunda', 'Primera'])
        ->and($this->getJson("{$url}?estado=completada&desde=2026-10-03", conBearer($e['bearer']))->json('data.*.titulo'))->toBe(['Segunda'])
        ->and($this->getJson($url, conBearer($e['bearer']))->json('meta.total'))->toBe(0);
});
