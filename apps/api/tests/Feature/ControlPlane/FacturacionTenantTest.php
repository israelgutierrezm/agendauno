<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

// cargarDatosFiscales() vive en tests/Pest.php (compartido).

/**
 * @return array<string, mixed>
 */
function cfdiValido(): array
{
    return [
        'receptor' => [
            'nombre' => 'Cliente Final',
            'rfc' => 'XAXX010101000',
            'email' => 'cliente@correo.mx',
            'codigo_postal' => '06700',
        ],
        'uso_cfdi' => 'G03',
        'items' => [[
            'descripcion' => 'Pack 8 clases',
            'cantidad' => 2,
            'precio_unitario_minor' => 50000, // 500.00
            'clave_prod_serv' => '86121600',
            'clave_unidad' => 'E48',
        ]],
    ];
}

it('no permite facturar sin datos fiscales cargados', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    test()->postJson("/api/v1/app/{$e['slug']}/facturas", cfdiValido(), conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.datos_fiscales.0', 'Carga los datos fiscales del estudio antes de facturar.');
});

it('timbra un CFDI y calcula el desglose (subtotal + IVA 16%)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);

    $r = test()->postJson("/api/v1/app/{$e['slug']}/facturas", cfdiValido(), conBearer($e['bearer']))
        ->assertStatus(201)
        ->assertJsonPath('data.estado', 'timbrada');

    expect($r->json('data.subtotal_minor'))->toEqual(100000);
    expect($r->json('data.impuesto_minor'))->toEqual(16000);
    expect($r->json('data.total_minor'))->toEqual(116000);
    expect($r->json('data.uuid'))->not->toBeNull();
    expect($r->json('data.pdf_url'))->toContain('/pdf');

    // Aparece en el listado y se puede consultar.
    $id = (string) $r->json('data.id');
    test()->getJson("/api/v1/app/{$e['slug']}/facturas", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    test()->getJson("/api/v1/app/{$e['slug']}/facturas/{$id}", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.uuid', $r->json('data.uuid'));
});

it('registra la factura como error si el proveedor rechaza el timbrado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);

    // Proveedor que rechaza el timbre.
    app()->instance(ClienteFacturacion::class, new class implements ClienteFacturacion
    {
        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            throw new TimbradoFallido('RFC del receptor no valido.');
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });

    test()->postJson("/api/v1/app/{$e['slug']}/facturas", cfdiValido(), conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('data.estado', 'error')
        ->assertJsonPath('data.motivo_error', 'RFC del receptor no valido.');

    // Queda registrada (para reintento/auditoría).
    test()->getJson("/api/v1/app/{$e['slug']}/facturas", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
});

it('valida el receptor y los conceptos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);

    $malRfc = cfdiValido();
    $malRfc['receptor']['rfc'] = 'MALO';
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $malRfc, conBearer($e['bearer']))->assertStatus(422);

    $sinItems = cfdiValido();
    $sinItems['items'] = [];
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $sinItems, conBearer($e['bearer']))->assertStatus(422);
});

it('emitir factura exige permiso de gestion de ordenes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->postJson("/api/v1/app/{$e['slug']}/facturas", cfdiValido(), conBearer($coach))->assertForbidden();
});

it('con la tasa de la región fronteriza factura con IVA 8 %; otras tasas no se aceptan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $errores = test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['facturacion.iva_porcentaje' => 10]], conBearer($e['bearer']))
        ->assertStatus(422)->json('meta.errors');
    expect($errores['facturacion.iva_porcentaje'][0])->toBe('Tasa de IVA de las facturas: debe ser 16 o 8.');
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['facturacion.iva_porcentaje' => 8]], conBearer($e['bearer']))
        ->assertOk();

    $r = test()->postJson("/api/v1/app/{$e['slug']}/facturas", cfdiValido(), conBearer($e['bearer']))->assertStatus(201);
    expect($r->json('data.impuesto_minor'))->toEqual(8000)
        ->and($r->json('data.total_minor'))->toEqual(108000);
});
