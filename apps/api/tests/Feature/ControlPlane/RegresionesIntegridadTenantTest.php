<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Support\Facades\File;

/*
| Bloque P0.A del roadmap: regresiones de integridad/seguridad del plano tenant.
| Ver docs/audits/agendauno-roadmap.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cancelar una sesion cancela sus reservas y libera los holds (no deja creditos colgados)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $disponible = fn (string $persona): int => (int) test()
        ->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.disponible');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();
    expect($disponible($vp['persona']))->toBe(7000); // hold colocado

    // Cancelar la sesion (negocio) debe cancelar la reserva y liberar el hold.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');

    // El credito retenido vuelve: disponible restaurado (antes quedaba colgado en 7000).
    expect($disponible($vp['persona']))->toBe(8000);

    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($roster)->where('estado', 'confirmada'))->toHaveCount(0);
});

it('cancelar una sesion es idempotente (cancelarla dos veces no falla)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('en produccion el webhook tenant rechaza confirmaciones sin firma (no-Stripe)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    app()->detectEnvironment(fn (): string => 'production');

    // Sin verificador de firma para OpenPay en el plano tenant: en produccion se rechaza.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/openpay", ['referencia' => 'ref-forjada'])
        ->assertStatus(400);
});

it('en produccion el webhook Stripe sin webhook_secret configurado se rechaza', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    app()->detectEnvironment(fn (): string => 'production');

    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_x']],
    ])->assertStatus(400);
});

it('fuera de produccion el webhook sigue permitiendo la simulacion por referencia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // En el entorno de pruebas (no produccion) no se bloquea: responde ok.
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/openpay", ['referencia' => ''])
        ->assertOk()->assertJsonPath('data.ok', true);
});

it('la promocion de lista de espera consume el costo real de la reserva, no un valor fijo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $a = venderPackAMiembroTenant($e, 8000, 'Ana');
    $b = venderPackAMiembroTenant($e, 8000, 'Beto');
    $sesion = crearSesionTenant($e, $semilla, capacidad: 1);

    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($a, $b, $sesion): void {
        $svc = app(ReservasTenant::class);
        $sesionM = SesionTenant::query()->where('ulid', $sesion)->firstOrFail();
        $pa = PersonaTenant::query()->where('ulid', $a['persona'])->firstOrFail();
        $pb = PersonaTenant::query()->where('ulid', $b['persona'])->firstOrFail();

        // Reservas con costo personalizado de 2000 (no el 1000 por defecto).
        $ra = $svc->crear($sesionM, $pa, null, false, 2000);
        $rb = $svc->crear($sesionM, $pb, null, true, 2000);
        expect($rb->costo_unidades)->toBe(2000);

        // A cancela (a tiempo) → se OFRECE el cupo a B con SU costo real (2000) de hold.
        $svc->cancelar($ra);
        $rb->refresh();
        expect($rb->estado->value)->toBe('ofrecida');
        expect($rb->unidades)->toBe(2000);

        // B acepta la oferta → confirmada, conservando el hold de 2000.
        $svc->aceptar($rb);
        $rb->refresh();
        expect($rb->estado->value)->toBe('confirmada');
        expect($rb->unidades)->toBe(2000);
    });
});

it('las rutas tenant autenticadas aplican rate limiting (throttle:tenant)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertHeader('X-RateLimit-Limit', 120);
});
