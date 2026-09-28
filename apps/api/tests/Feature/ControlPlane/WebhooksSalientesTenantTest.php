<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Webhooks salientes (R40), primer consumidor del outbox (R39): el estudio registra
| endpoints firmados y el relay entrega los eventos de dominio (HMAC-SHA256), con
| filtrado por tipo, registro de cada entrega y reintento de las fallidas.
| Ver docs/audits/turno-uno-roadmap.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // ejemplo.test resuelve a una IP pública (sin tocar la red).
    dnsFalso(['ejemplo.test' => ['93.184.216.34']]);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function reservaEnEstudio(array $e): void
{
    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();
}

it('entrega el evento FIRMADO al endpoint suscrito y registra la entrega', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook',
        'eventos' => ['reserva.creada'], // la reserva también publica reserva.confirmada
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    $secreto = $webhook['secreto'];
    expect($secreto)->toStartWith('whsec_');

    reservaEnEstudio($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    Http::assertSent(function ($request) use ($secreto): bool {
        $firma = $request->header('X-AgendaUno-Signature')[0] ?? '';
        $esperada = 'sha256='.hash_hmac('sha256', $request->body(), $secreto);

        return str_contains($request->url(), 'ejemplo.test/hook')
            && $firma === $esperada
            && ($request->header('X-AgendaUno-Event')[0] ?? '') === 'reserva.creada';
    });

    $entregas = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}/entregas", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($entregas)->toHaveCount(1);
    expect($entregas[0]['estado'])->toBe('entregado');
    expect($entregas[0]['evento_tipo'])->toBe('reserva.creada');
    expect($entregas[0]['http_status'])->toBe(200);
});

it('no entrega eventos a los que el endpoint no esta suscrito', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook',
        'eventos' => ['pago.reembolsado'], // NO suscrito a reserva.creada
    ], conBearer($e['bearer']))->assertCreated();

    reservaEnEstudio($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    Http::assertNothingSent();
});

it('marca la entrega fallida y el comando de reintento la reenvia', function (): void {
    // Primer intento falla (500), el reintento tiene exito (200).
    Http::fake(['*' => Http::sequence()
        ->push('boom', 500)
        ->push('ok', 200)]);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook',
        'eventos' => ['reserva.creada'], // la reserva también publica reserva.confirmada
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    reservaEnEstudio($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $entregas = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}/entregas", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($entregas[0]['estado'])->toBe('fallido');
    expect($entregas[0]['intentos'])->toBe(1);

    // El endpoint ya responde bien: el reintento la entrega.
    $this->artisan('agendauno:reintentar-webhooks')->assertSuccessful();

    $entregas = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}/entregas", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($entregas[0]['estado'])->toBe('entregado');
    expect($entregas[0]['intentos'])->toBe(2);
});

it('el secreto solo se devuelve al crear (no se expone en el listado) y la gestion exige el permiso', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", ['url' => 'https://ejemplo.test/hook'], conBearer($e['bearer']))
        ->assertCreated();

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista)->toHaveCount(1);
    expect($lista[0])->not->toHaveKey('secreto');

    // Un recepcionista no configura integraciones/webhooks.
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes", conBearer($recep))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", ['url' => 'https://ejemplo.test/otro'], conBearer($recep))->assertStatus(403);
});

it('ofrece el catálogo de eventos y rechaza suscribirse a uno que no existe', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonFragment(['eventos_disponibles' => EventoDeDominioTenant::TIPOS]);

    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.mx/hook',
        'eventos' => ['reserva.inventada'],
    ], conBearer($e['bearer']))->assertStatus(422);
});

it('no acepta destinos internos, reservados o sin https (SSRF)', function (): void {
    Http::fake();
    dnsFalso([
        'ejemplo.test' => ['93.184.216.34'],
        'interno.test' => ['192.168.1.10'],
        'mixto.test' => ['93.184.216.34', '10.0.0.7'],
    ]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    foreach ([
        'http://ejemplo.test/hook',
        'https://127.0.0.1/hook',
        'https://169.254.169.254/latest/meta-data',
        'https://10.0.0.5/hook',
        'https://[::1]/hook',
        'https://[::ffff:127.0.0.1]/hook',
        'https://usuario:clave@ejemplo.test/hook',
        'https://ejemplo.test:6379/hook',
        'https://interno.test/hook',
        'https://mixto.test/hook',
        'https://no-existe.test/hook',
    ] as $url) {
        $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", ['url' => $url], conBearer($e['bearer']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'WEBHOOK_DESTINATION_NOT_ALLOWED');
    }

    $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes", conBearer($e['bearer']))->assertJsonCount(0, 'data');
    Http::assertNothingSent();
});

it('si el dominio pasa a resolver a una IP interna, no se envía nada', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook', 'eventos' => ['reserva.creada'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    // Cambia el DNS (rebinding) antes del envío.
    dnsFalso(['ejemplo.test' => ['10.0.0.8']]);
    reservaEnEstudio($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $entregas = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}/entregas", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($entregas[0]['estado'])->toBe('fallido')
        ->and($entregas[0]['ultimo_error'])->toStartWith('Destino no permitido');
    Http::assertNothingSent();
});

it('una redirección no cuenta como entregado', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook', 'eventos' => ['reserva.creada'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    reservaEnEstudio($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $entregas = $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}/entregas", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($entregas[0]['estado'])->toBe('fallido')->and($entregas[0]['http_status'])->toBe(302);
    Http::assertSentCount(1);
});
