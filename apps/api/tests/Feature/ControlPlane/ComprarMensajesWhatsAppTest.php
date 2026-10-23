<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| La compra de mensajes de WhatsApp (ADR 0186): en México, en pesos con IVA; fuera de
| México, como la renta, en dólares y sin IVA (exportación de servicios, ADR 0107).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un negocio con WhatsApp encendido, que paga sus mensajes, y Stripe de la plataforma.
 *
 * @return array{slug: string, bearer: string}
 */
function negocioQueCompraMensajes(string $slug): array
{
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::preventStrayRequests();
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'cs_mensajes', 'url' => 'https://checkout.stripe.com/c/pay/cs_mensajes']),
        'graph.facebook.com/*' => Http::response(['data' => []]),
    ]);
    test()->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => true, 'duenos' => false, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
        'app_secret' => 'secreto-de-la-app',
    ], conPlataforma())->assertOk();
    test()->putJson("/api/v1/plataforma/estudios/{$slug}/whatsapp", ['habilitado' => true], conPlataforma())->assertOk();
    test()->putJson('/api/v1/plataforma/parametros', ['valores' => ['whatsapp.cobro_por_mensaje' => 1]], conPlataforma())->assertOk();

    return $e;
}

it('en México se compran en pesos con IVA', function (): void {
    $e = negocioQueCompraMensajes('pilates-mx');

    $this->getJson("/api/v1/app/{$e['slug']}/whatsapp/saldo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.moneda', 'MXN')
        ->assertJsonPath('data.precio_minor', 45);
    // 100 × $0.45 = $45 + 16 % de IVA.
    $this->postJson("/api/v1/app/{$e['slug']}/whatsapp/comprar", ['cantidad' => 100], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.monto_minor', 5220)
        ->assertJsonPath('data.moneda', 'MXN');
    $cargo = CargoRenta::query()->sole();
    expect($cargo->desglose)->toMatchArray(['iva_porcentaje' => 16, 'iva_minor' => 720, 'moneda' => 'MXN']);
});

it('fuera de México se compran en dólares y sin IVA, también en su recibo', function (): void {
    $e = negocioQueCompraMensajes('pilates-co');
    Estudio::query()->where('slug', $e['slug'])->update(['pais' => 'CO']);
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['whatsapp.precio_mensaje_usd_centavos' => 3]], conPlataforma())->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/whatsapp/saldo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.moneda', 'USD')
        ->assertJsonPath('data.precio_minor', 3)
        ->assertJsonPath('data.paquetes.0.precio_minor', 300);
    // 100 × US$0.03 = US$3, sin IVA.
    $this->postJson("/api/v1/app/{$e['slug']}/whatsapp/comprar", ['cantidad' => 100], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.monto_minor', 300)
        ->assertJsonPath('data.moneda', 'USD');
    $cargo = CargoRenta::query()->sole();
    expect($cargo->desglose)->toMatchArray(['iva_porcentaje' => 0, 'iva_minor' => 0, 'moneda' => 'USD', 'total_minor' => 300])
        ->and($cargo->moneda)->toBe('USD')
        ->and(ReciboRentaPdf::importes($cargo)['iva_porcentaje'])->toBe(0);
});
