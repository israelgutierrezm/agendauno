<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

// Helpers compartidos (conPlataforma, cargoRentaPendiente, activarStripePlataforma,
// cargarDatosFiscales) viven en tests/Pest.php.

it('el comando genera el cargo de renta y el dueño lo ve en su apartado', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    terminarPrueba($e);

    // El admin pone cuota fija (monto determinista, sin depender del uso).
    $this->putJson('/api/v1/plataforma/estudios/estudio-a', [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900,
    ], conPlataforma())->assertOk();

    $periodo = emitirCargoDelMesEnCurso();

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.modo_cobro', 'fijo')
        ->assertJsonCount(1, 'data.cargos')
        ->assertJsonPath('data.cargos.0.periodo', $periodo)
        ->assertJsonPath('data.cargos.0.monto_minor', 149900)
        ->assertJsonPath('data.cargos.0.estado', 'pendiente');
});

it('la generación es idempotente: no duplica el cargo del periodo', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $periodo = emitirCargoDelMesEnCurso();
    $this->artisan('agendauno:generar-cargos-renta', ['--periodo' => $periodo])->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data.cargos');
});

it('el apartado de renta muestra la estimación del periodo en curso', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Sin cargos generados aún: lista vacía pero con la estimación actual (modo activos, 0 alumnos).
    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.modo_cobro', 'activos')
        ->assertJsonCount(0, 'data.cargos')
        ->assertJsonPath('data.actual.cargo_estimado_minor', 0);
});

it('el apartado de renta exige permiso de facturación', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($coach))->assertStatus(403);
});

it('el dueño paga su renta con Stripe y el webhook de la plataforma lo confirma', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_renta_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_renta_123',
        ], 200),
    ]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']); // sin webhook_secret -> webhook sin firma en dev
    $cargo = cargoRentaPendiente($e);

    // El dueño paga en linea -> pendiente + página de pago de Stripe; sigue pendiente.
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.checkout.tipo', 'redirect')
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_renta_123');
    Http::assertSent(fn ($r): bool => str_ends_with($r['success_url'], '/renta?pago=exito'));

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.cargos.0.estado', 'pendiente');

    // El webhook de la plataforma confirma el intento -> pagado.
    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_renta_123', 'payment_status' => 'paid']],
    ])->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.cargos.0.estado', 'pagado')
        ->assertJsonPath('data.cargos.0.pagado_en', fn (?string $v): bool => $v !== null);
});

it('si el aviso de Stripe no llega, la conciliación confirma la renta y el reintento no cobra de nuevo', function (): void {
    // Stripe: la sesión ya se pagó, pero su aviso nunca llega.
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/cs_renta_perdida*' => Http::response([
            'id' => 'cs_renta_perdida', 'status' => 'complete', 'payment_status' => 'paid',
        ]),
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_renta_perdida', 'url' => 'https://checkout.stripe.com/c/pay/cs_renta_perdida',
        ]),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $cargo = cargoRentaPendiente($e);
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'pendiente');

    // Al volver a «Pagar», se ve que ya se pagó: se confirma y no se abre otro cobro.
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertSuccessful()->assertJsonPath('data.estado', 'pagado');
    // Una sola sesión de pago: el reintento solo preguntó cómo quedó.
    Http::assertSentCount(2);
    Http::assertSent(fn ($r): bool => $r->method() === 'GET' && str_contains($r->url(), 'cs_renta_perdida'));
});

it('la conciliación programada confirma la renta pagada cuyo aviso no llegó', function (): void {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/cs_renta_perdida*' => Http::response([
            'id' => 'cs_renta_perdida', 'status' => 'complete', 'payment_status' => 'paid',
        ]),
        'api.stripe.com/*' => Http::response([
            'id' => 'cs_renta_perdida', 'url' => 'https://checkout.stripe.com/c/pay/cs_renta_perdida',
        ]),
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $cargo = cargoRentaPendiente($e);
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", ['proveedor' => 'stripe'], conBearer($e['bearer']))
        ->assertCreated();

    // Antes de la gracia, se espera el aviso.
    $this->artisan('agendauno:conciliar-renta')->expectsOutputToContain('confirmados: 0')->assertSuccessful();
    $this->travel(11)->minutes();
    $this->artisan('agendauno:conciliar-renta')->expectsOutputToContain('confirmados: 1')->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.cargos.0.estado', 'pagado');
});

it('rechaza pagar la renta si la plataforma no tiene pasarela activa (GATEWAY_UNAVAILABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cargo = cargoRentaPendiente($e); // no se activa ninguna pasarela

    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');
});

it('el webhook de la renta es idempotente: confirma el cargo una sola vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(); // sin llaves -> intento simulado pendiente
    $cargo = cargoRentaPendiente($e);

    $ref = (string) $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))->assertCreated()->json('data.referencia');

    $payload = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $ref]]];

    $this->postJson('/api/v1/webhooks/plataforma/stripe', $payload)->assertOk();
    $primero = CargoRenta::query()->where('referencia_pago', $ref)->firstOrFail()->pagado_en;

    // Segundo webhook identico: no reprocesa (mismo pagado_en).
    $this->postJson('/api/v1/webhooks/plataforma/stripe', $payload)->assertOk();
    $segundo = CargoRenta::query()->where('referencia_pago', $ref)->firstOrFail()->pagado_en;

    expect($primero)->not->toBeNull();
    expect($segundo?->equalTo($primero))->toBeTrue();
});

it('no se puede volver a pagar un cargo ya pagado (RENT_CHARGE_NOT_PAYABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma();
    $cargo = cargoRentaPendiente($e);

    $ref = (string) $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))->assertCreated()->json('data.referencia');

    $this->postJson('/api/v1/webhooks/plataforma/stripe', [
        'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $ref]],
    ])->assertOk();

    // Ya pagado: un nuevo intento de cobro se rechaza.
    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RENT_CHARGE_NOT_PAYABLE');
});

it('el pago de la renta exige permiso de facturación', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma();
    $cargo = cargoRentaPendiente($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$cargo}/pagar", [
        'proveedor' => 'stripe',
    ], conBearer($coach))->assertStatus(403);
});

it('un negocio con un error no detiene la renta de los demás: se avisa al superadmin', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    foreach ([estudioConSesion('estudio-a', 'a@correo.mx'), estudioConSesion('estudio-b', 'b@correo.mx')] as $e) {
        terminarPrueba($e);
        $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}", ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900], conPlataforma())->assertOk();
    }
    // Un dato roto en el primero que se procesa.
    Estudio::query()->where('slug', 'estudio-a')->update(['zona_horaria' => 'Zona/Inexistente']);

    $periodo = emitirCargoDelMesEnCurso();

    expect(CargoRenta::query()->where('periodo', $periodo)->with('estudio')->get()->pluck('estudio.slug')->all())->toBe(['estudio-b']);
    $this->assertDatabaseHas('alertas_plataforma', ['tipo' => 'renta', 'clave' => 'emitir-estudio-a', 'estudio' => 'estudio-a']);
});

it('una renta mes vencido por debajo del cargo mínimo queda sin cargo', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    terminarPrueba($e);
    $this->putJson('/api/v1/plataforma/estudios/estudio-a', ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 900], conPlataforma())->assertOk();

    emitirCargoDelMesEnCurso();

    expect(CargoRenta::query()->sole())->estado->value->toBe('sin_cargo')->monto_minor->toBe(900);
});
