<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/*
| Outbox de eventos de dominio (R39, habilitador de P1): los eventos importantes se
| ESCRIBEN en el outbox dentro de la transaccion (no se despachan en el acto); el relay
| agendauno:despachar-outbox los publica despues (at-least-once) disparando
| EventoDeDominioTenant, donde se enganchan los consumidores. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('reservar escribe el evento en el outbox y NO lo despacha hasta el relay; el relay lo publica y es idempotente', function (): void {
    Event::fake([EventoDeDominioTenant::class]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // Escrito en el outbox, pero AUN no publicado (deferido al relay).
    Event::assertNotDispatched(EventoDeDominioTenant::class);

    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    Event::assertDispatched(
        EventoDeDominioTenant::class,
        fn (EventoDeDominioTenant $ev): bool => $ev->tipo === 'reserva.creada'
            && $ev->agregadoTipo === 'reserva'
            && $ev->agregadoId === $reserva
            && $ev->payload['estado'] === 'confirmada',
    );
    // Con lugar, la reserva también queda confirmada (correo de confirmación).
    Event::assertDispatched(
        EventoDeDominioTenant::class,
        fn (EventoDeDominioTenant $ev): bool => $ev->tipo === 'reserva.confirmada' && $ev->agregadoId === $reserva,
    );

    // Segundo relay: nada que republicar (idempotente, marcado publicado_en).
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    Event::assertDispatchedTimes(EventoDeDominioTenant::class, 2);
});

it('un consumidor suscrito recibe el evento de dominio que publica el relay', function (): void {
    /** @var list<string> $recibidos */
    $recibidos = [];
    Event::listen(EventoDeDominioTenant::class, function (EventoDeDominioTenant $ev) use (&$recibidos): void {
        $recibidos[] = $ev->tipo;
    });

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect($recibidos)->toContain('reserva.creada');
});

it('un reembolso emite pago.reembolsado por el outbox', function (): void {
    Event::fake([EventoDeDominioTenant::class]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Orden de 1 pack, cobrada en efectivo (fulfillment concede el derecho).
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", ['proveedor' => 'manual'], conBearer($e['bearer']))
        ->assertCreated()->json('data.pago');

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Baja'], conBearer($e['bearer']))
        ->assertCreated();

    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    Event::assertDispatched(
        EventoDeDominioTenant::class,
        fn (EventoDeDominioTenant $ev): bool => $ev->tipo === 'pago.reembolsado'
            && $ev->agregadoTipo === 'pago'
            && $ev->agregadoId === $pago
            && $ev->payload['total'] === true,
    );
});
