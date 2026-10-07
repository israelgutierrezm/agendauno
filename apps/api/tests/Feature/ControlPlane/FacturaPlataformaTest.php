<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\EmitirFacturaPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\CargoRenta;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Activa Stripe en la plataforma (simulado), genera el cargo de renta, lo paga y lo
 * confirma por webhook. Devuelve el ulid del cargo PAGADO (listo para facturar).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cargoRentaPagado(array $e): string
{
    activarStripePlataforma();
    $cargo = cargoRentaPendiente($e);

    $ref = (string) test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))->assertCreated()->json('data.referencia');

    test()->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $ref]],
    ])->assertOk();

    return $cargo;
}

it('emite el CFDI de la renta de un cargo pagado (IVA incluido) y es idempotente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e); // cuota fija 149900

    $r = test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(201)
        ->assertJsonPath('data.estado', 'timbrada');

    $facturaId = (string) $r->json('data.id');
    expect($r->json('data.uuid'))->not->toBeNull();
    // 149900 es el TOTAL (IVA incluido): subtotal + impuesto cuadran exactamente.
    expect((int) $r->json('data.total_minor'))->toBe(149900);
    expect((int) $r->json('data.subtotal_minor') + (int) $r->json('data.impuesto_minor'))->toBe(149900);

    // Idempotente: re-emitir devuelve la MISMA factura (no timbra otra).
    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(201)->assertJsonPath('data.id', $facturaId);
});

it('no factura la renta sin datos fiscales cargados (FISCAL_DATA_REQUIRED)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cargo = cargoRentaPagado($e); // sin cargar datos fiscales

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'FISCAL_DATA_REQUIRED');
});

it('no factura un cargo de renta no pagado (RENT_CHARGE_NOT_INVOICEABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPendiente($e); // pendiente

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RENT_CHARGE_NOT_INVOICEABLE');
});

it('registra el error si el proveedor rechaza el timbre de la renta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e);

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

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('data.estado', 'error')
        ->assertJsonPath('data.motivo_error', 'RFC del receptor no valido.');
});

it('descarga el PDF y el XML de la factura de renta timbrada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e);

    $facturaId = (string) test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(201)->json('data.id');

    $pdf = test()->get("/api/v1/app/{$e['slug']}/renta/facturas/{$facturaId}/pdf", conBearer($e['bearer']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');

    test()->get("/api/v1/app/{$e['slug']}/renta/facturas/{$facturaId}/xml", conBearer($e['bearer']))
        ->assertOk();
});

it('facturar la renta exige permiso de facturación', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($coach))
        ->assertStatus(403);
});

it('el apartado de renta muestra la factura emitida del cargo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e);

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(201);

    test()->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.cargos.0.factura.estado', 'timbrada');
});

it('la factura de la renta lleva la tasa de IVA con que se cobró el cargo (su desglose)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPagado($e);
    // Un cargo por uso cobrado con otra tasa (la que fijó el superadmin en la tarifa).
    CargoRenta::query()->where('ulid', $cargo)->update([
        'modo_cobro' => 'activos',
        'monto_minor' => 108000,
        'desglose' => json_encode(['lineas' => [], 'subtotal_minor' => 100000, 'iva_porcentaje' => 8, 'iva_minor' => 8000, 'total_minor' => 108000]),
    ]);
    $proveedor = new class implements ClienteFacturacion
    {
        /** @var list<array<string, mixed>> */
        public array $enviadas = [];

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->enviadas[] = $factura;

            return new ResultadoTimbre('fac_iva8', 'uuid-iva8');
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    };
    app()->instance(ClienteFacturacion::class, $proveedor);

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/factura", [], conBearer($e['bearer']))
        ->assertStatus(201)
        ->assertJsonPath('data.subtotal_minor', 100000)
        ->assertJsonPath('data.impuesto_minor', 8000)
        ->assertJsonPath('data.total_minor', 108000);
    expect($proveedor->enviadas[0]['items'][0]['product']['taxes'])->toBe([['type' => 'IVA', 'rate' => 0.08]]);
});

it('sin tasa en el desglose (cargos anteriores) o con cuota fija, la renta se factura con IVA de 16 %', function (): void {
    $porUso = static fn (array $desglose): CargoRenta => new CargoRenta(['modo_cobro' => 'activos', 'desglose' => $desglose]);

    expect(EmitirFacturaPlataforma::ivaPorcentaje($porUso(['subtotal_minor' => 100, 'iva_porcentaje' => 0])))->toBe(0)
        ->and(EmitirFacturaPlataforma::ivaPorcentaje($porUso(['subtotal_minor' => 100])))->toBe(16)
        // La cuota fija se pacta con IVA incluido: su desglose no lo separa (0).
        ->and(EmitirFacturaPlataforma::ivaPorcentaje(new CargoRenta(['modo_cobro' => 'fijo', 'desglose' => ['iva_porcentaje' => 0]])))->toBe(16);
});

/*
| Recibo sin valor fiscal de la renta: el comprobante de quien paga la renta y no
| puede recibir la factura (otra moneda u otro país, ADR 0099). La web ofrece
| «Facturar» solo cuando la factura es posible (`factura_renta_posible`).
*/

it('descarga el recibo sin valor fiscal (PDF) de un cargo de renta pagado', function (): void {
    $e = estudioConSesion('recibo-a', 'a@recibo-a.mx');
    $cargo = cargoRentaPagado($e); // cuota fija 149900, IVA incluido

    $r = $this->get("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/recibo", conBearer($e['bearer']));

    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('application/pdf')
        ->and($r->headers->get('content-disposition'))->toContain('recibo-agendauno-');
    $pdf = (string) $r->getContent();
    expect($pdf)->toStartWith('%PDF-1.4')
        ->toContain('Recibo sin valor fiscal')
        ->toContain('Estudio recibo-a')
        ->toContain($cargo)
        // 1,499.00 con IVA de 16 % incluido: 1,292.24 + 206.76.
        ->toContain('1,292.24 MXN')
        ->toContain('206.76 MXN')
        ->toContain('1,499.00 MXN')
        // Acentos en WinAnsi (no se pierden ni se vuelven «?»).
        ->toContain(mb_convert_encoding('Suscripción AgendaUno', 'Windows-1252', 'UTF-8'));
});

it('no hay recibo de un cargo sin pagar, ni del cargo de otro negocio', function (): void {
    $a = estudioConSesion('recibo-a', 'a@recibo-a.mx');
    $b = estudioConSesion('recibo-b', 'b@recibo-b.mx');
    $pendiente = cargoRentaPendiente($a);

    $this->getJson("/api/v1/app/{$a['slug']}/renta/cargos/{$pendiente}/recibo", conBearer($a['bearer']))->assertStatus(409);
    $this->getJson("/api/v1/app/{$b['slug']}/renta/cargos/{$pendiente}/recibo", conBearer($b['bearer']))->assertNotFound();
});

it('el recibo de la renta exige el permiso de facturación', function (): void {
    $e = estudioConSesion('recibo-a', 'a@recibo-a.mx');
    $cargo = cargoRentaPagado($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@recibo-a.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/recibo", conBearer($coach))->assertForbidden();
});

it('la renta dice si el negocio puede recibir la factura: en pesos y en México, o con su RFC ya capturado', function (): void {
    $sinRfc = estudioConSesion('recibo-a', 'a@recibo-a.mx');
    $this->getJson("/api/v1/app/{$sinRfc['slug']}/renta", conBearer($sinRfc['bearer']))
        ->assertOk()->assertJsonPath('data.factura_renta_posible', true);

    // En dólares no captura datos fiscales: no hay factura, sí recibo.
    $this->putJson("/api/v1/app/{$sinRfc['slug']}/negocio/region", ['moneda' => 'USD'], conBearer($sinRfc['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$sinRfc['slug']}/renta", conBearer($sinRfc['bearer']))
        ->assertOk()->assertJsonPath('data.factura_renta_posible', false);

    // Con el RFC capturado antes de cambiar de moneda, la factura de la renta sigue.
    $conRfc = estudioConSesion('recibo-b', 'b@recibo-b.mx');
    cargarDatosFiscales($conRfc);
    $this->putJson("/api/v1/app/{$conRfc['slug']}/negocio/region", ['moneda' => 'USD'], conBearer($conRfc['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$conRfc['slug']}/renta", conBearer($conRfc['bearer']))
        ->assertOk()->assertJsonPath('data.factura_renta_posible', true);
});
