<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\RfcDeCertificado;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Http\Client\Request as PeticionAFacturapi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Emisores versionados y fotos fiscales (ADR 0228):
| - B: cambiar de RFC no deja inaccesibles las facturas de antes: se descargan, se
|   reenvían y se cancelan con la llave del emisor que las timbró.
| - C: un sello (CSD) de otro RFC no deja la facturación lista ni timbra.
| - D: cada línea guarda al venderse su IVA y sus claves del SAT; la factura usa esa
|   foto (no el catálogo de hoy), no junta exentos con gravados y reparte el descuento y
|   lo devuelto entre los conceptos.
| - E: Mis facturas por páginas y por periodo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * FacturAPI de verdad (con la llave de usuario de la plataforma), simulado: cada alta
 * crea otra organización (`org_1`, `org_2`…), cada llave que se le pide es otra
 * (`sk_live_org_1`, `sk_live_org_1_2`…) y cada organización dice el RFC de su sello, que
 * la prueba puede cambiar (`rfcs`) como puede borrar una llave (`borradas`).
 *
 * @param  array<string, string>  $rfcDelSello  organización => RFC de su sello
 * @return ArrayObject<string, mixed>
 */
function facturapiConVariosEmisores(array $rfcDelSello): ArrayObject
{
    Config::set('agendauno.facturapi.llave_usuario', 'sk_user_plataforma');
    $facturapi = new ArrayObject([
        'organizaciones' => 0, 'facturas' => 0,
        'llaves' => new ArrayObject,
        'rfcs' => new ArrayObject($rfcDelSello),
        'borradas' => new ArrayObject,
    ]);
    Http::fake(function (PeticionAFacturapi $p) use ($facturapi) {
        if (parse_url($p->url(), PHP_URL_HOST) !== 'www.facturapi.io') {
            return Http::response([]);
        }
        $ruta = substr((string) parse_url($p->url(), PHP_URL_PATH), strlen('/v2'));
        $legal = static fn (string $org): array => ['tax_id' => $facturapi['rfcs'][$org] ?? null];
        if ($p->method() === 'POST' && $ruta === '/organizations') {
            $facturapi['organizaciones']++;

            return Http::response(['id' => 'org_'.$facturapi['organizaciones']]);
        }
        if (preg_match('#^/organizations/(org_\d+)/certificate$#', $ruta, $m) === 1) {
            return Http::response(['id' => $m[1], 'legal' => $legal($m[1])]);
        }
        if (preg_match('#^/organizations/(org_\d+)/apikeys/live$#', $ruta, $m) === 1) {
            $n = $facturapi['llaves'][$m[1]] = ($facturapi['llaves'][$m[1]] ?? 0) + 1;

            return Http::response((string) json_encode('sk_live_'.$m[1].($n > 1 ? "_{$n}" : '')));
        }
        if (preg_match('#^/organizations/(org_\d+)/legal$#', $ruta, $m) === 1) {
            return Http::response(['id' => $m[1]]);
        }
        if (preg_match('#^/organizations/(org_\d+)$#', $ruta, $m) === 1) {
            return Http::response(['id' => $m[1], 'is_production_ready' => true, 'pending_steps' => [], 'legal' => $legal($m[1])]);
        }
        if (isset($facturapi['borradas'][substr($p->header('Authorization')[0] ?? '', strlen('Bearer '))])) {
            return Http::response(['message' => 'La llave no es válida.'], 401);
        }
        if ($p->method() === 'POST' && $ruta === '/invoices') {
            $facturapi['facturas']++;

            return Http::response(['id' => 'inv_'.$facturapi['facturas'], 'uuid' => 'UUID-'.$facturapi['facturas']]);
        }
        if (preg_match('#^/invoices/(inv_\d+)/(pdf|xml)$#', $ruta, $m) === 1) {
            return Http::response('%PDF '.$m[1]);
        }
        if (preg_match('#^/invoices/inv_\d+/email$#', $ruta) === 1) {
            return Http::response(['ok' => true]);
        }
        if ($p->method() === 'DELETE' && preg_match('#^/invoices/(inv_\d+)$#', $ruta, $m) === 1) {
            return Http::response(['id' => $m[1], 'status' => 'canceled']);
        }

        return Http::response(['message' => 'No esperado: '.$p->method().' '.$ruta], 404);
    });

    return $facturapi;
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function subirSelloDelEmisor(array $e, string $certificado = 'CERTIFICADO'): TestResponse
{
    return test()->post("/api/v1/app/{$e['slug']}/datos-fiscales/sello", [
        'certificado' => UploadedFile::fake()->createWithContent('sello.cer', $certificado),
        'llave' => UploadedFile::fake()->createWithContent('sello.key', 'LLAVE'),
        'password' => 'clave-csd',
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json']);
}

/**
 * Una factura sin venta, para probar el emisor.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function facturaSueltaDelEmisor(array $e): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
        'receptor' => ['nombre' => 'Ana López', 'rfc' => 'LOAA800101AB1', 'codigo_postal' => '06700', 'regimen_fiscal' => '605'],
        'uso_cfdi' => 'G03',
        'items' => [['descripcion' => 'Clase', 'cantidad' => 1, 'precio_unitario_minor' => 20000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']));
}

/**
 * Un certificado como el CSD del SAT (en DER), con el RFC en `x500UniqueIdentifier`.
 */
function certificadoCsdDePrueba(string $identificador): string
{
    $configuracion = (string) tempnam(sys_get_temp_dir(), 'csd');
    file_put_contents($configuracion, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
    $opciones = ['config' => $configuracion, 'digest_alg' => 'sha256'];
    $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configuracion]);
    $csr = openssl_csr_new(['commonName' => 'Empresa Demo', 'x500UniqueIdentifier' => $identificador], $llave, $opciones);
    openssl_x509_export(openssl_csr_sign($csr, null, $llave, 30, $opciones), $pem);
    unlink($configuracion);

    return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', (string) $pem));
}

/**
 * Un proveedor que guarda el cuerpo de cada CFDI que se timbra.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function proveedorQueGuardaLoTimbrado(): ArrayObject
{
    $timbradas = new ArrayObject;
    app()->instance(ClienteFacturacion::class, new class($timbradas) implements ClienteFacturacion
    {
        public function __construct(private ArrayObject $timbradas) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->timbradas->append($factura);

            return new ResultadoTimbre('fac_'.count($this->timbradas), 'UUID-'.count($this->timbradas));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });

    return $timbradas;
}

/**
 * Un plan del catálogo con su IVA.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $mas
 */
function planConIva(array $e, string $nombre, int $precio, string $iva, array $mas = []): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => $nombre, 'tipo' => 'paquete', 'precio_minor' => $precio,
        'moneda' => 'MXN', 'ilimitado' => false, 'creditos_incluidos' => 4000, 'iva' => $iva, ...$mas,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Una orden cobrada en caja (efectivo) a la persona, con un cupón si se dice.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  list<array{producto_id: string, cantidad: int}>  $items
 * @return array{orden: string, pago: string}
 */
function ordenCobradaConIva(array $e, string $persona, array $items, ?string $cupon = null): array
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", array_filter([
        'comprador_id' => $persona, 'items' => $items, 'codigo_promo' => $cupon,
    ]), conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    return ['orden' => $orden, 'pago' => $pago];
}

/**
 * Corre en la base del negocio.
 *
 * @template T
 *
 * @param  callable(): T  $paso
 * @return T
 */
function enLaBaseDelEmisor(string $slug, callable $paso): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), $paso);
}

it('cambiar de RFC no deja inaccesibles las facturas de antes: se descargan, reenvían y cancelan con su emisor', function (): void {
    facturapiConVariosEmisores(['org_1' => 'ABC010101AB9', 'org_2' => 'XYZ010101AB1']);
    $e = estudioConSesion('emisor-versionado', 'a@emisor-versionado.mx');
    cargarDatosFiscales($e);
    subirSelloDelEmisor($e)->assertOk()->assertJsonPath('data.facturacion_lista', true);
    $vieja = facturaSueltaDelEmisor($e)->assertCreated()
        ->assertJsonPath('data.emisor_rfc', 'ABC010101AB9')
        ->assertJsonPath('data.de_otro_rfc', false)
        ->json('data.id');
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
    $base = "/api/v1/app/{$e['slug']}/facturas";
    $receptor = ['nombre' => 'Ana López', 'rfc' => 'LOAA800101AB1', 'codigo_postal' => '06700', 'regimen_fiscal' => '605'];
    $anticipo = test()->postJson($base, [
        'receptor' => $receptor, 'uso_cfdi' => 'G03', 'anticipo' => true,
        'items' => [['descripcion' => 'Anticipo', 'cantidad' => 1, 'precio_unitario_minor' => 5000, 'clave_prod_serv' => '84111506', 'clave_unidad' => 'ACT']],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    expect(test()->getJson("{$base}?anticipos_de=LOAA800101AB1", conBearer($e['bearer']))->assertOk()->json('meta.total'))->toBe(1);

    // Otro RFC: otra alta en FacturAPI (otra organización, otra llave).
    test()->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Nueva Razón SA de CV', 'rfc' => 'XYZ010101AB1', 'regimen_fiscal' => '601', 'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.sellos_cargados', false);
    subirSelloDelEmisor($e)->assertOk()->assertJsonPath('data.facturacion_lista', true);
    $nueva = facturaSueltaDelEmisor($e)->assertCreated()->assertJsonPath('data.emisor_rfc', 'XYZ010101AB1')->json('data.id');
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices') && $p->hasHeader('Authorization', 'Bearer sk_live_org_2'));

    // Su anticipo ya no se ofrece ni se aplica con el RFC de hoy.
    expect(test()->getJson("{$base}?anticipos_de=LOAA800101AB1", conBearer($e['bearer']))->assertOk()->json('meta.total'))->toBe(0);
    test()->postJson($base, [
        'receptor' => $receptor, 'uso_cfdi' => 'G03', 'anticipos' => [$anticipo],
        'items' => [['descripcion' => 'Clase', 'cantidad' => 1, 'precio_unitario_minor' => 15000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('meta.errors.anticipos.0', fn (string $m): bool => str_contains($m, 'ABC010101AB9'));

    // La vieja se descarga y se reenvía con la llave de su emisor; la nueva, con la de hoy.
    test()->get("{$base}/{$vieja}/pdf", conBearer($e['bearer']))->assertOk();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_1/pdf') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
    test()->get("{$base}/{$nueva}/xml", conBearer($e['bearer']))->assertOk();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_3/xml') && $p->hasHeader('Authorization', 'Bearer sk_live_org_2'));
    test()->postJson("{$base}/{$vieja}/enviar", ['email' => 'contabilidad@correo.mx'], conBearer($e['bearer']))->assertStatus(202);
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_1/email')
        && $p['email'] === 'contabilidad@correo.mx' && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));

    // Un CFDI nuevo relacionado con ella ya no lo timbra el RFC de hoy, y no se ofrece.
    $fila = collect(test()->getJson($base, conBearer($e['bearer']))->assertOk()->json('data'))->firstWhere('id', $vieja);
    expect($fila['de_otro_rfc'])->toBeTrue()
        ->and($fila['emisor_rfc'])->toBe('ABC010101AB9')
        ->and($fila['acreditable_minor'])->toBe(0)
        ->and($fila['cancelable'])->toBeTrue();
    test()->postJson("{$base}/{$vieja}/notas-credito", ['monto_minor' => 1000, 'forma_pago' => '01'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.factura.0', fn (string $m): bool => str_contains($m, 'ABC010101AB9'));
    test()->getJson("{$base}/borrador?sustituye={$vieja}", conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.sustituye.0', fn (string $m): bool => str_contains($m, 'ABC010101AB9'));

    // Se cancela en su organización.
    test()->postJson("{$base}/{$vieja}/cancelar", ['motivo' => '02'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
    Http::assertSent(fn (PeticionAFacturapi $p): bool => $p->method() === 'DELETE' && str_contains($p->url(), '/invoices/inv_1?') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
    Http::assertNotSent(fn (PeticionAFacturapi $p): bool => str_contains($p->url(), '/invoices/inv_1') && $p->hasHeader('Authorization', 'Bearer sk_live_org_2'));
});

it('la migración liga al emisor de hoy las facturas que ya hay, con su llave', function (): void {
    facturapiConVariosEmisores(['org_1' => 'ABC010101AB9', 'org_2' => 'XYZ010101AB1']);
    $e = estudioConSesion('emisor-migrado', 'a@emisor-migrado.mx');
    cargarDatosFiscales($e);
    subirSelloDelEmisor($e)->assertOk();
    $factura = facturaSueltaDelEmisor($e)->assertCreated()->json('data.id');

    // Como una base de antes de esta versión, que se pone al día.
    enLaBaseDelEmisor($e['slug'], function (): void {
        $migracion = require database_path('migrations/tenant/2026_10_26_000204_emisores_fiscales_y_fotos_fiscales.php');
        $migracion->down();
        $migracion->up();
    });
    test()->getJson("/api/v1/app/{$e['slug']}/facturas/{$factura}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.emisor_rfc', 'ABC010101AB9');

    // Cambia de RFC: la factura sigue con la llave de su emisor, copiada tal cual.
    test()->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Nueva Razón SA de CV', 'rfc' => 'XYZ010101AB1', 'regimen_fiscal' => '601', 'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertOk();
    subirSelloDelEmisor($e)->assertOk();
    test()->get("/api/v1/app/{$e['slug']}/facturas/{$factura}/pdf", conBearer($e['bearer']))->assertOk();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_1/pdf') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
});

it('al cambiar de RFC, una factura que no dice su emisor se liga al que se retira', function (): void {
    $facturapi = facturapiConVariosEmisores(['org_1' => 'ABC010101AB9', 'org_2' => 'XYZ010101AB1']);
    $e = estudioConSesion('emisor-de-antes', 'a@emisor-de-antes.mx');
    cargarDatosFiscales($e);
    subirSelloDelEmisor($e)->assertOk();
    $factura = facturaSueltaDelEmisor($e)->assertCreated()->json('data.id');

    // Como si la hubieran timbrado antes de guardar su emisor.
    enLaBaseDelEmisor($e['slug'], function (): void {
        DB::connection('tenant')->table('facturas')->update(['emisor_fiscal_id' => null, 'emisor_rfc' => null]);
        DB::connection('tenant')->table('emisores_fiscales')->delete();
    });
    test()->getJson("/api/v1/app/{$e['slug']}/facturas/{$factura}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.emisor_rfc', null);

    test()->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Nueva Razón SA de CV', 'rfc' => 'XYZ010101AB1', 'regimen_fiscal' => '601', 'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertOk();
    subirSelloDelEmisor($e)->assertOk();

    test()->getJson("/api/v1/app/{$e['slug']}/facturas/{$factura}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.emisor_rfc', 'ABC010101AB9');
    test()->get("/api/v1/app/{$e['slug']}/facturas/{$factura}/pdf", conBearer($e['bearer']))->assertOk();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_1/pdf') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
    expect($facturapi['organizaciones'])->toBe(2);
});

it('si FacturAPI borró la llave de un emisor por no usarla, se crea otra y la factura sigue a la mano', function (): void {
    $facturapi = facturapiConVariosEmisores(['org_1' => 'ABC010101AB9', 'org_2' => 'XYZ010101AB1']);
    $e = estudioConSesion('emisor-llave', 'a@emisor-llave.mx');
    cargarDatosFiscales($e);
    subirSelloDelEmisor($e)->assertOk();
    $vieja = facturaSueltaDelEmisor($e)->assertCreated()->json('data.id');
    test()->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Nueva Razón SA de CV', 'rfc' => 'XYZ010101AB1', 'regimen_fiscal' => '601', 'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertOk();
    subirSelloDelEmisor($e)->assertOk();

    // La del emisor de antes: se crea otra con la llave de usuario, y se queda guardada.
    $facturapi['borradas']['sk_live_org_1'] = true;
    $base = "/api/v1/app/{$e['slug']}/facturas";
    test()->get("{$base}/{$vieja}/pdf", conBearer($e['bearer']))->assertOk();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/organizations/org_1/apikeys/live') && $p->hasHeader('Authorization', 'Bearer sk_user_plataforma'));
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices/inv_1/pdf') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1_2'));
    test()->get("{$base}/{$vieja}/xml", conBearer($e['bearer']))->assertOk();
    expect($facturapi['llaves']['org_1'])->toBe(2);

    // La del emisor de hoy: timbra con la nueva y no queda una factura con error.
    $facturapi['borradas']['sk_live_org_2'] = true;
    facturaSueltaDelEmisor($e)->assertCreated()->assertJsonPath('data.estado', 'timbrada');
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices') && $p->hasHeader('Authorization', 'Bearer sk_live_org_2_2'));
    facturaSueltaDelEmisor($e)->assertCreated();
    expect($facturapi['llaves']['org_2'])->toBe(2);
});

it('un sello que FacturAPI dice que es de otro RFC no deja la facturación lista ni timbra, hasta subir el correcto', function (): void {
    $facturapi = facturapiConVariosEmisores(['org_1' => 'OTR010101AB1']);
    $e = estudioConSesion('sello-otro-rfc', 'a@sello-otro-rfc.mx');
    cargarDatosFiscales($e);

    subirSelloDelEmisor($e)->assertStatus(422)
        ->assertJsonPath('meta.errors.sello.0', fn (string $m): bool => str_contains($m, 'OTR010101AB1') && str_contains($m, 'ABC010101AB9'));
    // Sin llave del negocio con ese sello.
    Http::assertNotSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/apikeys/live'));
    test()->getJson("/api/v1/app/{$e['slug']}/datos-fiscales", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.facturacion_lista', false)
        ->assertJsonPath('data.sellos_cargados', false)
        ->assertJsonPath('data.sello_de_otro_rfc', true)
        ->assertJsonPath('data.rfc_del_sello', 'OTR010101AB1');

    // No se timbra antes de corregirlo.
    facturaSueltaDelEmisor($e)->assertStatus(422)
        ->assertJsonPath('meta.errors.facturacion.0', fn (string $m): bool => str_contains($m, 'OTR010101AB1'));
    Http::assertNotSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices'));

    // El sello de su RFC reemplaza al otro, en la misma organización: queda lista y timbra.
    $facturapi['rfcs']['org_1'] = 'ABC010101AB9';
    subirSelloDelEmisor($e)->assertOk()
        ->assertJsonPath('data.facturacion_lista', true)
        ->assertJsonPath('data.sello_de_otro_rfc', false)
        ->assertJsonPath('data.error', null);
    facturaSueltaDelEmisor($e)->assertCreated();
    Http::assertSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices') && $p->hasHeader('Authorization', 'Bearer sk_live_org_1'));
    expect($facturapi['organizaciones'])->toBe(1);
});

it('«Revisar de nuevo» también compara el RFC del sello: si ya no es el capturado, no timbra', function (): void {
    $facturapi = facturapiConVariosEmisores(['org_1' => 'ABC010101AB9']);
    $e = estudioConSesion('sello-revisar', 'a@sello-revisar.mx');
    cargarDatosFiscales($e);
    subirSelloDelEmisor($e)->assertOk()->assertJsonPath('data.facturacion_lista', true);

    $facturapi['rfcs']['org_1'] = 'OTR010101AB1';
    test()->postJson("/api/v1/app/{$e['slug']}/datos-fiscales/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.facturacion_lista', false)
        ->assertJsonPath('data.sellos_cargados', false)
        ->assertJsonPath('data.sello_de_otro_rfc', true)
        ->assertJsonPath('data.error', fn (string $m): bool => str_contains($m, 'OTR010101AB1'));
    facturaSueltaDelEmisor($e)->assertStatus(422)->assertJsonValidationErrors(['facturacion'], 'meta.errors');
    Http::assertNotSent(fn (PeticionAFacturapi $p): bool => str_ends_with($p->url(), '/invoices'));
});

it('un sello que se lee de otro RFC se rechaza sin tocar FacturAPI, y el del RFC capturado sigue su alta', function (): void {
    facturapiConVariosEmisores(['org_1' => 'ABC010101AB9']);
    $e = estudioConSesion('sello-leido', 'a@sello-leido.mx');
    cargarDatosFiscales($e);

    subirSelloDelEmisor($e, certificadoCsdDePrueba('OTR010101AB1 / OTRA800101MDFRNN09'))->assertStatus(422)
        ->assertJsonPath('meta.errors.sello.0', fn (string $m): bool => str_contains($m, 'OTR010101AB1') && str_contains($m, 'ABC010101AB9'));
    Http::assertNotSent(fn (PeticionAFacturapi $p): bool => str_contains($p->url(), 'facturapi.io'));

    subirSelloDelEmisor($e, certificadoCsdDePrueba('ABC010101AB9 / HEGT7610034S2'))->assertOk()
        ->assertJsonPath('data.sellos_cargados', true)
        ->assertJsonPath('data.facturacion_lista', true);
});

it('lee el RFC del certificado del SAT; lo que no se lee no decide', function (): void {
    $der = certificadoCsdDePrueba('abc010101ab9 / HEGT7610034S2');
    expect(RfcDeCertificado::leer($der))->toBe('ABC010101AB9')
        ->and(RfcDeCertificado::leer("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n"))->toBe('ABC010101AB9')
        ->and(RfcDeCertificado::leer(certificadoCsdDePrueba('no es un RFC')))->toBeNull()
        ->and(RfcDeCertificado::leer('CERTIFICADO'))->toBeNull()
        ->and(RfcDeCertificado::leer(''))->toBeNull();
});

it('una venta exenta y con IVA se factura separada, con el descuento y lo devuelto repartidos y la foto del día de la venta', function (): void {
    $e = estudioConSesion('foto-fiscal', 'a@foto-fiscal.mx');
    cargarDatosFiscales($e);
    $timbradas = proveedorQueGuardaLoTimbrado();
    $consulta = planConIva($e, 'Consulta', 50000, 'exento');
    $crema = planConIva($e, 'Crema', 11600, 'general');
    test()->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'diez', 'tipo' => 'porcentaje', 'valor' => 1000], conBearer($e['bearer']))
        ->assertCreated();
    $v = ordenCobradaConIva($e, crearMiembroTenant($e, 'Ana'), [
        ['producto_id' => $consulta, 'cantidad' => 1], ['producto_id' => $crema, 'cantidad' => 1],
    ], 'diez');
    $url = "/api/v1/app/{$e['slug']}/facturas";
    $borrador = fn (): TestResponse => test()->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk();

    // El 10 % de descuento ($61.60) se reparte: $50.00 de la consulta y $11.60 de la crema.
    $borrador()
        ->assertJsonPath('data.total_minor', 55440)
        ->assertJsonPath('data.descuento_minor', 6160)
        ->assertJsonPath('data.devuelto_minor', 0)
        ->assertJsonPath('data.fiscal_del_catalogo', false)
        ->assertJsonPath('data.conceptos', [
            ['descripcion' => 'Consulta', 'cantidad' => 1, 'precio_unitario_minor' => 45000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48', 'iva' => 'exento'],
            ['descripcion' => 'Crema', 'cantidad' => 1, 'precio_unitario_minor' => 9000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48', 'iva' => 'general'],
        ]);

    // Que el catálogo cambie después no cambia lo vendido.
    test()->putJson("/api/v1/app/{$e['slug']}/productos/{$consulta}", ['iva' => 'general', 'clave_sat' => '85121800'], conBearer($e['bearer']))->assertOk();
    $borrador()->assertJsonPath('data.conceptos.0.iva', 'exento')->assertJsonPath('data.conceptos.0.clave_prod_serv', '86121600');

    // Una devolución parcial (10 %) también se reparte entre los dos.
    test()->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos", ['motivo' => 'Ajuste', 'monto_minor' => 5544], conBearer($e['bearer']))->assertCreated();
    $conceptos = $borrador()
        ->assertJsonPath('data.total_minor', 49896)
        ->assertJsonPath('data.devuelto_minor', 5544)
        ->assertJsonPath('data.conceptos.0.precio_unitario_minor', 40500)
        ->assertJsonPath('data.conceptos.1.precio_unitario_minor', 8100)
        ->json('data.conceptos');

    $receptor = ['nombre' => 'Ana López', 'rfc' => 'LOAA800101AB1', 'codigo_postal' => '06700', 'regimen_fiscal' => '605'];
    // Una pantalla que no manda el IVA de cada concepto no factura mal una venta mixta.
    $sinIva = array_map(static fn (array $c): array => array_diff_key($c, ['iva' => true]), $conceptos);
    test()->postJson($url, ['receptor' => $receptor, 'uso_cfdi' => 'G03', 'items' => $sinIva, 'orden_id' => $v['orden']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['items'], 'meta.errors');
    // Ni una que traiga otro importe por IVA.
    $cruzados = [[...$conceptos[0], 'iva' => 'general'], [...$conceptos[1], 'iva' => 'exento']];
    test()->postJson($url, ['receptor' => $receptor, 'uso_cfdi' => 'G03', 'items' => $cruzados, 'orden_id' => $v['orden']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['items'], 'meta.errors');

    $factura = test()->postJson($url, ['receptor' => $receptor, 'uso_cfdi' => 'G03', 'items' => $conceptos, 'orden_id' => $v['orden']], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.subtotal_minor', 48600)
        ->assertJsonPath('data.impuesto_minor', 1296)
        ->assertJsonPath('data.total_minor', 49896)
        ->json('data.id');
    expect(array_map(static fn (array $i): array => $i['product']['taxes'], $timbradas[0]['items']))->toBe([
        [['type' => 'IVA', 'factor' => 'Exento']],
        [['type' => 'IVA', 'rate' => 0.16]],
    ]);

    // Su nota de crédito también separa: lo exento sin IVA y lo gravado con IVA.
    test()->postJson("{$url}/{$factura}/notas-credito", ['monto_minor' => 4990, 'forma_pago' => '01'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.subtotal_minor', 4860)
        ->assertJsonPath('data.impuesto_minor', 130)
        ->assertJsonPath('data.total_minor', 4990);
    expect(array_map(static fn (array $i): array => [$i['product']['price'], $i['product']['taxes']], $timbradas[1]['items']))->toBe([
        [40.5, [['type' => 'IVA', 'factor' => 'Exento']]],
        [8.1, [['type' => 'IVA', 'rate' => 0.16]]],
    ]);
});

it('la promoción no toca las cuotas; el descuento de contado se reparte entre todo lo cobrado', function (): void {
    $e = estudioConSesion('foto-cuotas', 'a@foto-cuotas.mx');
    cargarDatosFiscales($e);
    test()->putJson("/api/v1/app/{$e['slug']}/cuotas", [
        'inscripcion_minor' => 50000, 'reingreso_meses' => 6, 'anualidad_minor' => 0, 'anualidad_modo' => 'aniversario',
    ], conBearer($e['bearer']))->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['ventas.descuento_contado_pct' => 10]], conBearer($e['bearer']))->assertOk();
    test()->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'diez', 'tipo' => 'porcentaje', 'valor' => 1000], conBearer($e['bearer']))->assertCreated();
    $plan = planConIva($e, 'Consulta', 100000, 'exento', ['cobra_inscripcion' => true, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1]);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => $plan, 'cantidad' => 1]], 'codigo_promo' => 'diez',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.total_minor', 140000)->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    // $100 de promoción solo en el plan ($900) y la inscripción completa ($500); el 10 % de
    // contado se reparte entre los dos: $810 exentos y $450 con IVA incluido.
    test()->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 126000)
        ->assertJsonPath('data.descuento_minor', 24000)
        ->assertJsonPath('data.conceptos.0.descripcion', 'Consulta')
        ->assertJsonPath('data.conceptos.0.iva', 'exento')
        ->assertJsonPath('data.conceptos.0.precio_unitario_minor', 81000)
        ->assertJsonPath('data.conceptos.1.descripcion', 'Inscripción')
        ->assertJsonPath('data.conceptos.1.iva', 'general')
        ->assertJsonPath('data.conceptos.1.precio_unitario_minor', 38793);
});

it('una venta toda exenta va exenta aunque la pantalla no mande el IVA, y no acepta otro', function (): void {
    $e = estudioConSesion('foto-exenta', 'a@foto-exenta.mx');
    cargarDatosFiscales($e);
    $consulta = planConIva($e, 'Consulta', 50000, 'exento');
    $v = ordenCobradaConIva($e, crearMiembroTenant($e, 'Ana'), [['producto_id' => $consulta, 'cantidad' => 1]]);
    $url = "/api/v1/app/{$e['slug']}/facturas";
    $conceptos = test()->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.conceptos.0.iva', 'exento')
        ->assertJsonPath('data.fiscal_del_catalogo', false)
        ->json('data.conceptos');
    $facturar = fn (array $items): TestResponse => test()->postJson($url, [
        'receptor' => ['nombre' => 'Público en general', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'S01', 'orden_id' => $v['orden'], 'items' => $items,
    ], conBearer($e['bearer']));

    $facturar([[...$conceptos[0], 'iva' => 'general']])->assertStatus(422)->assertJsonValidationErrors(['items'], 'meta.errors');
    $facturar([array_diff_key($conceptos[0], ['iva' => true])])->assertCreated()
        ->assertJsonPath('data.impuesto_minor', 0)
        ->assertJsonPath('data.total_minor', 50000);
});

it('una venta de antes de guardar su foto se factura con el catálogo de hoy, lo dice, y vale lo que se revisó', function (): void {
    $e = estudioConSesion('foto-vieja', 'a@foto-vieja.mx');
    cargarDatosFiscales($e);
    $consulta = planConIva($e, 'Consulta', 50000, 'exento');
    $v = ordenCobradaConIva($e, crearMiembroTenant($e, 'Beto'), [['producto_id' => $consulta, 'cantidad' => 1]]);
    // Como si fuera de antes de esta versión (sin foto), y con el catálogo ya cambiado.
    enLaBaseDelEmisor($e['slug'], function () use ($v): void {
        $orden = OrdenTenant::query()->where('ulid', $v['orden'])->sole();
        LineaOrdenTenant::query()->where('orden_id', $orden->getKey())->update(['iva' => null, 'clave_prod_serv' => null, 'clave_unidad' => null]);
    });
    test()->putJson("/api/v1/app/{$e['slug']}/productos/{$consulta}", ['iva' => 'general'], conBearer($e['bearer']))->assertOk();
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $conceptos = test()->getJson("{$url}/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.conceptos.0.iva', 'general')
        ->assertJsonPath('data.fiscal_del_catalogo', true)
        ->json('data.conceptos');

    // Quien factura sabe que se vendió exenta: lo corrige en pantalla y vale.
    test()->postJson($url, [
        'receptor' => ['nombre' => 'Público en general', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'S01', 'orden_id' => $v['orden'],
        'items' => [[...$conceptos[0], 'iva' => 'exento', 'precio_unitario_minor' => 50000]],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.impuesto_minor', 0);
});

it('en el mostrador cada artículo lleva su foto fiscal y lo devuelto no se factura', function (): void {
    $e = estudioConSesion('foto-mostrador', 'a@foto-mostrador.mx');
    cargarDatosFiscales($e);
    $sede = agendaSemilla($e);
    $base = "/api/v1/app/{$e['slug']}";
    test()->postJson("{$base}/caja/turnos", ['sucursal_id' => $sede['sucursal'], 'fondo_minor' => 0], conBearer($e['bearer']))->assertCreated();
    $articulo = function (string $nombre, int $precio, string $iva) use ($e, $base, $sede): string {
        $id = (string) test()->postJson("{$base}/articulos", ['nombre' => $nombre, 'precio_minor' => $precio, 'moneda' => 'MXN', 'iva' => $iva], conBearer($e['bearer']))
            ->assertCreated()->json('data.id');
        test()->postJson("{$base}/articulos/{$id}/movimientos", ['sucursal_id' => $sede['sucursal'], 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))->assertCreated();

        return $id;
    };
    $medicina = $articulo('Medicina', 10000, 'exento');
    $pomada = $articulo('Pomada', 11600, 'general');
    $venta = (string) test()->postJson("{$base}/pos/ventas", [
        'sucursal_id' => $sede['sucursal'], 'metodo_pago' => 'efectivo',
        'items' => [['articulo_id' => $medicina, 'cantidad' => 2], ['articulo_id' => $pomada, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    // Después, la medicina deja de ser exenta en el catálogo: lo vendido no cambia.
    test()->putJson("{$base}/articulos/{$medicina}", ['nombre' => 'Medicina', 'precio_minor' => 10000, 'moneda' => 'MXN', 'iva' => 'general'], conBearer($e['bearer']))->assertOk();
    $lineas = collect(test()->getJson("{$base}/pos/ventas", conBearer($e['bearer']))->json('data'))->firstWhere('id', $venta)['lineas'];
    test()->withHeader('Idempotency-Key', 'dev-medicina')->postJson("{$base}/pos/ventas/{$venta}/devoluciones", [
        'lineas' => [['id' => $lineas[0]['id'], 'cantidad' => 1]], 'motivo' => 'No la usó',
    ], conBearer($e['bearer']))->assertCreated();

    test()->getJson("{$base}/facturas/borrador?venta={$venta}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 21600)
        ->assertJsonPath('data.devuelto_minor', 10000)
        ->assertJsonPath('data.fiscal_del_catalogo', false)
        ->assertJsonPath('data.conceptos', [
            ['descripcion' => 'Medicina', 'cantidad' => 1, 'precio_unitario_minor' => 10000, 'clave_prod_serv' => '01010101', 'clave_unidad' => 'H87', 'iva' => 'exento'],
            ['descripcion' => 'Pomada', 'cantidad' => 1, 'precio_unitario_minor' => 10000, 'clave_prod_serv' => '01010101', 'clave_unidad' => 'H87', 'iva' => 'general'],
        ]);
});

it('Mis facturas va por páginas y por periodo; sin ficha en el negocio, no tiene ninguna', function (): void {
    $e = estudioConSesion('mis-facturas-paginas', 'a@mis-facturas-paginas.mx');
    cargarDatosFiscales($e);
    $alumno = alumnoConSesion($e);
    $persona = (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $pack = crearPackTenant($e);
    $facturar = function (string $cuando) use ($e, $persona, $pack): string {
        test()->travelTo(now()->parse($cuando));
        $v = ordenCobradaConIva($e, $persona, [['producto_id' => $pack, 'cantidad' => 1]]);
        $borrador = test()->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$v['orden']}", conBearer($e['bearer']))->assertOk()->json('data');

        return (string) test()->postJson("/api/v1/app/{$e['slug']}/facturas", [
            'receptor' => ['nombre' => 'Vale', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
            'uso_cfdi' => 'S01', 'items' => $borrador['conceptos'], 'orden_id' => $v['orden'],
        ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    };
    $octubre = $facturar('2026-10-05 12:00:00');
    $noviembre = $facturar('2026-11-10 12:00:00');
    $diciembre = $facturar('2026-12-15 12:00:00');
    alRelojDePrueba();
    $url = "/api/v1/app/{$e['slug']}/mi/facturas";

    test()->getJson("{$url}?per_page=2", conBearer($alumno['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.ultima_pagina', 2)
        ->assertJsonPath('meta.disponible', true)
        ->assertJsonPath('data.0.id', $diciembre)
        ->assertJsonPath('data.1.id', $noviembre);
    test()->getJson("{$url}?per_page=2&page=2", conBearer($alumno['bearer']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $octubre);
    test()->getJson("{$url}?desde=2026-11-01&hasta=2026-11-30", conBearer($alumno['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $noviembre);
    test()->getJson("{$url}?desde=2026-11-30&hasta=2026-11-01", conBearer($alumno['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['hasta'], 'meta.errors');

    // Quien no tiene ficha (el equipo) ve el listado vacío, no un error.
    test()->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
