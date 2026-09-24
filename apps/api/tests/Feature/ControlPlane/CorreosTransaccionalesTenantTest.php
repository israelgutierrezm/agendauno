<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Correos transaccionales que trae cada negocio (activos y editables): confirmación de
| reserva o cita, recibo de pago y bienvenida. Salen a nombre del negocio y las
| respuestas llegan a su correo de contacto.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Mensajes de un evento tras publicar el outbox.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array<string, mixed>>
 */
function mensajesDe(array $e, string $asuntoEmpieza): array
{
    test()->artisan('turnouno:despachar-outbox')->assertSuccessful();
    $mensajes = test()->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');

    return array_values(array_filter($mensajes, fn (array $m): bool => str_starts_with((string) $m['asunto'], $asuntoEmpieza)));
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function clienteConCorreo(array $e, string $nombre = 'Bea', string $email = 'bea@correo.mx'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => $nombre, 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('confirma la reserva por correo a nombre del negocio', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = clienteConCorreo($e);
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
        ->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated();

    $mensajes = mensajesDe($e, 'Reserva confirmada');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['destinatario'])->toBe('bea@correo.mx')
        ->and($mensajes[0]['asunto'])->toBe('Reserva confirmada: Nivel 1 el jueves 1 de octubre');

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, function (MensajeMailable $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('bea@correo.mx')
            && $mail->hasFrom((string) config('mail.from.address'), 'Estudio estudio-a')
            && $mail->hasReplyTo('a@correo.mx')
            && str_contains($html, '08:00')
            && str_contains($html, 'Estudio estudio-a');
    });
});

it('quien queda en lista de espera recibe la confirmación hasta que acepta su lugar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 1, '2026-10-01 08:00:00');

    $ana = clienteConCorreo($e, 'Ana', 'ana@correo.mx');
    $bea = clienteConCorreo($e);
    foreach ([$ana, $bea] as $persona) {
        $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $pack], conBearer($e['bearer']))->assertCreated();
    }
    $reservaAna = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $reservaBea = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $bea, 'esperar' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera')->json('data.id');

    expect(collect(mensajesDe($e, 'Reserva confirmada'))->pluck('destinatario')->all())->toBe(['ana@correo.mx']);

    // Ana cancela: a Bea se le ofrece el lugar y, al aceptarlo, le llega su confirmación.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaAna}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaBea}/aceptar", [], conBearer($e['bearer']))->assertOk();

    expect(collect(mensajesDe($e, 'Reserva confirmada'))->pluck('destinatario')->sort()->values()->all())
        ->toBe(['ana@correo.mx', 'bea@correo.mx']);
});

it('el recibo lleva el detalle, el total y el método de pago', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = clienteConCorreo($e);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();

    $mensajes = mensajesDe($e, 'Recibo de tu pago');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['destinatario'])->toBe('bea@correo.mx');

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $mail): bool => $mail->asuntoMensaje === 'Recibo de tu pago en Estudio estudio-a'
        && str_contains($mail->cuerpoMensaje, 'Pack 8 clases · $899.00 MXN')
        && str_contains($mail->cuerpoMensaje, 'Total: $899.00 MXN')
        && str_contains($mail->cuerpoMensaje, 'Pagado con: Efectivo'));
});

it('da la bienvenida a quien crea su cuenta, con el enlace para entrar', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    $mensajes = mensajesDe($e, 'Te damos la bienvenida');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['destinatario'])->toBe('vale@correo.mx');

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $mail): bool => str_contains($mail->cuerpoMensaje, '/entrar?estudio=estudio-a')
        && str_contains($mail->render(), 'href="'));
});

it('un correo de prueba confirma la configuración de correo', function (): void {
    Mail::fake();

    $this->artisan('turnouno:probar-correo', ['destinatario' => 'yo@correo.mx'])->assertSuccessful();
    $this->artisan('turnouno:probar-correo', ['destinatario' => 'no-es-correo'])->assertFailed();

    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $mail): bool => $mail->hasTo('yo@correo.mx'));
});
