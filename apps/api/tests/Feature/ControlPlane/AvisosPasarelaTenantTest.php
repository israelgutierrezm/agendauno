<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\AvisoPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Los avisos de las pasarelas en una bandeja (ADR 0173): con la firma comprobada se
| guardan y se contesta al momento; se procesan después de responder y, si algo falla,
| se reintentan cada vez más espaciado. Al quinto intento quedan «fallidos» y el
| superadmin se entera. Lo mismo con los eventos del outbox que no se despachan.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // Lo que contesta la API de Mercado Pago a cada consulta de su cobro.
    $this->mp = (object) ['respuestas' => []];
    $mp = $this->mp;
    Http::fake(function (Request $request) use ($mp) {
        $ruta = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/sessions')) {
            return Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);
        }
        if ($request->method() === 'POST' && str_ends_with($ruta, '/checkout/preferences')) {
            return Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-1']);
        }
        if (preg_match('#/v1/payments/(\d+)$#', $ruta) === 1) {
            $respuesta = array_shift($mp->respuestas) ?? ['status' => 500, 'cuerpo' => []];

            return Http::response($respuesta['cuerpo'], $respuesta['status']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un alumno abre el pago en línea de un paquete con esa pasarela.
 *
 * @param  array<string, string>  $llaves
 * @return array{slug: string, bearer: string, pago: string}
 */
function pagoParaAvisoPasarela(string $proveedor, array $llaves): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/{$proveedor}", ['activa' => true, 'modo' => 'test', 'credenciales' => $llaves], conBearer($e['bearer']))->assertOk();
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", ['metodo' => 'tarjeta'], conBearer($a['bearer']))
        ->assertCreated()->json('data.pago');

    return [...$e, 'pago' => $pago];
}

/** @return array{avisos: list<array{estado: string, intentos: int, tipo: string|null}>, pago: string} */
function estadoDeLosAvisos(string $slug, string $pago): array
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), fn (): array => [
        'avisos' => AvisoPasarelaTenant::query()->orderBy('id')->get()
            ->map(fn (AvisoPasarelaTenant $a): array => ['estado' => $a->estado, 'intentos' => $a->intentos, 'tipo' => $a->tipo])->all(),
        'pago' => PagoTenant::query()->where('ulid', $pago)->firstOrFail()->estado->value,
    ]);
}

it('Stripe: con la firma comprobada se guarda, se contesta y se procesa; el mismo aviso no se guarda dos veces', function (): void {
    $c = pagoParaAvisoPasarela('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_prueba']);
    $url = "/api/v1/webhooks/tenant/{$c['slug']}/stripe";
    $cuerpo = (string) json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']]]);
    $firmar = function (string $cuerpo): array {
        $t = now()->getTimestamp();

        return ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$cuerpo}", 'whsec_prueba'), 'Content-Type' => 'application/json'];
    };

    // Sin la firma correcta no se guarda nada.
    $this->call('POST', $url, [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=mala', 'CONTENT_TYPE' => 'application/json'], $cuerpo)->assertStatus(400);
    expect(estadoDeLosAvisos($c['slug'], $c['pago'])['avisos'])->toBe([]);

    $servidor = fn (array $cabeceras): array => collect($cabeceras)->mapWithKeys(fn (string $v, string $k): array => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    $this->call('POST', $url, [], [], [], $servidor($firmar($cuerpo)), $cuerpo)->assertOk();
    $this->call('POST', $url, [], [], [], $servidor($firmar($cuerpo)), $cuerpo)->assertOk();

    expect(estadoDeLosAvisos($c['slug'], $c['pago']))->toBe([
        'avisos' => [['estado' => 'procesado', 'intentos' => 0, 'tipo' => 'checkout.session.completed']],
        'pago' => 'aprobado',
    ]);
});

it('si procesarlo falla se reintenta cada vez más espaciado y, al pasar, confirma el pago', function (): void {
    $c = pagoParaAvisoPasarela('mercadopago', ['access_token' => 'APP_USR-prueba']);
    $url = "/api/v1/webhooks/tenant/{$c['slug']}/mercadopago?type=payment&data_id=701";
    $aprobado = ['status' => 200, 'cuerpo' => ['id' => 701, 'status' => 'approved', 'external_reference' => $c['pago'], 'transaction_amount' => 899.0]];

    // Mercado Pago no responde: se contesta igual y queda para reintentar en 2 minutos.
    $this->postJson($url)->assertOk();
    expect(estadoDeLosAvisos($c['slug'], $c['pago']))->toMatchArray([
        'avisos' => [['estado' => 'pendiente', 'intentos' => 1, 'tipo' => 'payment']], 'pago' => 'pendiente',
    ]);

    // Antes de tiempo no se reintenta; a los 2 minutos sí (y vuelve a fallar: 4 más).
    $this->artisan('agendauno:procesar-avisos-pasarela')->expectsOutputToContain('procesados: 0')->assertSuccessful();
    expect(estadoDeLosAvisos($c['slug'], $c['pago'])['avisos'][0]['intentos'])->toBe(1);
    $this->travel(3)->minutes();
    $this->artisan('agendauno:procesar-avisos-pasarela')->assertSuccessful();
    expect(estadoDeLosAvisos($c['slug'], $c['pago'])['avisos'][0]['intentos'])->toBe(2);

    $this->mp->respuestas = [$aprobado];
    $this->travel(5)->minutes();
    $this->artisan('agendauno:procesar-avisos-pasarela')->expectsOutputToContain('procesados: 1')->assertSuccessful();
    expect(estadoDeLosAvisos($c['slug'], $c['pago']))->toMatchArray([
        'avisos' => [['estado' => 'procesado', 'intentos' => 2, 'tipo' => 'payment']], 'pago' => 'aprobado',
    ]);
});

it('al quinto intento queda fallido y el superadmin se entera', function (): void {
    $c = pagoParaAvisoPasarela('mercadopago', ['access_token' => 'APP_USR-prueba']);
    $this->postJson("/api/v1/webhooks/tenant/{$c['slug']}/mercadopago?type=payment&data_id=701")->assertOk();

    foreach ([2, 4, 8, 16] as $minutos) {
        $this->travel($minutos + 1)->minutes();
        $this->artisan('agendauno:procesar-avisos-pasarela')->assertSuccessful();
    }

    expect(estadoDeLosAvisos($c['slug'], $c['pago'])['avisos'])->toBe([['estado' => 'fallido', 'intentos' => 5, 'tipo' => 'payment']]);
    $alerta = AlertaPlataforma::query()->where('tipo', 'aviso_pasarela_fallido')->firstOrFail();
    expect($alerta->estudio)->toBe('estudio-a')
        ->and($alerta->mensaje)->toContain('Mercado Pago (payment)')->toContain('5 intentos');

    // Ya terminado, no se vuelve a intentar.
    $this->travel(1)->hour();
    $this->artisan('agendauno:procesar-avisos-pasarela')->expectsOutputToContain('procesados: 0')->assertSuccessful();
});

it('un evento del outbox que no se despacha avisa al superadmin y se puede reintentar ya corregido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $falla = true;
    Event::listen(EventoDeDominioTenant::class, function () use (&$falla): void {
        if ($falla) {
            throw new RuntimeException('La plantilla está rota.');
        }
    });
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => app(RegistrarEventoTenant::class)->registrar('prueba.ocurrio', 'prueba', null));
    $intentos = fn (): int => app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): int => (int) EventoOutboxTenant::query()->where('tipo', 'prueba.ocurrio')->value('intentos'));

    foreach (range(1, 6) as $_) {
        $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    }
    expect($intentos())->toBe(5);
    $alerta = AlertaPlataforma::query()->where('tipo', 'evento_outbox_aparcado')->where('clave', 'estudio-a:prueba.ocurrio')->firstOrFail();
    expect($alerta->veces)->toBe(1)
        ->and($alerta->mensaje)->toContain('prueba.ocurrio')->toContain('La plantilla está rota.');

    $falla = false;
    $this->artisan('agendauno:despachar-outbox', ['--reintentar-aparcados' => true])
        ->expectsOutputToContain('Eventos aparcados de vuelta en la fila:')
        ->assertSuccessful();
    expect(app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): bool => EventoOutboxTenant::query()->where('tipo', 'prueba.ocurrio')->whereNotNull('publicado_en')->exists()))
        ->toBeTrue();
});
