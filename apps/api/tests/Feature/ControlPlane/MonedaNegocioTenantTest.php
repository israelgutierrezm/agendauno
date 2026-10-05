<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| La moneda del negocio (ADR 0097): un parámetro con las monedas del catálogo, pesos
| mexicanos si nadie la cambia. La plataforma fija la suya y cada negocio puede usar
| otra; lo nuevo (productos, nómina, inventario) la toma si no se indica, y una
| moneda fuera del catálogo no se acepta.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un producto sin decir su moneda y devuelve la que quedó.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function monedaDeProductoNuevo(array $e): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Paquete', 'tipo' => 'paquete', 'precio_minor' => 50000,
        'ilimitado' => false, 'creditos_incluidos' => 4000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.moneda');
}

it('sin cambiarla es el peso mexicano, y se ofrece el catálogo con nombre', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.moneda', 'MXN');
    expect(monedaDeProductoNuevo($e))->toBe('MXN');

    $parametro = collect($this->getJson("/api/v1/app/{$e['slug']}/parametros", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('clave', 'negocio.moneda');
    expect($parametro['plataforma'])->toBe(484)
        ->and($parametro['opciones'])->toContain(484, 840, 978)
        ->and($parametro['etiquetas']['484'])->toBe('MXN · Peso mexicano')
        ->and($parametro['etiquetas']['840'])->toBe('USD · Dólar estadounidense');
});

it('el negocio usa otra moneda del catálogo y lo nuevo la toma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['negocio.moneda' => 840]], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.moneda', 'USD');
    expect(monedaDeProductoNuevo($e))->toBe('USD');

    // Una sucursal puede usar otra, elegida del catálogo.
    $this->getJson("/api/v1/app/{$e['slug']}/sucursales", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('meta.moneda_negocio', 'USD')
        ->assertJsonPath('meta.monedas.0', ['codigo' => 'MXN', 'nombre' => 'Peso mexicano']);

    // Fuera del catálogo: ni como parámetro ni en un producto.
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['negocio.moneda' => 999]], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Otro', 'tipo' => 'paquete', 'precio_minor' => 100, 'moneda' => 'XYZ',
        'ilimitado' => false, 'creditos_incluidos' => 1000,
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['moneda'], 'meta.errors');
});

it('la plataforma fija la moneda de los negocios que no la cambiaron', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['negocio.moneda' => 978]], conPlataforma())
        ->assertOk();

    expect(monedaDeProductoNuevo($e))->toBe('EUR');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.moneda', 'EUR');
});
