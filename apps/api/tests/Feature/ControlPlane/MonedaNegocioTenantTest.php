<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| La región del negocio (ADR 0099): UNA moneda para todo el negocio (pesos mexicanos
| por omisión), que se elige antes de empezar a cobrar, y su zona horaria (la de la
| Ciudad de México por omisión). Con pesos mexicanos funcionan las pasarelas en línea
| y la facturación (esta, solo en México); con otra moneda, ni se cargan sus datos.
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

/**
 * Cambia la moneda o la zona horaria del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, string>  $datos
 */
function cambiarRegionNegocio(array $e, array $datos): TestResponse
{
    return test()->putJson("/api/v1/app/{$e['slug']}/negocio/region", $datos, conBearer($e['bearer']));
}

it('por omisión trabaja en pesos mexicanos y con la hora de la Ciudad de México', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.moneda', 'MXN')
        ->assertJsonPath('data.zona_horaria', 'America/Mexico_City')
        ->assertJsonPath('data.puede_cambiar_moneda', true)
        ->assertJsonPath('data.pasarelas.disponibles', true)
        ->assertJsonPath('data.facturacion.disponible', true)
        ->assertJsonPath('data.monedas.0', ['codigo' => 'MXN', 'nombre' => 'Peso mexicano']);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.moneda', 'MXN')
        ->assertJsonPath('data.estudio.zona_horaria', 'America/Mexico_City');
    expect(monedaDeProductoNuevo($e))->toBe('MXN');
});

it('una sola moneda: lo creado pasa a la nueva y no se acepta otra', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $antes = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual', 'tipo' => 'paquete', 'precio_minor' => 99900,
        'ilimitado' => false, 'creditos_incluidos' => 8000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    cambiarRegionNegocio($e, ['moneda' => 'usd'])->assertOk()->assertJsonPath('data.moneda', 'USD');

    // Lo ya creado (sin ventas) pasa a la nueva moneda; lo nuevo, también.
    $productos = collect($this->getJson("/api/v1/app/{$e['slug']}/productos", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($productos->firstWhere('id', $antes)['moneda'])->toBe('USD')
        ->and(monedaDeProductoNuevo($e))->toBe('USD');
    // Otra moneda no se acepta, ni fuera del catálogo.
    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'En pesos', 'tipo' => 'paquete', 'precio_minor' => 100, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 1000,
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['moneda'], 'meta.errors');
    cambiarRegionNegocio($e, ['moneda' => 'XYZ'])->assertUnprocessable();
});

it('con cobros registrados, la moneda ya no cambia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.puede_cambiar_moneda', false);
    cambiarRegionNegocio($e, ['moneda' => 'USD'])
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.moneda.0', 'Ya hay cobros en MXN: la moneda se elige antes de empezar a cobrar.');
});

it('fuera de pesos mexicanos no se cobra en línea ni se factura, ni se cargan sus datos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // En pesos, una pasarela conectada cobra.
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.lista', true);

    cambiarRegionNegocio($e, ['moneda' => 'EUR'])
        ->assertOk()
        ->assertJsonPath('data.pasarelas.disponibles', false)
        ->assertJsonPath('data.facturacion.disponible', false);

    // La pasarela que ya estaba deja de cobrar y no se cargan llaves nuevas.
    $pasarelas = $this->getJson("/api/v1/app/{$e['slug']}/pasarelas", conBearer($e['bearer']))->assertOk();
    expect(collect($pasarelas->json('data'))->firstWhere('proveedor', 'stripe')['lista'])->toBeFalse()
        ->and($pasarelas->json('meta.en_linea_disponible'))->toBeFalse();
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/mercadopago", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['access_token' => 'x'],
    ], conBearer($e['bearer']))->assertUnprocessable()
        ->assertJsonPath('meta.errors.proveedor.0', 'Las pasarelas de pago en línea solo funcionan con pesos mexicanos (MXN).');

    // Facturación: ni datos fiscales ni facturas.
    $this->getJson("/api/v1/app/{$e['slug']}/datos-fiscales", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.disponible', false);
    $this->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Estudio Demo SA de CV', 'rfc' => 'ABC010101AB9', 'regimen_fiscal' => '601', 'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertUnprocessable()
        ->assertJsonPath('meta.errors.facturacion.0', 'La facturación a tus clientes solo funciona en pesos mexicanos (MXN) y para negocios en México.');
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", [], conBearer($e['bearer']))->assertUnprocessable();
});

it('la facturación es solo para negocios en México', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Estudio::query()->where('slug', $e['slug'])->update(['pais' => 'CO']);

    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.pasarelas.disponibles', true)
        ->assertJsonPath('data.facturacion.disponible', false);
});

it('elige su zona horaria; una que no existe no se acepta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    cambiarRegionNegocio($e, ['zona_horaria' => 'America/Tijuana'])
        ->assertOk()->assertJsonPath('data.zona_horaria', 'America/Tijuana');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.zona_horaria', 'America/Tijuana');
    expect(Estudio::query()->where('slug', $e['slug'])->value('zona_horaria'))->toBe('America/Tijuana');

    cambiarRegionNegocio($e, ['zona_horaria' => 'Marte/Olympus'])->assertUnprocessable();
});
