<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Cobros comprensibles y verificables (plan de mejoras M07): una pasarela no se
| promete lista si no puede confirmar los pagos; el modo de prueba se nota; quien ya
| inició un pago lo ve; las devoluciones quedan en la bitácora.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app()->instance('env', 'testing');
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** El negocio de la prueba en su conexión. */
function enEstudioA(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

it('las llaves de prueba no se guardan como reales y el cliente sabe que no se le cobra de verdad', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'live', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('meta.errors.modo.0', 'Estas llaves son de prueba: elige el modo de prueba o usa las llaves reales.');

    activarCobroEnLinea($e);
    $stripe = collect($this->getJson("/api/v1/app/{$e['slug']}/pasarelas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('proveedor', 'stripe');
    expect($stripe)->toMatchArray(['lista' => true, 'en_prueba' => true, 'confirma_pagos' => false]);

    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))->assertOk()
        ->assertJsonPath('data.pago_en_linea', true)
        ->assertJsonPath('data.pago_en_linea_prueba', true);
});

it('en producción, sin cómo confirmar los pagos la pasarela no se ofrece como lista', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'live', 'credenciales' => ['secret_key' => 'sk_live_x'],
    ], conBearer($e['bearer']))->assertOk();

    app()->instance('env', 'production');
    $lista = fn () => collect($this->getJson("/api/v1/app/{$e['slug']}/pasarelas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('proveedor', 'stripe');
    expect($lista())->toMatchArray(['lista' => false, 'confirma_pagos' => false, 'en_prueba' => false]);

    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'live', 'credenciales' => ['webhook_secret' => 'whsec_x'],
    ], conBearer($e['bearer']))->assertOk();
    expect($lista())->toMatchArray(['lista' => true, 'confirma_pagos' => true]);
});

it('quien ya inició un pago en línea lo ve antes de volver a pagar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarCobroEnLinea($e);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $pack = crearPackTenant($e);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($ana['bearer']))
        ->assertCreated()->json('data.id');

    $pendiente = fn () => $this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes/pendientes", conBearer($ana['bearer']))->assertOk()->json('data.0');
    expect($pendiente()['pago_en_proceso'])->toBeNull();

    // Pidió su ficha para pagar en OXXO: la orden lo dice mientras no se confirma.
    enEstudioA(fn () => PagoTenant::query()->create([
        'orden_id' => OrdenTenant::query()->where('ulid', $orden)->value('id'), 'proveedor' => 'stripe', 'metodo' => 'oxxo',
        'estado' => 'pendiente', 'monto_minor' => 89900, 'moneda' => 'MXN',
    ]));
    expect($pendiente()['pago_en_proceso'])->toMatchArray(['metodo' => 'oxxo']);
});

it('una devolución queda en la bitácora con quién, cuánto y por qué', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    compraPagadaTenant($e, crearMiembroTenant($e, 'Ana'));
    $pago = (string) enEstudioA(fn () => PagoTenant::query()->value('ulid'));

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))->assertCreated();

    $asiento = enEstudioA(fn () => AuditoriaTenant::query()->where('accion', 'pago.reembolso_solicitado')->first());
    expect($asiento)->not->toBeNull()
        ->and($asiento->entidad_id)->toBe($pago)
        ->and($asiento->motivo)->toBe('Se mudó')
        ->and($asiento->despues['monto_minor'])->toBe(89900);
});

it('un rechazo que llega después de aprobado el pago no quita lo entregado ni lo marca rechazado', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'cs_tarde', 'url' => 'https://checkout.stripe.com/c/pay/cs_tarde'], 200),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarCobroEnLinea($e);
    $ana = crearMiembroTenant($e, 'Ana');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $ana, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]]], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe', 'metodo' => 'tarjeta'], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_tarde', 'payment_status' => 'paid']]])->assertOk();
    // Fuera de orden: después llega un «falló» del mismo intento.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", ['type' => 'checkout.session.async_payment_failed', 'data' => ['object' => ['id' => 'cs_tarde']]])->assertOk();

    $estado = enEstudioA(fn () => [PagoTenant::query()->value('estado')?->value, OrdenTenant::query()->where('ulid', $orden)->value('estado')?->value]);
    expect($estado)->toBe(['aprobado', 'pagada']);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/derechos", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.disponible', 8000);
});
