<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\FacturacionSinRespuesta;
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
        ->assertJsonPath('meta.errors.datos_fiscales.0', 'Carga los datos fiscales del negocio antes de facturar.');
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

it('el uso del CFDI y la forma de pago salen del catálogo del SAT y concuerdan con el RFC', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $inventado = cfdiValido();
    $inventado['uso_cfdi'] = 'Z99';
    $inventado['forma_pago'] = '77';
    $this->postJson($url, $inventado, conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['uso_cfdi', 'forma_pago'], 'meta.errors');

    // Colegiaturas (D10) son solo de personas físicas; este RFC (12) es de persona moral.
    $moral = cfdiValido();
    $moral['receptor']['rfc'] = 'ESO800101AB1';
    $moral['uso_cfdi'] = 'D10';
    $this->postJson($url, $moral, conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['uso_cfdi'], 'meta.errors');
});

it('factura un anticipo con el concepto del SAT y el resto lo relaciona (tipo 07), una sola vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    // Proveedor que guarda lo que se le manda a timbrar.
    $enviadas = new ArrayObject;
    app()->instance(ClienteFacturacion::class, new class($enviadas) implements ClienteFacturacion
    {
        public function __construct(private ArrayObject $enviadas) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->enviadas->append($factura);

            return new ResultadoTimbre('fac_'.count($this->enviadas), 'UUID-'.count($this->enviadas));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });

    // El anticipo: un solo concepto con su importe; clave, unidad y descripción del SAT.
    $anticipo = cfdiValido();
    $anticipo['anticipo'] = true;
    $anticipo['items'] = [['descripcion' => 'Lo que sea', 'cantidad' => 3, 'precio_unitario_minor' => 20000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']];
    $idAnticipo = (string) test()->postJson("/api/v1/app/{$e['slug']}/facturas", $anticipo, conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.anticipo', true)->assertJsonPath('data.subtotal_minor', 20000)->json('data.id');
    expect($enviadas[0]['items'][0])->toMatchArray(['quantity' => 1])
        ->and($enviadas[0]['items'][0]['product'])->toMatchArray([
            'description' => 'Anticipo del bien o servicio', 'product_key' => '84111506', 'unit_key' => 'ACT', 'price' => 200,
        ]);

    // Aparece como anticipo por aplicar de ese RFC.
    test()->getJson("/api/v1/app/{$e['slug']}/facturas?anticipos_de=xaxx010101000", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $idAnticipo);

    // El resto: relacionado con el anticipo y con la leyenda del remanente.
    $resto = cfdiValido();
    $resto['anticipos'] = [$idAnticipo];
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $resto, conBearer($e['bearer']))->assertCreated();
    expect($enviadas[1]['related_documents'])->toBe([['relationship' => '07', 'documents' => ['UUID-1']]])
        ->and($enviadas[1]['items'][0]['product']['description'])->toBe('Pack 8 clases - CFDI por remanente de un anticipo');
    test()->getJson("/api/v1/app/{$e['slug']}/facturas?anticipos_de=XAXX010101000", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    // Ya aplicado no se vuelve a aplicar (ni se gasta otro timbre).
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $resto, conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['anticipos'], 'meta.errors');
    expect($enviadas)->toHaveCount(2);
});

it('un anticipo de otro RFC no se puede aplicar y la factura de un anticipo lleva un solo concepto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $anticipo = cfdiValido();
    $anticipo['anticipo'] = true;
    $idAnticipo = (string) test()->postJson("/api/v1/app/{$e['slug']}/facturas", $anticipo, conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $otro = cfdiValido();
    $otro['receptor']['rfc'] = 'GODE561231GR8';
    $otro['anticipos'] = [$idAnticipo];
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $otro, conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['anticipos'], 'meta.errors');

    $dos = cfdiValido();
    $dos['anticipo'] = true;
    $dos['items'][] = $dos['items'][0];
    test()->postJson("/api/v1/app/{$e['slug']}/facturas", $dos, conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['items'], 'meta.errors');
});

it('si FacturAPI no responde, el reintento lleva la misma llave y registra una sola factura; otra igual después, otra llave', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    // FacturAPI que no responde la primera vez (pero sí timbra) y, con la misma llave,
    // devuelve la misma factura.
    $enviadas = new ArrayObject;
    app()->instance(ClienteFacturacion::class, new class($enviadas) implements ClienteFacturacion
    {
        public function __construct(private ArrayObject $enviadas) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->enviadas->append($factura);
            if (count($this->enviadas) === 1) {
                throw new FacturacionSinRespuesta;
            }

            return new ResultadoTimbre('fac_'.md5((string) $factura['idempotency_key']), 'UUID-'.md5((string) $factura['idempotency_key']));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $this->postJson($url, cfdiValido(), conBearer($e['bearer']))->assertStatus(503)->assertJsonPath('code', 'INVOICING_NO_RESPONSE');
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');

    $this->postJson($url, cfdiValido(), conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.estado', 'timbrada');
    expect($enviadas[1]['idempotency_key'])->toBe($enviadas[0]['idempotency_key']);

    // Otra factura igual (otra venta del mismo cliente): otra llave, otra factura.
    $this->postJson($url, cfdiValido(), conBearer($e['bearer']))->assertCreated();
    expect($enviadas[2]['idempotency_key'])->not->toBe($enviadas[1]['idempotency_key']);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(2, 'data');
});
