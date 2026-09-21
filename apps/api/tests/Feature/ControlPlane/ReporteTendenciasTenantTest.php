<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea y liquida (ventanilla) una orden de 1 pack a un miembro nuevo → orden pagada hoy.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearOrdenPagada(array $e, string $pack, string $nombre): void
{
    $comprador = crearMiembroTenant($e, $nombre);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertOk();
}

it('la tendencia suma ingresos del día y desglosa por producto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    crearOrdenPagada($e, $pack, 'Ana');
    crearOrdenPagada($e, $pack, 'Beto');

    $hoy = CarbonImmutable::now()->toDateString();
    $data = $this->getJson("/api/v1/app/{$e['slug']}/reportes/tendencias?desde={$hoy}&hasta={$hoy}&agrupacion=dia", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($data['serie'])->toHaveCount(1);
    expect($data['serie'][0]['fecha'])->toBe($hoy);
    expect($data['serie'][0]['ingresos_minor'])->toBe(179800);
    expect($data['serie'][0]['ordenes'])->toBe(2);

    expect($data['por_producto'])->toHaveCount(1);
    expect($data['por_producto'][0]['producto'])->toBe('Pack 8 clases');
    expect($data['por_producto'][0]['unidades'])->toBe(2);

    expect($data['totales']['ingresos_minor'])->toBe(179800);
    expect($data['totales']['ordenes'])->toBe(2);
    expect($data['totales']['ticket_promedio_minor'])->toBe(89900);
});

it('la serie rellena los días sin ventas con ceros', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    crearOrdenPagada($e, $pack, 'Ana');

    $hoy = CarbonImmutable::now();
    $desde = $hoy->subDays(2)->toDateString();
    $hasta = $hoy->toDateString();

    $serie = collect($this->getJson("/api/v1/app/{$e['slug']}/reportes/tendencias?desde={$desde}&hasta={$hasta}&agrupacion=dia", conBearer($e['bearer']))
        ->assertOk()->json('data.serie'));

    expect($serie)->toHaveCount(3); // 3 días en el rango
    expect($serie->firstWhere('fecha', $hoy->toDateString())['ingresos_minor'])->toBe(89900);
    // Los días previos van en cero (continuidad de la línea).
    expect($serie->firstWhere('fecha', $hoy->subDays(2)->toDateString())['ingresos_minor'])->toBe(0);
});

it('la tendencia exporta la serie a CSV', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    crearOrdenPagada($e, $pack, 'Ana');

    $hoy = CarbonImmutable::now()->toDateString();
    $res = $this->get("/api/v1/app/{$e['slug']}/reportes/tendencias?desde={$hoy}&hasta={$hoy}&formato=csv", conBearer($e['bearer']))
        ->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->getContent())->toContain('Fecha,Ingresos,Ordenes');
    expect($res->getContent())->toContain('899.00');
});

it('la tendencia exige facturacion.ver (recepción no entra)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $hoy = CarbonImmutable::now()->toDateString();
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/tendencias?desde={$hoy}&hasta={$hoy}", conBearer($recep))
        ->assertForbidden();
});
