<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\FacturacionFalsa;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Support\Facades\File;

/*
| En producción sin la llave de FacturAPI no se factura: ni a los clientes del
| negocio ni la renta. Antes se usaba el proveedor falso y salían CFDI simulados.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('en producción sin llave de FacturAPI no factura ni lo ofrece; fuera de producción sigue el proveedor de pruebas', function (): void {
    config(['agendauno.facturapi.llave' => null]);
    expect(app(ClienteFacturacion::class))->toBeInstanceOf(FacturacionFalsa::class);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(ClienteFacturacion::class))->toBeInstanceOf(FacturacionNoConfigurada::class);
    test()->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.facturacion_disponible', false);
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Cliente Final', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'G03',
        'items' => [['descripcion' => 'Paquete 8 clases', 'cantidad' => 1, 'precio_unitario_minor' => 120000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.facturacion.0', FacturacionNoConfigurada::MOTIVO);
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->sole(),
        fn () => expect(FacturaTenant::query()->count())->toBe(0),
    );

    // Con la llave, se vuelve a facturar con el proveedor real.
    config(['agendauno.facturapi.llave' => 'sk_test_llave']);
    test()->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.facturacion_disponible', true);
});

it('la verificación de producción avisa que sin llave de FacturAPI no hay facturas', function (): void {
    config(['agendauno.facturapi.llave' => null]);

    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('AVISO Llave de FacturAPI (CFDI)');
});

it('en producción sin llave de FacturAPI no se venden timbres', function (): void {
    config(['agendauno.facturapi.llave' => null]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    app()->detectEnvironment(fn (): string => 'production');

    test()->getJson("/api/v1/app/{$e['slug']}/timbres", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.posible', false)
        ->assertJsonPath('data.motivo', FacturacionNoConfigurada::MOTIVO);
    test()->postJson("/api/v1/app/{$e['slug']}/timbres/comprar", ['cantidad' => 50], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.cantidad.0', FacturacionNoConfigurada::MOTIVO);
});
