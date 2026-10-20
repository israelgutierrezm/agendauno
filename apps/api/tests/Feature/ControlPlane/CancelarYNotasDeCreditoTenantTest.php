<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\CancelacionCfdi;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Facturacion\FacturApiHttp;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Cancelar, sustituir y notas de crédito (ADR 0180). Cancelada, la venta de la factura
| se puede volver a facturar o anular; si el cliente debe aceptarla, queda en espera y
| el comando aplica lo que respondió. La sustituta toma la venta y se relaciona (04) con
| la que se cancela (01). La nota de crédito (egreso) se relaciona (01) con la factura
| y no pasa de lo facturado; mientras exista, la factura no se cancela.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un proveedor que anota lo que se timbra y lo que se cancela, y responde a cada
 * cancelación (y a cada consulta) lo que se le diga; si no, la acepta.
 *
 * @param  list<EstadoCancelacion>  $alCancelar
 * @param  list<EstadoCancelacion>  $alConsultar
 * @return array{timbradas: ArrayObject<int, array<string, mixed>>, canceladas: ArrayObject<int, array{id: string, motivo: string, sustituta: string|null}>}
 */
function proveedorCfdiQueAnota(array $alCancelar = [], array $alConsultar = []): array
{
    $timbradas = new ArrayObject;
    $canceladas = new ArrayObject;
    $proveedor = new class($timbradas, $canceladas, $alCancelar, $alConsultar) implements CancelacionCfdi, ClienteFacturacion
    {
        /**
         * @param  list<EstadoCancelacion>  $alCancelar
         * @param  list<EstadoCancelacion>  $alConsultar
         */
        public function __construct(
            private ArrayObject $timbradas,
            private ArrayObject $canceladas,
            private array $alCancelar,
            private array $alConsultar,
        ) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->timbradas->append($factura);

            return new ResultadoTimbre('fac_'.count($this->timbradas), 'UUID-'.count($this->timbradas));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }

        public function cancelar(string $llaveOrganizacion, string $facturaId, string $motivo, ?string $sustituta = null): EstadoCancelacion
        {
            $this->canceladas->append(['id' => $facturaId, 'motivo' => $motivo, 'sustituta' => $sustituta]);

            return array_shift($this->alCancelar) ?? EstadoCancelacion::Aceptada;
        }

        public function consultarCancelacion(string $llaveOrganizacion, string $facturaId): EstadoCancelacion
        {
            return array_shift($this->alConsultar) ?? EstadoCancelacion::Aceptada;
        }
    };
    app()->instance(ClienteFacturacion::class, $proveedor);
    app()->instance(CancelacionCfdi::class, $proveedor);

    return ['timbradas' => $timbradas, 'canceladas' => $canceladas];
}

/**
 * Un paquete de $899.00 cobrado en efectivo y facturado con su borrador.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{orden: string, pago: string, factura: string}
 */
function paqueteFacturadoParaCancelar(array $e): array
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');
    $borrador = test()->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))->assertOk()->json('data');
    $factura = (string) test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Ana López', 'rfc' => 'LOAA800101AB1', 'codigo_postal' => '06700', 'regimen_fiscal' => '605'],
        'uso_cfdi' => 'S01',
        'forma_pago' => $borrador['forma_pago'],
        'items' => $borrador['conceptos'],
        'orden_id' => $orden,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return ['orden' => $orden, 'pago' => $pago, 'factura' => $factura];
}

it('cancelada (02), su venta se puede volver a facturar y el cobro vuelve a ser facturable', function (): void {
    $e = estudioConSesion('cancela-cfdi', 'a@cancela-cfdi.mx');
    cargarDatosFiscales($e);
    $p = proveedorCfdiQueAnota();
    $v = paqueteFacturadoParaCancelar($e);
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $this->postJson("{$url}/{$v['factura']}/cancelar", ['motivo' => '01'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['motivo'], 'meta.errors');
    $this->postJson("{$url}/{$v['factura']}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'cancelada')
        ->assertJsonPath('data.cancelacion.estado', 'aceptada')
        ->assertJsonPath('data.cancelacion.motivo', '02')
        ->assertJsonPath('data.cancelable', false);
    expect($p['canceladas'][0])->toBe(['id' => 'fac_1', 'motivo' => '02', 'sustituta' => null]);

    $this->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk();
    $fila = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $v['pago']);
    expect($fila['factura'])->toBeNull()->and($fila['facturable'])->toBeTrue();
    expect($this->getJson("{$url}?estado=cancelada", conBearer($e['bearer']))->assertOk()->json('meta.conteos.cancelada'))->toBe(1);
});

it('si el cliente debe aceptarla queda en espera y el comando aplica lo que respondió', function (): void {
    $e = estudioConSesion('cancela-espera', 'a@cancela-espera.mx');
    cargarDatosFiscales($e);
    proveedorCfdiQueAnota(
        [EstadoCancelacion::Pendiente, EstadoCancelacion::Pendiente],
        [EstadoCancelacion::Aceptada, EstadoCancelacion::Rechazada],
    );
    $v = paqueteFacturadoParaCancelar($e);
    $otra = paqueteFacturadoParaCancelar($e);
    $url = "/api/v1/app/{$e['slug']}/facturas";

    foreach ([$v['factura'], $otra['factura']] as $factura) {
        $this->postJson("{$url}/{$factura}/cancelar", ['motivo' => '03'], conBearer($e['bearer']))
            ->assertOk()
            ->assertJsonPath('data.estado', 'timbrada')
            ->assertJsonPath('data.cancelacion.estado', 'pendiente')
            ->assertJsonPath('data.cancelable', false);
    }
    // En espera, la venta sigue facturada y no se pide otra vez.
    $this->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertStatus(422);
    $this->postJson("{$url}/{$v['factura']}/cancelar", ['motivo' => '03'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.factura.0', 'Su cancelación ya está en espera.');

    $this->artisan('agendauno:conciliar-cancelaciones-cfdi')->assertSuccessful();

    $this->getJson("{$url}/{$v['factura']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'cancelada')
        ->assertJsonPath('data.cancelacion.estado', 'aceptada');
    // Rechazada: sigue vigente, se dice por qué y se puede volver a pedir.
    $this->getJson("{$url}/{$otra['factura']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'timbrada')
        ->assertJsonPath('data.cancelacion.estado', 'rechazada')
        ->assertJsonPath('data.cancelacion.detalle', 'El cliente rechazó la cancelación.')
        ->assertJsonPath('data.cancelable', true);
    $this->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk();
});

it('la sustituta toma la venta, se relaciona (04) y la vieja se cancela con ella (01)', function (): void {
    $e = estudioConSesion('sustituye-cfdi', 'a@sustituye-cfdi.mx');
    cargarDatosFiscales($e);
    $p = proveedorCfdiQueAnota();
    $v = paqueteFacturadoParaCancelar($e);
    $url = "/api/v1/app/{$e['slug']}/facturas";

    // El borrador trae lo que se timbró, para corregir lo que estaba mal.
    $borrador = $this->getJson("{$url}/borrador?sustituye={$v['factura']}", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.receptor.rfc', 'LOAA800101AB1')
        ->assertJsonPath('data.receptor.regimen_fiscal', '605')
        ->assertJsonPath('data.conceptos.0.precio_unitario_minor', 77500)
        ->json('data');

    // Sustituir también cancela: sin el permiso de devoluciones, no.
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@sustituye-cfdi.mx', 'recepcionista');
    $cuerpo = [
        'receptor' => ['nombre' => 'Ana López Ávila', 'rfc' => 'LOAA800101AB2', 'codigo_postal' => '06700', 'regimen_fiscal' => '605'],
        'uso_cfdi' => 'S01', 'forma_pago' => $borrador['forma_pago'], 'items' => $borrador['conceptos'],
        'sustituye' => $v['factura'],
    ];
    $this->postJson($url, $cuerpo, conBearer($recep))->assertForbidden();

    $nueva = $this->postJson($url, $cuerpo, conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.orden_id', $v['orden'])
        ->assertJsonPath('data.relacionada.id', $v['factura'])
        ->assertJsonPath('data.relacionada.relacion', '04')
        ->json('data');
    expect($p['timbradas'][1]['related_documents'])->toBe([['relationship' => '04', 'documents' => ['UUID-1']]])
        ->and($p['canceladas'][0])->toBe(['id' => 'fac_1', 'motivo' => '01', 'sustituta' => 'UUID-2']);
    $this->getJson("{$url}/{$v['factura']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'cancelada');

    // El cobro dice la sustituta.
    $fila = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('id', $v['pago']);
    expect($fila['factura']['id'])->toBe($nueva['id']);
});

it('la nota de crédito se relaciona (01), no pasa de lo facturado y mientras exista la factura no se cancela', function (): void {
    $e = estudioConSesion('nota-credito', 'a@nota-credito.mx');
    cargarDatosFiscales($e);
    $p = proveedorCfdiQueAnota();
    $url = "/api/v1/app/{$e['slug']}/facturas";
    // Una factura sin régimen del receptor: $1,000 + IVA = $1,160.
    $factura = (string) $this->postJson($url, [
        'receptor' => ['nombre' => 'Cliente Final', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'S01',
        'items' => [['descripcion' => 'Pack 8 clases', 'cantidad' => 2, 'precio_unitario_minor' => 50000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.acreditable_minor', 116000)->json('data.id');

    $nota = ['monto_minor' => 58000, 'descripcion' => 'Devolución de una clase', 'forma_pago' => '01'];
    $this->postJson("{$url}/{$factura}/notas-credito", $nota, conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['regimen_fiscal'], 'meta.errors');
    $creada = $this->postJson("{$url}/{$factura}/notas-credito", $nota + ['regimen_fiscal' => '616'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'nota_de_credito')
        ->assertJsonPath('data.subtotal_minor', 50000)
        ->assertJsonPath('data.total_minor', 58000)
        ->assertJsonPath('data.relacionada.id', $factura)
        ->assertJsonPath('data.relacionada.relacion', '01')
        ->json('data');
    expect($p['timbradas'][1])->toMatchArray([
        'type' => 'E', 'use' => 'G02', 'payment_form' => '01',
        'related_documents' => [['relationship' => '01', 'documents' => ['UUID-1']]],
    ])->and($p['timbradas'][1]['customer']['tax_system'])->toBe('616')
        ->and($p['timbradas'][1]['items'][0]['product'])->toMatchArray([
            'description' => 'Devolución de una clase', 'product_key' => '84111506', 'unit_key' => 'ACT', 'price' => 500,
        ]);

    // Queda la mitad por acreditar (y el régimen del cliente, guardado); más, no.
    $this->getJson("{$url}/{$factura}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.acreditable_minor', 58000)
        ->assertJsonPath('data.regimen_receptor', '616')
        ->assertJsonPath('data.cancelable', false);
    $this->postJson("{$url}/{$factura}/notas-credito", ['monto_minor' => 60000, 'forma_pago' => '01'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['monto_minor'], 'meta.errors');

    // Con la nota vigente la factura no se cancela; cancelada la nota, sí.
    $this->postJson("{$url}/{$factura}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.factura.0', 'Tiene notas de crédito vigentes: cancélalas antes.');
    $this->postJson("{$url}/{$creada['id']}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
    $this->postJson("{$url}/{$factura}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('una nota por el total acredita el total (el IVA se redondea como en el CFDI)', function (): void {
    $e = estudioConSesion('nota-exacta', 'a@nota-exacta.mx');
    cargarDatosFiscales($e);
    proveedorCfdiQueAnota();
    $url = "/api/v1/app/{$e['slug']}/facturas";
    // $100.04 + IVA: 16.0064 se redondea a 16.01 (como el CFDI), total $116.05.
    $factura = (string) $this->postJson($url, [
        'receptor' => ['nombre' => 'Cliente Final', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700', 'regimen_fiscal' => '616'],
        'uso_cfdi' => 'S01',
        'items' => [['descripcion' => 'Clase', 'cantidad' => 1, 'precio_unitario_minor' => 10004, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.impuesto_minor', 1601)->assertJsonPath('data.total_minor', 11605)->json('data.id');

    $this->postJson("{$url}/{$factura}/notas-credito", ['monto_minor' => 11605, 'forma_pago' => '01'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 11605);
    $this->getJson("{$url}/{$factura}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.acreditable_minor', 0);
});

it('con la cancelación en espera no se ofrecen notas de crédito', function (): void {
    $e = estudioConSesion('nota-espera', 'a@nota-espera.mx');
    cargarDatosFiscales($e);
    proveedorCfdiQueAnota([EstadoCancelacion::Pendiente]);
    $v = paqueteFacturadoParaCancelar($e);
    $url = "/api/v1/app/{$e['slug']}/facturas/{$v['factura']}";

    $this->postJson("{$url}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.acreditable_minor', 0);
    $this->postJson("{$url}/notas-credito", ['monto_minor' => 1000, 'forma_pago' => '01'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.factura.0', 'Su cancelación está en espera: no se le hacen notas de crédito.');
});

it('un anticipo aplicado no se cancela; cancelar el resto lo deja por aplicar', function (): void {
    $e = estudioConSesion('anticipo-cancela', 'a@anticipo-cancela.mx');
    cargarDatosFiscales($e);
    proveedorCfdiQueAnota();
    $url = "/api/v1/app/{$e['slug']}/facturas";
    $receptor = ['nombre' => 'Cliente Final', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'];
    $anticipo = (string) $this->postJson($url, [
        'receptor' => $receptor, 'uso_cfdi' => 'S01', 'anticipo' => true,
        'items' => [['descripcion' => 'Anticipo', 'cantidad' => 1, 'precio_unitario_minor' => 20000, 'clave_prod_serv' => '84111506', 'clave_unidad' => 'ACT']],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.acreditable_minor', 0)->json('data.id');
    $resto = (string) $this->postJson($url, [
        'receptor' => $receptor, 'uso_cfdi' => 'S01', 'anticipos' => [$anticipo],
        'items' => [['descripcion' => 'Corte', 'cantidad' => 1, 'precio_unitario_minor' => 30000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("{$url}/{$anticipo}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.factura.0', 'Este anticipo ya se aplicó: cancela antes la factura que lo aplica.');
    $this->postJson("{$url}/{$resto}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))->assertOk();
    $this->getJson("{$url}?anticipos_de=XAXX010101000", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $anticipo);
});

it('quien no puede devolver no cancela ni hace notas de crédito', function (): void {
    $e = estudioConSesion('cancela-permiso', 'a@cancela-permiso.mx');
    cargarDatosFiscales($e);
    proveedorCfdiQueAnota();
    $v = paqueteFacturadoParaCancelar($e);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@cancela-permiso.mx', 'recepcionista');
    $url = "/api/v1/app/{$e['slug']}/facturas/{$v['factura']}";

    $this->postJson("{$url}/cancelar", ['motivo' => '02'], conBearer($recep))->assertForbidden();
    $this->postJson("{$url}/notas-credito", ['monto_minor' => 1000, 'forma_pago' => '01'], conBearer($recep))->assertForbidden();
});

it('con FacturAPI: pide la cancelación con su motivo y lee cómo va', function (): void {
    Http::fake([
        '*/invoices/fac_1?*' => Http::sequence()
            ->push(['status' => 'canceled', 'cancellation_status' => 'accepted'])
            ->push(['status' => 'valid', 'cancellation_status' => 'pending'])
            ->push(['status' => 'valid', 'cancellation_status' => 'verifying'])
            ->push(['message' => 'La factura no está vigente.'], 400),
        '*/invoices/fac_1' => Http::sequence()
            ->push(['status' => 'valid', 'cancellation_status' => 'rejected'])
            ->push(['status' => 'valid', 'cancellation_status' => 'expired']),
    ]);
    $api = new FacturApiHttp('https://www.facturapi.io/v2');

    expect($api->cancelar('llave', 'fac_1', '01', 'UUID-2'))->toBe(EstadoCancelacion::Aceptada)
        ->and($api->cancelar('llave', 'fac_1', '02'))->toBe(EstadoCancelacion::Pendiente)
        ->and($api->cancelar('llave', 'fac_1', '03'))->toBe(EstadoCancelacion::Pendiente)
        ->and(fn () => $api->cancelar('llave', 'fac_1', '02'))->toThrow(TimbradoFallido::class, 'La factura no está vigente.')
        ->and($api->consultarCancelacion('llave', 'fac_1'))->toBe(EstadoCancelacion::Rechazada)
        ->and($api->consultarCancelacion('llave', 'fac_1'))->toBe(EstadoCancelacion::Vencida);
    Http::assertSent(fn (PeticionHttp $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://www.facturapi.io/v2/invoices/fac_1?motive=01&substitution=UUID-2');
    Http::assertSent(fn (PeticionHttp $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://www.facturapi.io/v2/invoices/fac_1?motive=02');
});
