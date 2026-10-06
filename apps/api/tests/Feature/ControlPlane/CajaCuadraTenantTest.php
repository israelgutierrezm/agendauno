<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| 1.7 de la fase 1: que caja y reportes cuadren. Cada movimiento cuenta el día en que
| el dinero se movió (no cuando se inició), los totales son del rango completo aunque
| la lista se corte, el CSV coincide con el reporte y nunca se suman monedas.
| Fechas en CDMX; el negocio usa esa zona.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->stripe = (object) ['devolucion' => 'succeeded'];

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/checkout/sessions') => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            str_contains($url, '/checkout/sessions/cs_1') => Http::response(['id' => 'cs_1', 'payment_intent' => 'pi_1']),
            str_ends_with($url, '/refunds') => Http::response(['id' => 're_1', 'status' => $this->stripe->devolucion]),
            default => Http::response([], 404),
        };
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Vende un producto a un alumno nuevo y lo cobra en caja (efectivo).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cobroCajaDe(array $e, string $alumno, string $producto): void
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, $alumno), 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function corte(array $e, string $dia, array $extra = []): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?".http_build_query(['desde' => $dia, 'hasta' => $dia, ...$extra]), conBearer($e['bearer']))
        ->assertOk()->json();
}

it('un pago iniciado ayer y confirmado hoy cuenta hoy, y su devolución el día en que se hizo', function (): void {
    $this->travelTo('2026-09-28 23:30:00'); // 17:30 del 28 en CDMX
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe', 'metodo' => 'tarjeta'], conBearer($e['bearer']))
        ->assertCreated()->json('data.pago');

    // Stripe lo confirma al día siguiente (29 en CDMX).
    $this->travelTo('2026-09-29 16:00:00');
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
    ])->assertOk();

    expect(corte($e, '2026-09-28')['totales']['cobrado_minor'])->toBe(0)
        ->and(corte($e, '2026-09-29')['totales']['cobrado_minor'])->toBe(89900)
        ->and(corte($e, '2026-09-29')['data'][0]['orden'])->toBe($orden);

    // La devolución queda en proceso el 29 y Stripe la confirma el 30: cuenta el 30.
    $this->stripe->devolucion = 'pending';
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))->assertCreated();
    $this->travelTo('2026-09-30 16:00:00');
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'charge.refund.updated', 'data' => ['object' => ['id' => 're_1', 'status' => 'succeeded']],
    ])->assertOk();

    expect(corte($e, '2026-09-29')['totales']['devuelto_minor'])->toBe(0)
        ->and(corte($e, '2026-09-30')['totales']['devuelto_minor'])->toBe(89900)
        ->and(corte($e, '2026-09-30')['data'][0]['pago'])->toBe($pago);
});

it('los totales son del rango completo aunque la lista se corte, y el CSV coincide', function (): void {
    $this->travelTo('2026-09-28 17:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e);
    foreach (['Ana', 'Bea', 'Caro'] as $alumno) {
        cobroCajaDe($e, $alumno, $pack);
    }

    $r = corte($e, '2026-09-28', ['limite' => 2]);
    expect($r['data'])->toHaveCount(2)
        ->and($r['meta']['truncado'])->toBeTrue()
        ->and($r['totales']['cobrado_minor'])->toBe(3 * 89900)
        ->and($r['totales']['por_metodo'])->toBe(['Efectivo' => 3 * 89900]);

    // El CSV trae todos los movimientos del rango (no se corta) y los mismos totales.
    $csv = (string) $this->get("/api/v1/app/{$e['slug']}/pagos/movimientos?desde=2026-09-28&hasta=2026-09-28&limite=2&formato=csv", conBearer($e['bearer']))
        ->assertOk()->getContent();
    $lineas = array_values(array_filter(explode("\n", $csv)));
    expect(collect($lineas)->filter(fn (string $l): bool => str_contains($l, ',Cobro,'))->count())->toBe(3)
        ->and($csv)->toContain('Total cobrado,2697.00,MXN');
});

it('no suma monedas distintas', function (): void {
    $this->travelTo('2026-09-28 17:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $claseSuelta = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Clase suelta', 'tipo' => 'paquete', 'precio_minor' => 2000,
        'ilimitado' => false, 'creditos_incluidos' => 1000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    cobroCajaDe($e, 'Ana', crearPackTenant($e));
    cobroCajaDe($e, 'Bea', $claseSuelta);
    // Hoy un negocio cobra en una sola moneda (ADR 0099), pero su historia puede traer
    // otra: ese cobro de 20 quedó en dólares.
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function (): void {
        DB::connection('tenant')->table('ordenes')->where('total_minor', 2000)->update(['moneda' => 'USD']);
        DB::connection('tenant')->table('pagos')->where('monto_minor', 2000)->update(['moneda' => 'USD']);
    });

    $porMoneda = collect(corte($e, '2026-09-28')['totales_por_moneda'])->keyBy('moneda');
    expect($porMoneda)->toHaveCount(2)
        ->and($porMoneda['MXN']['cobrado_minor'])->toBe(89900)
        ->and($porMoneda['USD']['cobrado_minor'])->toBe(2000);
});
