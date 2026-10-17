<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MovimientoTimbreTenant;
use App\Modules\Tenancy\Models\SaldoTimbresTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Timbres para facturar (ADR 0107): saldo con movimientos en la base del negocio.
| Cada factura timbrada gasta uno; sin timbres no se timbra. Se compran en paquetes
| (50 a 500, $1.80 + IVA cada uno) con Stripe y se suman al confirmarse el pago.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array<string, mixed>
 */
function facturaDeUnPaquete(): array
{
    return [
        'receptor' => ['nombre' => 'Cliente Final', 'rfc' => 'XAXX010101000', 'codigo_postal' => '06700'],
        'uso_cfdi' => 'G03',
        'items' => [['descripcion' => 'Paquete 8 clases', 'cantidad' => 1, 'precio_unitario_minor' => 120000, 'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48']],
    ];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function timbresDe(array $e): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/timbres", conBearer($e['bearer']))->assertOk()->json('data');
}

it('cada factura timbrada gasta un timbre y sin timbres no se timbra', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    cargarDatosFiscales($e, timbres: 0);

    $this->postJson("/api/v1/app/{$e['slug']}/facturas", facturaDeUnPaquete(), conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'STAMPS_EXHAUSTED');
    $this->getJson("/api/v1/app/{$e['slug']}/facturas", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');

    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->sole(),
        fn () => app(TimbresTenant::class)->acreditar(2, 'cortesia', 'Cortesía'),
    );
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", facturaDeUnPaquete(), conBearer($e['bearer']))->assertCreated();

    $timbres = timbresDe($e);
    expect($timbres['disponibles'])->toBe(1)
        ->and(array_column($timbres['movimientos'], 'tipo'))->toBe(['consumo', 'compra'])
        ->and($timbres['movimientos'][0]['saldo_despues'])->toBe(1);
});

it('si el proveedor rechaza la factura no se gasta el timbre', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    cargarDatosFiscales($e, timbres: 1);
    app()->instance(ClienteFacturacion::class, new class implements ClienteFacturacion
    {
        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            throw new TimbradoFallido('RFC del receptor no válido.');
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return '';
        }
    });

    $this->postJson("/api/v1/app/{$e['slug']}/facturas", facturaDeUnPaquete(), conBearer($e['bearer']))->assertStatus(422);

    expect(timbresDe($e)['disponibles'])->toBe(1);
});

it('el dueño compra un paquete con Stripe y al confirmarse el pago se suman una sola vez', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_timbres', 'url' => 'https://checkout.stripe.com/c/pay/cs_timbres'])]);

    expect(timbresDe($e))
        ->posible->toBeTrue()
        ->paquetes->toBe([
            ['cantidad' => 50, 'precio_minor' => 9000], ['cantidad' => 100, 'precio_minor' => 18000],
            ['cantidad' => 200, 'precio_minor' => 36000], ['cantidad' => 350, 'precio_minor' => 63000],
            ['cantidad' => 500, 'precio_minor' => 90000],
        ]);
    $this->postJson("/api/v1/app/{$e['slug']}/timbres/comprar", ['cantidad' => 75], conBearer($e['bearer']))->assertStatus(422);

    // $90 + IVA.
    $this->postJson("/api/v1/app/{$e['slug']}/timbres/comprar", ['cantidad' => 50], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.monto_minor', 10440)
        ->assertJsonPath('data.moneda', 'MXN')
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_timbres');
    expect(timbresDe($e)['disponibles'])->toBe(0);

    foreach ([1, 2] as $vez) {
        $this->postJson('/api/v1/webhooks/plataforma/stripe', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_timbres', 'payment_status' => 'paid']],
        ])->assertOk();
    }

    expect(timbresDe($e)['disponibles'])->toBe(50)
        ->and(CargoRenta::query()->sole())->concepto->toBe('timbres')->estado->value->toBe('pagado');
});

it('una compra cuyo pago caduca se cancela y no cuenta como renta pendiente', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_timbres', 'url' => 'https://checkout.stripe.com/c/pay/cs_timbres'])]);
    $this->postJson("/api/v1/app/{$e['slug']}/timbres/comprar", ['cantidad' => 100], conBearer($e['bearer']))->assertCreated();

    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_timbres']],
    ])->assertOk();

    expect(CargoRenta::query()->sole()->estado->value)->toBe('cancelado')
        ->and(CargoRenta::query()->sole()->vence_en)->toBeNull();
});

it('solo se venden timbres a quien puede facturar: México y, en citas, el plan Pro', function (): void {
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $fuera = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    Estudio::query()->where('slug', $fuera['slug'])->update(['pais' => 'CO']);
    expect(timbresDe($fuera)['posible'])->toBeFalse();
    $this->postJson("/api/v1/app/{$fuera['slug']}/timbres/comprar", ['cantidad' => 50], conBearer($fuera['bearer']))->assertStatus(422);

    // Citas: en la prueba tiene las funciones de Pro; ya en Premium, no.
    $citas = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    expect(timbresDe($citas)['posible'])->toBeTrue();
    terminarPrueba($citas);
    Estudio::query()->where('slug', $citas['slug'])->update(['plan_nivel' => 'premium', 'plan_profesionales' => 2]);
    expect(timbresDe($citas))->posible->toBeFalse()->motivo->toBe('La facturación está en el plan Pro.');
});

it('resume el uso por mes en la zona del negocio y estima para cuánto alcanzan', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function (): void {
        $movimientos = [
            ['compra', 100, '2026-06-10 12:00:00'],
            ...array_fill(0, 6, ['consumo', -1, '2026-07-10 12:00:00']),
            ...array_fill(0, 9, ['consumo', -1, '2026-08-20 12:00:00']),
            ...array_fill(0, 11, ['consumo', -1, '2026-09-15 12:00:00']),
            // El 30 de septiembre a las 22:00 en la Ciudad de México (1 de octubre en UTC) es de septiembre.
            ['consumo', -1, '2026-10-01 04:00:00'],
            ['consumo', -1, '2026-10-01 11:00:00'],
            ['consumo', -1, '2026-10-01 11:30:00'],
        ];
        $saldo = 0;
        foreach ($movimientos as [$tipo, $cantidad, $fecha]) {
            $saldo += $cantidad;
            MovimientoTimbreTenant::query()->forceCreate([
                'tipo' => $tipo, 'cantidad' => $cantidad, 'saldo_despues' => $saldo, 'created_at' => $fecha, 'updated_at' => $fecha,
            ]);
        }
        (SaldoTimbresTenant::query()->orderBy('id')->first() ?? SaldoTimbresTenant::query()->create(['disponibles' => 0]))
            ->update(['disponibles' => $saldo]);
    });

    $timbres = timbresDe($e);
    $porMes = collect($timbres['resumen']['por_mes'])->keyBy('mes');

    expect($timbres['disponibles'])->toBe(71)
        ->and($timbres['resumen'])
        ->usados_mes->toBe(2)
        ->usados_mes_anterior->toBe(12)
        // Promedio de julio, agosto y septiembre (6, 9 y 12): alcanzan para 7 meses.
        ->promedio_mensual->toBe(9)
        ->alcanza_meses->toBe(7)
        ->and($porMes)->toHaveCount(12)
        ->and($porMes->keys()->first())->toBe('2025-11')
        ->and($porMes->keys()->last())->toBe('2026-10')
        ->and($porMes['2026-06'])->toMatchArray(['usados' => 0, 'comprados' => 100])
        ->and($porMes['2026-09'])->toMatchArray(['usados' => 12, 'comprados' => 0]);
});

it('cada movimiento trae su factura o su compra; se filtran por tipo, fecha y cliente y se exportan', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_timbres', 'url' => 'https://checkout.stripe.com/c/pay/cs_timbres'])]);
    cargarDatosFiscales($e, timbres: 0);
    $url = "/api/v1/app/{$e['slug']}/timbres";
    $dueno = conBearer($e['bearer']);
    $this->postJson("{$url}/comprar", ['cantidad' => 50], $dueno)->assertCreated();
    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_timbres', 'payment_status' => 'paid']],
    ])->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", facturaDeUnPaquete(), $dueno)->assertCreated();
    $otra = facturaDeUnPaquete();
    $otra['receptor'] = ['nombre' => 'Laura Méndez', 'rfc' => 'MELA800101AB1', 'codigo_postal' => '03100'];
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", $otra, $dueno)->assertCreated();

    $lista = $this->getJson("{$url}/movimientos", $dueno)->assertOk();
    expect($lista->json('meta.total'))->toBe(3)
        ->and($lista->json('data.0.tipo'))->toBe('consumo')
        ->and($lista->json('data.0.factura.cliente'))->toBe('Laura Méndez')
        ->and($lista->json('data.0.factura.rfc'))->toBe('MELA800101AB1')
        ->and($lista->json('data.0.factura.total_minor'))->toBeGreaterThan(0)
        ->and($lista->json('data.2.tipo'))->toBe('compra')
        ->and($lista->json('data.2.compra.monto_minor'))->toBe(10440)
        ->and($lista->json('data.2.factura'))->toBeNull();

    $this->getJson("{$url}/movimientos?tipo=compra", $dueno)->assertOk()->assertJsonPath('meta.total', 1);
    expect(collect($this->getJson("{$url}/movimientos?q=laura", $dueno)->assertOk()->json('data'))->pluck('factura.cliente')->all())
        ->toBe(['Laura Méndez']);
    $this->getJson("{$url}/movimientos?desde=2026-10-02", $dueno)->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("{$url}/movimientos?desde=2026-10-02&hasta=2026-10-01", $dueno)->assertUnprocessable();
    $this->getJson("{$url}/movimientos?tipo=otro", $dueno)->assertUnprocessable();

    $csv = $this->get("{$url}/movimientos?formato=csv", $dueno)->assertOk();
    expect((string) $csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and((string) $csv->getContent())->toContain('Fecha,Movimiento,Cantidad,Saldo,Cliente')
        ->toContain('Laura Méndez')
        ->toContain('Compra');
});

it('quien está acotado a una sede ve los timbres, pero no a quién se facturó', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $sedeA = agendaSemilla($e);
    agendaSemilla($e);
    cargarDatosFiscales($e, timbres: 3);
    $this->postJson("/api/v1/app/{$e['slug']}/facturas", facturaDeUnPaquete(), conBearer($e['bearer']))->assertCreated();
    $rol = $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Contabilidad', 'permisos' => ['facturacion.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    $conta = conBearer(personalConSesion($e['slug'], $e['bearer'], 'conta@correo.mx', $rol['clave']));
    asignarSucursal($e, usuarioIdPorEmail($e, 'conta@correo.mx'), $sedeA['sucursal']);
    $url = "/api/v1/app/{$e['slug']}/timbres/movimientos";

    $fila = $this->getJson($url, $conta)->assertOk()->json('data.0');
    expect($fila['tipo'])->toBe('consumo')
        ->and($fila['factura']['cliente'])->toBeNull()
        ->and($fila['factura']['rfc'])->toBeNull();
    // Tampoco busca por cliente: la búsqueda no aplica.
    $this->getJson("{$url}?q=cliente", $conta)->assertOk()->assertJsonPath('meta.total', 2);
});

it('una compra sin pagar queda pendiente y su pago vuelve a la pantalla de timbres', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_timbres', 'url' => 'https://checkout.stripe.com/c/pay/cs_timbres'])]);
    $url = "/api/v1/app/{$e['slug']}/timbres";

    expect(timbresDe($e)['compra_pendiente'])->toBeNull();
    $this->postJson("{$url}/comprar", ['cantidad' => 100, 'retorno' => 'https://otro.sitio/renta'], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->postJson("{$url}/comprar", ['cantidad' => 100, 'retorno' => '/renta/timbres'], conBearer($e['bearer']))->assertCreated();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/checkout/sessions')
        && str_contains(urldecode($r->body()), '/renta/timbres?pago=exito'));
    // $180 + IVA.
    expect(timbresDe($e)['compra_pendiente'])->cantidad->toBe(100)->monto_minor->toBe(20880);
});
