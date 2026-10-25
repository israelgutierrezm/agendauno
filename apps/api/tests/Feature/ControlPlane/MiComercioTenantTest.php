<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el alumno ve el catálogo y compra un pack (orden pendiente)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);

    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($a['bearer']))
        ->assertOk()->json('data'));
    expect($catalogo->pluck('id'))->toContain($pack);

    $orden = $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data');

    expect($orden['estado'])->toBe('pendiente');
    expect($orden['total_minor'])->toBe(89900);

    // Aún sin créditos: la orden no está pagada.
    $perfil = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))->assertOk()->json('data');
    expect($perfil['derechos'])->toBe([]);
});

it('el alumno no puede auto-cobrarse en efectivo/ventanilla (sin créditos gratis)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual',
    ], conBearer($a['bearer']))->assertStatus(422)->assertJsonPath('meta.errors.proveedor.0', 'El pago en efectivo o ventanilla se registra en el negocio.');
    // Tampoco abre un depósito en ventanilla, aunque el negocio la tenga activa.
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/ventanilla", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", [
        'proveedor' => 'ventanilla',
    ], conBearer($a['bearer']))->assertStatus(422)->assertJsonPath('meta.errors.proveedor.0', 'El pago en efectivo o ventanilla se registra en el negocio.');
    expect(app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), fn (): int => PagoTenant::query()->count()))->toBe(0);

    // Sigue sin créditos.
    $perfil = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))->assertOk()->json('data');
    expect($perfil['derechos'])->toBe([]);
});

it('el alumno paga en línea; el webhook confirma, otorga créditos y ya puede reservar', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_alumno_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_alumno_1',
        ], 200),
    ]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $pack = crearPackTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $a = alumnoConSesion($e);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    // Cobro en línea: queda pendiente + checkout; sin fulfillment todavía.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", [
        'proveedor' => 'stripe', 'metodo' => 'tarjeta',
    ], conBearer($a['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_alumno_1');
    // Vuelve con su pago en la URL: la web pregunta si ya se confirmó (sin sesión, solo
    // el estado) y no dice «Pago confirmado» antes.
    $exito = (string) Http::recorded(fn ($r): bool => str_ends_with($r->url(), '/checkout/sessions'))->first()[0]['success_url'];
    expect($exito)->toContain('/mi-cuenta/pagos?pago=exito&ref_pago=');
    parse_str((string) parse_url($exito, PHP_URL_QUERY), $consulta);
    $confirmacion = "/api/v1/app/{$e['slug']}/pagos/{$consulta['ref_pago']}/confirmacion";
    $this->getJson($confirmacion)->assertOk()->assertExactJson(['data' => ['estado' => 'en_proceso']]);
    $this->getJson("/api/v1/app/{$e['slug']}/pagos/01J0000000000000000000000Z/confirmacion")->assertNotFound();
    $otro = estudioConSesion('estudio-b', 'b@correo.mx');
    $this->getJson("/api/v1/app/{$otro['slug']}/pagos/{$consulta['ref_pago']}/confirmacion")->assertNotFound();

    // Antes de reservar no hay derecho.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($a['bearer']))
        ->assertStatus(422);

    // El webhook confirma el pago -> fulfillment.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_alumno_1', 'payment_status' => 'paid']],
    ])->assertOk();

    $perfil = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))->assertOk()->json('data');
    expect($perfil['derechos'][0]['disponible'])->toBe(8000);
    $this->getJson($confirmacion)->assertOk()->assertExactJson(['data' => ['estado' => 'confirmado']]);

    // Con créditos, ya reserva: cierra el ciclo (embudo -> compra -> reserva).
    $reserva = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($a['bearer']))
        ->assertCreated()->json('data');
    expect($reserva['estado'])->toBe('confirmada');
    // La reserva identifica la clase por sesion_id + sucursal (P0 #4).
    expect($reserva['sesion_id'])->toBe($sesion);
    expect($reserva['sucursal'])->toBe('Roma Norte');
});

it('el alumno no puede cobrar una orden que no es suya (403)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $otro = crearMiembroTenant($e, 'Otro');
    // Orden del staff para OTRO alumno.
    $ordenAjena = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $otro, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $a = alumnoConSesion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$ordenAjena}/cobrar", [
        'proveedor' => 'stripe',
    ], conBearer($a['bearer']))->assertForbidden();
});

it('el historial del alumno lista sus compras', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated();

    $historial = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($a['bearer']))
        ->assertOk()->json('data'));
    expect($historial)->toHaveCount(1);
    expect($historial->first()['total_minor'])->toBe(89900);
    expect($historial->first()['lineas'][0]['producto'])->toBe('Pack 8 clases');
});

it('sin pasarela en línea el alumno no puede pagar aquí; con Stripe activo paga sin elegir pasarela', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_alumno_2', 'url' => 'https://checkout.stripe.com/c/pay/cs_alumno_2',
        ], 200),
    ]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $a = alumnoConSesion($e);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))
        ->assertOk()->assertJsonPath('data.pago_en_linea', false);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", [], conBearer($a['bearer']))
        ->assertStatus(422);

    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))
        ->assertOk()->assertJsonPath('data.pago_en_linea', true);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", ['metodo' => 'tarjeta'], conBearer($a['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.proveedor', 'stripe')
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_alumno_2');
});
