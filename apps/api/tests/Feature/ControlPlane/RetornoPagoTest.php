<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Pasarelas\RetornoPago;
use Illuminate\Http\Client\Request as PeticionSaliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Regreso de la pasarela de pago: el cliente vuelve al sitio desde el que inició el
| pago (el subdominio de su negocio, donde tiene su sesión) si es uno de los nuestros.
| Un origen ajeno, o ninguno (la app móvil), vuelve a url_app como siempre.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.url_app', 'https://agendauno.mx');
    Config::set('agendauno.dominio_base', 'agendauno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un cliente con una compra pendiente en un negocio que cobra con Stripe (simulado).
 *
 * @return array{slug: string, orden: string, cliente: string}
 */
function compraDelClienteParaRetorno(): array
{
    Http::fake(['api.stripe.com/*' => Http::response([
        'id' => 'cs_retorno_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_retorno_1',
    ])]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarCobroEnLinea($e);
    $pack = crearPackTenant($e, 8000);
    $cliente = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($cliente['bearer']))->assertCreated()->json('data.id');

    return ['slug' => $e['slug'], 'orden' => $orden, 'cliente' => $cliente['bearer']];
}

it('regresa del pago al subdominio desde el que se pagó y a url_app si el origen es ajeno o no viene', function (array $encabezados, string $sitio): void {
    $c = compraDelClienteParaRetorno();

    test()->postJson("/api/v1/app/{$c['slug']}/mi/ordenes/{$c['orden']}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ], [...conBearer($c['cliente']), ...$encabezados])
        ->assertCreated()
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_retorno_1');

    Http::assertSent(fn (PeticionSaliente $r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['success_url'] === $sitio.'/mi-cuenta?pago=exito'
        && $r['cancel_url'] === $sitio.'/mi-cuenta?pago=cancelado');
})->with([
    'desde el subdominio del negocio' => [['Origin' => 'https://estudio-a.agendauno.mx'], 'https://estudio-a.agendauno.mx'],
    'sin Origin, por el Referer' => [['Referer' => 'https://estudio-a.agendauno.mx/mi-cuenta?seccion=compras'], 'https://estudio-a.agendauno.mx'],
    'desde el dominio raíz' => [['Origin' => 'https://agendauno.mx'], 'https://agendauno.mx'],
    'desde un sitio ajeno' => [['Origin' => 'https://otro-sitio.com'], 'https://agendauno.mx'],
    'desde un sitio que imita el dominio' => [['Origin' => 'https://estudio-a.agendauno.mx.otro-sitio.com'], 'https://agendauno.mx'],
    'sin origen (la app móvil)' => [[], 'https://agendauno.mx'],
]);

it('regresa al dueño a su subdominio después de pagar la renta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma();
    $cargo = cargoRentaPendiente($e);

    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], [
        ...conBearer($e['bearer']), 'Origin' => 'https://estudio-a.agendauno.mx',
    ])->assertCreated();

    Http::assertSent(fn (PeticionSaliente $r): bool => str_ends_with($r->url(), '/checkout/sessions')
        && $r['success_url'] === 'https://estudio-a.agendauno.mx/renta?pago=exito');
});

it('en producción solo admite los sitios del negocio con https y en el puerto de siempre', function (string $origen, string $sitio): void {
    app()->detectEnvironment(fn (): string => 'production');
    $peticion = Request::create('/', 'POST', server: ['HTTP_ORIGIN' => $origen]);

    expect(RetornoPago::origen($peticion))->toBe($sitio);
})->with([
    'https del subdominio' => ['https://estudio-a.agendauno.mx', 'https://estudio-a.agendauno.mx'],
    'http del subdominio' => ['http://estudio-a.agendauno.mx', 'https://agendauno.mx'],
    'otro puerto' => ['https://estudio-a.agendauno.mx:8443', 'https://agendauno.mx'],
    'un dominio que termina igual' => ['https://falsoagendauno.mx', 'https://agendauno.mx'],
    'dos niveles de subdominio' => ['https://a.b.agendauno.mx', 'https://agendauno.mx'],
]);
