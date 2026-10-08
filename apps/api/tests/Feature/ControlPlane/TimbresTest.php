<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
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
