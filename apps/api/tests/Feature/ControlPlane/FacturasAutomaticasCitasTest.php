<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Facturas automáticas de citas: a quien guardó sus datos fiscales y pidió factura
| automática, en un negocio de citas que la encendió. Con anticipo: su CFDI de anticipo
| al pagarlo y, al pagar el resto, la factura de la operación completa relacionada con
| el anticipo (07) y el CFDI de egreso que lo aplica (forma de pago 30).
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El proveedor de facturación de la prueba: guarda lo que se le manda a timbrar. Con
 * `$fallarEgresos`, los CFDI de egreso no se timbran hasta que se apague.
 */
function timbradoDeFacturasAutomaticas(ArrayObject $enviadas, ArrayObject $fallarEgresos): void
{
    app()->instance(ClienteFacturacion::class, new class($enviadas, $fallarEgresos) implements ClienteFacturacion
    {
        public function __construct(private ArrayObject $enviadas, private ArrayObject $fallarEgresos) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            if (($factura['type'] ?? 'I') === 'E' && count($this->fallarEgresos) > 0) {
                throw new TimbradoFallido('El SAT no respondió.');
            }
            $this->enviadas->append($factura);

            return new ResultadoTimbre('fac_'.count($this->enviadas), 'UUID-'.count($this->enviadas));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });
}

/**
 * Una barbería que cobra en línea, con un corte de $250 y $100 de anticipo, que factura
 * las citas en automático; Beto agenda en su página y guarda sus datos fiscales.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, cita: array<string, mixed>, beto: string}
 */
function barberiaQueFacturaSola(bool $automatica = true): array
{
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'], 200)]);
    $e = estudioConSesion('barberia-fact', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30, 'anticipo_minor' => 10000,
    ], conBearer($e['bearer']))->assertOk();
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    activarCobroEnLinea($e);
    cargarDatosFiscales($e);
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['facturacion.automatica_citas' => 1]], conBearer($e['bearer']))->assertOk();

    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    $cita = test()->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => "{$dia} 10:00:00", 'duracion_minutos' => 30,
    ])->assertCreated()->json('data');
    $beto = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Beto", conBearer($e['bearer']))->json('data.0.id');
    test()->putJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/datos-fiscales", [
        'rfc' => 'gode561231gr8', 'nombre' => 'Alberto Gómez Delgado', 'codigo_postal' => '06700',
        'regimen_fiscal' => '612', 'uso_cfdi' => 'G03', 'email' => 'beto@correo.mx', 'factura_automatica' => $automatica,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.rfc', 'GODE561231GR8')->assertJsonPath('meta.automatica_disponible', true);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'cita' => $cita, 'beto' => $beto];
}

/** Beto paga su anticipo en línea (Stripe completa el cobro) y corren los avisos. */
function betoPagaSuAnticipo(string $slug, string $orden): void
{
    test()->postJson("/api/v1/app/{$slug}/citas/pagar", ['orden_id' => $orden])->assertCreated();
    test()->postJson("/api/v1/webhooks/tenant/{$slug}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
}

/** Las facturas del negocio de la prueba. */
function facturasDeLaBarberia(): Collection
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'barberia-fact')->sole(),
        fn () => FacturaTenant::query()->orderBy('id')->get(),
    );
}

it('factura el anticipo al pagarlo y, al cobrar el resto, la operación completa y el egreso que aplica el anticipo', function (): void {
    $enviadas = new ArrayObject;
    timbradoDeFacturasAutomaticas($enviadas, new ArrayObject);
    ['e' => $e, 'cita' => $cita] = barberiaQueFacturaSola();

    // El anticipo ($100 con IVA): su CFDI de anticipo, con los datos de Beto.
    betoPagaSuAnticipo($e['slug'], (string) $cita['orden_id']);
    expect($enviadas)->toHaveCount(1)
        ->and($enviadas[0]['customer'])->toMatchArray(['tax_id' => 'GODE561231GR8', 'legal_name' => 'ALBERTO GÓMEZ DELGADO', 'tax_system' => '612'])
        ->and($enviadas[0]['use'])->toBe('G03')
        ->and($enviadas[0]['items'][0]['product'])->toMatchArray(['description' => 'Anticipo del bien o servicio', 'product_key' => '84111506', 'price' => 86.21]);
    $anticipo = facturasDeLaBarberia()->first();
    expect($anticipo->anticipo)->toBeTrue()->and($anticipo->total_minor)->toBe(10000);

    // El resto en caja: la operación completa ($250), relacionada con el anticipo, y el
    // egreso que aplica el anticipo (forma 30), relacionado con ella.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect($enviadas)->toHaveCount(3)
        ->and($enviadas[1]['items'][0]['product'])->toMatchArray(['price' => 215.52, 'product_key' => '86121600'])
        ->and($enviadas[1]['items'][0]['product']['description'])->not->toContain('remanente')
        ->and($enviadas[1]['payment_form'])->toBe('01')
        ->and($enviadas[1]['related_documents'])->toBe([['relationship' => '07', 'documents' => ['UUID-1']]])
        ->and($enviadas[2])->toMatchArray(['type' => 'E', 'payment_form' => '30', 'use' => 'G02'])
        ->and($enviadas[2]['items'][0]['product'])->toMatchArray(['description' => 'Aplicación de anticipo', 'product_key' => '84111506', 'price' => 86.21])
        ->and($enviadas[2]['related_documents'])->toBe([['relationship' => '07', 'documents' => ['UUID-2']]]);
    $facturas = facturasDeLaBarberia();
    expect($facturas->pluck('tipo')->all())->toBe(['I', 'I', 'E'])
        ->and($facturas[0]->anticipo_aplicado_en)->toBe($facturas[1]->getKey())
        ->and($facturas[1]->total_minor)->toBe(25000);

    // Una sola vez: los reintentos no facturan de nuevo.
    $this->artisan('agendauno:facturar-citas')->assertSuccessful();
    expect($enviadas)->toHaveCount(3);
});

it('si el egreso no se timbra, el reintento aplica el anticipo después', function (): void {
    $enviadas = new ArrayObject;
    $fallar = new ArrayObject([true]);
    timbradoDeFacturasAutomaticas($enviadas, $fallar);
    ['e' => $e, 'cita' => $cita] = barberiaQueFacturaSola();
    betoPagaSuAnticipo($e['slug'], (string) $cita['orden_id']);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect($enviadas)->toHaveCount(2)
        ->and(facturasDeLaBarberia()->first()->anticipo_aplicado_en)->toBeNull();

    $fallar->exchangeArray([]);
    $this->artisan('agendauno:facturar-citas')->assertSuccessful();
    expect($enviadas)->toHaveCount(3)
        ->and($enviadas[2]['payment_form'])->toBe('30')
        ->and(facturasDeLaBarberia()->first()->anticipo_aplicado_en)->not->toBeNull();
});

it('sin pedir la factura automática, o con el negocio que no la encendió, no factura nada', function (): void {
    $enviadas = new ArrayObject;
    timbradoDeFacturasAutomaticas($enviadas, new ArrayObject);
    ['e' => $e, 'cita' => $cita] = barberiaQueFacturaSola(automatica: false);
    betoPagaSuAnticipo($e['slug'], (string) $cita['orden_id']);
    expect($enviadas)->toHaveCount(0);

    // El negocio la apaga: tampoco, aunque el cliente la pida.
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['facturacion.automatica_citas' => 0]], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['reserva']}/cobro", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect($enviadas)->toHaveCount(0);
});

it('el cliente guarda sus datos fiscales en su cuenta y el borrador de una factura los toma', function (): void {
    $e = estudioConSesion('barberia-mia', 'dueno@barberia.mx', 'barberia');
    cargarDatosFiscales($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $mi = "/api/v1/app/{$e['slug']}/mi/datos-fiscales";

    $this->getJson($mi, conBearer($vale['bearer']))->assertOk()->assertJsonPath('data', null)
        ->assertJsonPath('meta.automatica_disponible', false);
    // Un uso solo de personas físicas con el RFC de una empresa, no.
    $this->putJson($mi, ['rfc' => 'ABC010101AB9', 'nombre' => 'Empresa', 'codigo_postal' => '06700', 'regimen_fiscal' => '601', 'uso_cfdi' => 'D01'], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['uso_cfdi'], 'meta.errors');
    $this->putJson($mi, ['rfc' => 'XAXX01010100', 'nombre' => 'x', 'codigo_postal' => '067', 'regimen_fiscal' => '999', 'uso_cfdi' => 'G03'], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['codigo_postal', 'regimen_fiscal'], 'meta.errors');
    $this->putJson($mi, ['rfc' => 'GODE561231GR8', 'nombre' => 'Valeria Gómez', 'codigo_postal' => '06700', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03', 'factura_automatica' => true], conBearer($vale['bearer']))
        ->assertOk()->assertJsonPath('data.nombre', 'VALERIA GÓMEZ')->assertJsonPath('data.factura_automatica', true);

    // Una venta de Vale: su borrador ya trae sus datos.
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    compraPagadaTenant($e, $persona);
    $orden = (string) $this->getJson("/api/v1/app/{$e['slug']}/ordenes?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.receptor.rfc', 'GODE561231GR8')
        ->assertJsonPath('data.receptor.regimen_fiscal', '612')
        ->assertJsonPath('data.receptor.uso_cfdi', 'G03');

    $this->deleteJson($mi, [], conBearer($vale['bearer']))->assertNoContent();
    $this->getJson($mi, conBearer($vale['bearer']))->assertOk()->assertJsonPath('data', null);
});
