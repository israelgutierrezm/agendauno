<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\EnviarFacturaPorCorreo;
use App\Modules\Tenancy\Facturacion\FacturApiHttp;
use App\Modules\Tenancy\Facturacion\OrganizacionesCfdi;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request as PeticionSaliente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| Cada negocio factura con su propia organización de FacturAPI (ADR 0212): al subir su
| sello se da de alta (organización, datos fiscales, CSD y su llave), timbra con su
| llave y no con la de la plataforma, y el IVA de cada concepto sale de lo vendido.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function subirSelloPrueba(array $e, string $contrasena = 'clave-csd'): TestResponse
{
    return test()->post("/api/v1/app/{$e['slug']}/datos-fiscales/sello", [
        'certificado' => UploadedFile::fake()->createWithContent('sello.cer', 'CERTIFICADO'),
        'llave' => UploadedFile::fake()->createWithContent('sello.key', 'LLAVE'),
        'password' => $contrasena,
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json']);
}

/** Lo que guardó el negocio de su alta en FacturAPI. */
function datosFiscalesDe(string $slug): DatosFiscalesTenant
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $slug)->sole(),
        fn (): DatosFiscalesTenant => DatosFiscalesTenant::query()->sole(),
    );
}

it('al subir su sello el negocio se da de alta en FacturAPI y timbra con su propia llave', function (): void {
    Config::set('agendauno.facturapi.llave_usuario', 'sk_user_plataforma');
    Config::set('agendauno.facturapi.llave', 'sk_live_de_la_plataforma');
    app()->instance(OrganizacionesCfdi::class, new FacturApiHttp('https://www.facturapi.io/v2'));
    Http::fake([
        'www.facturapi.io/v2/organizations' => Http::response(['id' => 'org_123']),
        'www.facturapi.io/v2/organizations/org_123/legal' => Http::response(['id' => 'org_123']),
        'www.facturapi.io/v2/organizations/org_123/certificate' => Http::response(['id' => 'org_123']),
        'www.facturapi.io/v2/organizations/org_123/apikeys/live' => Http::response('"sk_live_del_negocio"'),
        'www.facturapi.io/v2/invoices' => Http::response(['id' => 'inv_1', 'uuid' => 'uuid-1']),
        'www.facturapi.io/v2/organizations/org_123' => Http::response([
            'id' => 'org_123', 'is_production_ready' => false,
            'pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma la carta manifiesto']],
        ]),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);

    $r = subirSelloPrueba($e)->assertOk()
        ->assertJsonPath('data.sellos_cargados', true)
        ->assertJsonPath('data.facturacion_lista', false)
        ->assertJsonPath('data.pendientes.0', 'Firma la carta manifiesto');
    expect($r->json('data.portal_manifiesto'))->toContain('facturapi.io/manifiesto');
    // Nunca se devuelve la llave.
    expect(json_encode($r->json()))->not->toContain('sk_live_del_negocio');

    Http::assertSent(fn (PeticionSaliente $p): bool => str_ends_with($p->url(), '/organizations') && $p->hasHeader('Authorization', 'Bearer sk_user_plataforma'));
    Http::assertSent(fn (PeticionSaliente $p): bool => str_ends_with($p->url(), '/organizations/org_123/legal')
        && $p['legal_name'] === 'Estudio Demo SA de CV' && $p['tax_system'] === '601' && $p['address']['zip'] === '06700');
    Http::assertSent(fn (PeticionSaliente $p): bool => str_ends_with($p->url(), '/organizations/org_123/certificate') && $p->isMultipart());
    $datos = datosFiscalesDe($e['slug']);
    expect($datos->facturapi_organizacion_id)->toBe('org_123')
        ->and($datos->facturapi_llave)->toBe('sk_live_del_negocio');

    // Timbra con la llave del negocio, no con la de la plataforma (otro emisor).
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Cliente', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'G03',
        'items' => [['descripcion' => 'Clase', 'cantidad' => 1, 'precio_unitario_minor' => 20000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))->assertCreated();
    Http::assertSent(fn (PeticionSaliente $p): bool => str_ends_with($p->url(), '/invoices') && $p->hasHeader('Authorization', 'Bearer sk_live_del_negocio'));
    Http::assertNotSent(fn (PeticionSaliente $p): bool => $p->hasHeader('Authorization', 'Bearer sk_live_de_la_plataforma'));
});

it('un sello que FacturAPI rechaza no se guarda y se dice por qué', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);

    subirSelloPrueba($e, '')->assertUnprocessable();
    expect(datosFiscalesDe($e['slug'])->sellosCargados())->toBeFalse();
});

it('cada concepto lleva el IVA de lo vendido: un servicio exento no suma IVA', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    subirSelloPrueba($e)->assertOk()->assertJsonPath('data.facturacion_lista', true);

    $r = test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Paciente', 'rfc' => 'XAXX010101000', 'email' => 'paciente@correo.mx', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'G03',
        'items' => [
            ['descripcion' => 'Consulta', 'cantidad' => 1, 'precio_unitario_minor' => 50000, 'clave_prod_serv' => '85121800', 'clave_unidad' => 'E48', 'iva' => 'exento'],
            ['descripcion' => 'Crema', 'cantidad' => 1, 'precio_unitario_minor' => 10000, 'clave_prod_serv' => '01010101', 'clave_unidad' => 'H87'],
        ],
    ], conBearer($e['bearer']))->assertCreated();

    // Solo la crema lleva IVA (16 % de 100.00).
    expect($r->json('data.subtotal_minor'))->toBe(60000)
        ->and($r->json('data.impuesto_minor'))->toBe(1600)
        ->and($r->json('data.total_minor'))->toBe(61600);
});

it('el equipo descarga el PDF y el XML, los reenvía por correo y el cliente los ve en Mis facturas', function (): void {
    Queue::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    subirSelloPrueba($e)->assertOk();
    activarCobroEnLinea($e);
    $pack = crearPackTenant($e, 8000);
    $cliente = alumnoConSesion($e);
    $persona = (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
        'liquidar' => ['metodo' => 'efectivo'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $borrador = test()->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))->assertOk()->json('data');
    $factura = (string) test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Vale', 'rfc' => 'XAXX010101000', 'email' => 'vale@correo.mx', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'G03', 'items' => $borrador['conceptos'], 'orden_id' => $orden,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    test()->get("/api/v1/app/{$e['slug']}/facturas/{$factura}/pdf", conBearer($e['bearer']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    test()->postJson("/api/v1/app/{$e['slug']}/facturas/{$factura}/enviar", ['email' => 'contabilidad@correo.mx'], conBearer($e['bearer']))
        ->assertStatus(202)->assertJsonPath('data.enviada_a', 'contabilidad@correo.mx');
    Queue::assertPushed(EnviarFacturaPorCorreo::class, fn (EnviarFacturaPorCorreo $j): bool => $j->correo === 'contabilidad@correo.mx');

    test()->getJson("/api/v1/app/{$e['slug']}/mi/facturas", conBearer($cliente['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $factura);
    test()->get("/api/v1/app/{$e['slug']}/mi/facturas/{$factura}/xml", conBearer($cliente['bearer']))
        ->assertOk()->assertHeader('Content-Type', 'application/xml');
    // La de otra persona no.
    $otro = alumnoConSesion($e, 'Beto', 'beto@correo.mx');
    test()->get("/api/v1/app/{$e['slug']}/mi/facturas/{$factura}/pdf", conBearer($otro['bearer']))->assertNotFound();
});
