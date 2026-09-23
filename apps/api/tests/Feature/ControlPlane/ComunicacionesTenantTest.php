<?php

declare(strict_types=1);

use App\Modules\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Comunicaciones (R28), consumidor del outbox (R39): una plantilla por (evento, canal)
| genera un mensaje ENCOLADO (renderizado con datos del evento/persona); el relay lo
| envia (interno = bandeja in-app; email) con estados y reintentos. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function guardarPlantilla(array $e, array $datos): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", $datos, conBearer($e['bearer']))
        ->assertCreated();
}

it('un evento genera un mensaje interno renderizado desde la plantilla y el relay lo envia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    guardarPlantilla($e, [
        'clave' => 'reserva.creada', 'canal' => 'interno',
        'asunto' => 'Reserva {{estado}} de {{persona_nombre}}',
        'cuerpo' => 'Hola {{persona_nombre}}, te esperamos.',
    ]);

    $vp = venderPackAMiembroTenant($e, 8000); // persona "Ana"
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    // El relay del outbox publica reserva.creada -> se genera el mensaje (encolado).
    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');
    expect($mensajes)->toHaveCount(1);
    expect($mensajes[0]['canal'])->toBe('interno');
    expect($mensajes[0]['estado'])->toBe('encolado');
    expect($mensajes[0]['asunto'])->toBe('Reserva confirmada de Ana');
    expect($mensajes[0]['persona'])->toBe('Ana');

    // El relay de envio lo marca enviado (bandeja in-app).
    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes?estado=enviado", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($mensajes)->toHaveCount(1);
    expect($mensajes[0]['estado'])->toBe('enviado');
});

it('una plantilla de email genera y envia un correo al destinatario', function (): void {
    Mail::fake();

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    guardarPlantilla($e, [
        'clave' => 'reserva.creada', 'canal' => 'email',
        'asunto' => 'Tu reserva', 'cuerpo' => 'Hola {{persona_nombre}}',
    ]);

    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Bea', 'email' => 'bea@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $producto = crearPackTenant($e, 8000);
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))->assertCreated();
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();

    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');
    expect($mensajes[0]['canal'])->toBe('email');
    expect($mensajes[0]['destinatario'])->toBe('bea@correo.mx');

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();

    Mail::assertSent(
        MensajeMailable::class,
        fn (MensajeMailable $mail): bool => $mail->hasTo('bea@correo.mx') && $mail->asuntoMensaje === 'Tu reserva',
    );
});

it('una plantilla inactiva no genera mensajes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    guardarPlantilla($e, [
        'clave' => 'reserva.creada', 'canal' => 'interno',
        'asunto' => 'X', 'cuerpo' => 'Y', 'activo' => false,
    ]);

    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();

    $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('gestionar plantillas exige comunicaciones.gestionar; un recepcionista solo ve los mensajes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    // El recepcionista NO gestiona plantillas...
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.creada', 'canal' => 'interno', 'asunto' => 'X', 'cuerpo' => 'Y',
    ], conBearer($recep))->assertStatus(403);

    // ...pero SI puede ver el historial de mensajes.
    $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($recep))->assertOk();
});

it('los eventos de asistencia identifican a la persona por su ULID (plantillas y webhooks la encuentran)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    guardarPlantilla($e, [
        'clave' => 'asistencia.marcada', 'canal' => 'interno',
        'asunto' => 'Gracias por venir, {{persona_nombre}}', 'cuerpo' => 'Asistencia: {{estado}}',
    ]);

    $vp = venderPackAMiembroTenant($e, 8000); // persona "Ana"
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();

    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['asunto'])->toBe('Gracias por venir, Ana')
        ->and($mensajes[0]['persona'])->toBe('Ana');
});

it('los mensajes automáticos ofrecen el catálogo de eventos y rechazan uno inexistente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonFragment(['eventos_disponibles' => EventoDeDominioTenant::TIPOS]);

    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.inventada', 'canal' => 'interno', 'asunto' => 'x', 'cuerpo' => 'y',
    ], conBearer($e['bearer']))->assertStatus(422);
});
