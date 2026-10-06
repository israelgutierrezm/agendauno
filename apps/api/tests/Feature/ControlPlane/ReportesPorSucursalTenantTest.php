<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| Los reportes con el alcance de quien los consulta (R19, ADR 0098): alguien acotado a
| una sucursal solo ve lo de la suya en Tendencias, Cohortes, Rentabilidad, Equipo,
| Demanda y Sucursales (consultas, totales, desgloses y exportación); el dueño ve todo.
| Y Tendencias nunca suma monedas: cuenta lo vendido por la fecha de la compra y lo
| cobrado, devuelto y neto por la fecha del dinero, como el reporte principal.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Vende (y, si se pide, cobra en caja) un producto a ese cliente; devuelve la orden.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function venderReportesSede(array $e, string $persona, string $nombre, int $precio, bool $cobrar = true): string
{
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => $nombre, 'tipo' => 'paquete', 'precio_minor' => $precio,
        'ilimitado' => false, 'creditos_incluidos' => 4000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    if ($cobrar) {
        test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    }

    return $orden;
}

/**
 * Un negocio con dos sucursales, un cliente y una venta en cada una ($100 en A y $200
 * en B), una clase en cada una y alguien que solo ve los reportes de la sucursal A.
 *
 * @return array{e: array{slug: string, bearer: string}, acotado: string}
 */
function negocioDosSedesReportes(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    venderReportesSede($e, crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']), 'Pack A', 10000);
    venderReportesSede($e, crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']), 'Pack B', 20000);
    crearSesionTenant($e, $sedeA, 5, '2026-10-01 18:00:00');
    crearSesionTenant($e, $sedeB, 5, '2026-10-01 19:00:00');

    $rol = (string) test()->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Reportes de sede', 'permisos' => ['facturacion.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $acotado = personalConSesion($e['slug'], $e['bearer'], 'reportes@correo.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'reportes@correo.mx'), $sedeA['sucursal']);

    return ['e' => $e, 'acotado' => $acotado];
}

it('tendencias: quien ve una sucursal solo cuenta su dinero, su desglose y su exportación', function (): void {
    ['e' => $e, 'acotado' => $acotado] = negocioDosSedesReportes();
    $url = "/api/v1/app/{$e['slug']}/reportes/tendencias?desde=2026-10-01&hasta=2026-10-01";

    $todo = $this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data');
    expect($todo['totales'])->toMatchArray(['ventas' => 2, 'ventas_minor' => 30000, 'cobrado_minor' => 30000, 'neto_minor' => 30000]);

    $suyo = $this->getJson($url, conBearer($acotado))->assertOk()->json('data');
    expect($suyo['totales'])->toMatchArray(['ventas' => 1, 'ventas_minor' => 10000, 'cobrado_minor' => 10000, 'neto_minor' => 10000])
        ->and(array_column($suyo['por_producto'], 'producto'))->toBe(['Pack A']);

    $csv = (string) $this->get("{$url}&formato=csv", conBearer($acotado))->assertOk()->getContent();
    expect($csv)->toContain('100.00')->and($csv)->not->toContain('200.00');
});

it('tendencias: separa monedas y cuenta lo cobrado por la fecha del cobro', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    // $100 MXN comprados el 1 y cobrados el 4; $10 que quedaron en dólares (de antes).
    $orden = venderReportesSede($e, $ana, 'Mensual', 10000, cobrar: false);
    $usd = venderReportesSede($e, $ana, 'Clase suelta', 1000);
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($usd): void {
        $id = DB::connection('tenant')->table('ordenes')->where('ulid', $usd)->value('id');
        DB::connection('tenant')->table('ordenes')->where('id', $id)->update(['moneda' => 'USD']);
        DB::connection('tenant')->table('pagos')->where('orden_id', $id)->update(['moneda' => 'USD']);
    });
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/tendencias?desde=2026-10-01&hasta=2026-10-04", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // Nada de «$110 MXN»: los pesos por un lado y los dólares, aparte.
    expect($r['moneda'])->toBe('MXN')
        ->and($r['totales'])->toMatchArray(['ventas_minor' => 10000, 'cobrado_minor' => 10000, 'neto_minor' => 10000])
        ->and($r['otras_monedas'])->toBe([['moneda' => 'USD', 'ventas_minor' => 1000, 'cobrado_minor' => 1000, 'devuelto_minor' => 0, 'neto_minor' => 1000]]);
    // Vendido el día de la compra; cobrado el día del cobro.
    $porDia = collect($r['serie'])->keyBy('fecha');
    expect($porDia['2026-10-01'])->toMatchArray(['ventas_minor' => 10000, 'cobrado_minor' => 0])
        ->and($porDia['2026-10-04'])->toMatchArray(['ventas_minor' => 0, 'cobrado_minor' => 10000]);
});

it('cohortes: solo cuenta a los clientes, clases y compras de sus sucursales', function (): void {
    ['e' => $e, 'acotado' => $acotado] = negocioDosSedesReportes();
    $url = "/api/v1/app/{$e['slug']}/reportes/cohortes";

    $todo = $this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data.conversion');
    $suyo = $this->getJson($url, conBearer($acotado))->assertOk()->json('data.conversion');

    expect($todo['registrados'])->toBe(2)->and($todo['compraron'])->toBe(2)
        ->and($suyo['registrados'])->toBe(1)->and($suyo['compraron'])->toBe(1);
});

it('rentabilidad, equipo, demanda y sucursales: solo lo de sus sucursales; el dueño, todo', function (): void {
    ['e' => $e, 'acotado' => $acotado] = negocioDosSedesReportes();
    $periodo = '?desde=2026-10-01&hasta=2026-10-01';
    $leer = fn (string $reporte, string $bearer, string $ruta): mixed => $this->getJson("/api/v1/app/{$e['slug']}/reportes/{$reporte}{$periodo}", conBearer($bearer))
        ->assertOk()->json($ruta);

    expect($leer('rentabilidad', $e['bearer'], 'data.totales.sesiones'))->toBe(2)
        ->and($leer('rentabilidad', $acotado, 'data.totales.sesiones'))->toBe(1)
        ->and($leer('equipo', $e['bearer'], 'data.totales.clases'))->toBe(2)
        ->and($leer('equipo', $acotado, 'data.totales.clases'))->toBe(1)
        ->and($leer('demanda', $e['bearer'], 'data.totales.sesiones'))->toBe(2)
        ->and($leer('demanda', $acotado, 'data.totales.sesiones'))->toBe(1)
        ->and($leer('sucursales', $e['bearer'], 'data.totales.sucursales'))->toBe(2)
        ->and($leer('sucursales', $acotado, 'data.totales.sucursales'))->toBe(1)
        ->and($leer('sucursales', $acotado, 'data.totales.miembros_activos'))->toBe(1);
});
