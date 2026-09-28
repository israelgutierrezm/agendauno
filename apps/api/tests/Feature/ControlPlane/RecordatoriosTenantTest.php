<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Recordatorios de clases y citas: 24 h y 2 h antes del inicio, una sola vez por
| reserva, con las plantillas de correo que trae cada estudio (editables).
| La sesión de prueba es el jueves 1 de octubre a las 08:00 de CDMX (14:00 UTC).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Reserva confirmada de "Bea" (con correo) en la clase del 1 de octubre, hecha en
 * la fecha indicada (UTC).
 *
 * @return array{slug: string, bearer: string, reserva: string}
 */
function reservaConCorreo(string $reservadaEn): array
{
    test()->travelTo($reservadaEn);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Bea', 'email' => 'bea@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 08:00:00');
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    return [...$e, 'reserva' => $reserva];
}

/**
 * Corre el aviso y los relays a esa hora (UTC); devuelve los recordatorios generados
 * (la reserva también genera su confirmación, que aquí no cuenta).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array<string, mixed>>
 */
function recordarA(array $e, string $ahora): array
{
    test()->travelTo($ahora);
    test()->artisan('agendauno:enviar-recordatorios')->assertSuccessful();
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $mensajes = test()->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');

    return array_values(array_filter($mensajes, fn (array $m): bool => esRecordatorio((string) $m['asunto'])));
}

function esRecordatorio(string $asunto): bool
{
    return str_starts_with($asunto, 'Recordatorio:') || str_starts_with($asunto, 'Hoy a las');
}

it('manda el correo de 24 h y el de 2 h, cada uno una sola vez', function (): void {
    Mail::fake();
    $e = reservaConCorreo('2026-09-28 12:00:00');

    // Cada estudio trae los dos correos activos, editables en Automáticos.
    $plantillas = collect($this->getJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", conBearer($e['bearer']))->json('data'));
    expect($plantillas->where('activo', true)->where('canal', 'email')->pluck('clave')->all())
        ->toContain('reserva.recordatorio_24h', 'reserva.recordatorio_2h');

    // Un día antes (menos 10 min): el de 24 h, con fecha y hora locales.
    $mensajes = recordarA($e, '2026-09-30 14:10:00');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['destinatario'])->toBe('bea@correo.mx')
        ->and($mensajes[0]['asunto'])->toBe('Recordatorio: Nivel 1 el jueves 1 de octubre a las 08:00');

    // Otra corrida en la misma ventana no lo repite.
    expect(recordarA($e, '2026-09-30 14:15:00'))->toHaveCount(1);

    // Dos horas antes: el de 2 h.
    $mensajes = recordarA($e, '2026-10-01 12:05:00');
    expect($mensajes)->toHaveCount(2)
        ->and(collect($mensajes)->pluck('asunto'))->toContain('Hoy a las 08:00: Nivel 1');

    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $mail): bool => str_starts_with($mail->asuntoMensaje, 'Recordatorio:'));
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $mail): bool => str_starts_with($mail->asuntoMensaje, 'Hoy a las'));
});

it('quien reserva el mismo día solo recibe el de 2 h', function (): void {
    $e = reservaConCorreo('2026-09-30 20:00:00'); // 18 h antes

    expect(recordarA($e, '2026-09-30 20:10:00'))->toHaveCount(0);

    $mensajes = recordarA($e, '2026-10-01 12:30:00');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['asunto'])->toBe('Hoy a las 08:00: Nivel 1');
});

it('si el aviso se atrasa no llegan los dos juntos', function (): void {
    $e = reservaConCorreo('2026-09-28 12:00:00');

    // Primera corrida ya dentro de las 2 h: solo el de 2 h.
    $mensajes = recordarA($e, '2026-10-01 13:00:00');
    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]['asunto'])->toBe('Hoy a las 08:00: Nivel 1');
});

it('una reserva cancelada o una plantilla apagada no mandan recordatorio', function (): void {
    $e = reservaConCorreo('2026-09-28 12:00:00');

    // Se apaga el de 24 h.
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.recordatorio_24h', 'canal' => 'email',
        'asunto' => 'X', 'cuerpo' => 'Y', 'activo' => false,
    ], conBearer($e['bearer']))->assertCreated();
    expect(recordarA($e, '2026-09-30 14:10:00'))->toHaveCount(0);

    // Se cancela antes del de 2 h.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$e['reserva']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(recordarA($e, '2026-10-01 12:05:00'))->toHaveCount(0);
});
