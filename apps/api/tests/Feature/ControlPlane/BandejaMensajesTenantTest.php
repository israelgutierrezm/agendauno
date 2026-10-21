<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| La bandeja de WhatsApp e Instagram (ADR 0200): el negocio conecta su número o su
| cuenta, lo que le escriben llega a su bandeja (y solo a la suya) y lo contesta en las
| 24 horas que deja Meta. Meta va simulado.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config([
        'agendauno.bandeja.meta_app_id' => 'app-meta',
        'agendauno.bandeja.meta_app_secret' => 'secreto-meta',
        'agendauno.bandeja.whatsapp_config_id' => 'config-registro',
        'agendauno.bandeja.instagram_app_id' => 'app-ig',
        'agendauno.bandeja.instagram_app_secret' => 'secreto-ig',
        'agendauno.bandeja.verify_token' => 'verificar-bandeja',
    ]);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
    File::deleteDirectory(storage_path('framework/testing/fcm'));
});

/**
 * Meta simulado para la bandeja: anota lo que se le pidió.
 *
 * @param  list<array{metodo: string, url: string, cuerpo: array<mixed>}>  $llamadas
 */
function fingirMetaBandeja(array &$llamadas): void
{
    $enviados = 0;
    Http::fake(function (PeticionHttp $peticion) use (&$llamadas, &$enviados) {
        $llamadas[] = ['metodo' => $peticion->method(), 'url' => $peticion->url(), 'cuerpo' => $peticion->data()];
        $url = (string) strtok($peticion->url(), '?');

        return match (true) {
            str_ends_with($url, 'graph.facebook.com/v23.0/oauth/access_token') => Http::response(['access_token' => 'llave-wa']),
            str_ends_with($url, '/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with($url, '/register') => Http::response(['success' => true]),
            str_ends_with($url, 'graph.facebook.com/v23.0/7654321') => Http::response(['display_phone_number' => '+52 55 1234 5678', 'verified_name' => 'Estudio']),
            str_ends_with($url, '/7654321/messages') => Http::response(['messages' => [['id' => 'wamid.salida'.(++$enviados)]]]),
            str_ends_with($url, 'api.instagram.com/oauth/access_token') => Http::response(['access_token' => 'corta', 'user_id' => '1789']),
            str_ends_with($url, 'graph.instagram.com/access_token') => Http::response(['access_token' => 'llave-ig', 'expires_in' => 5184000]),
            str_ends_with($url, 'graph.instagram.com/refresh_access_token') => Http::response(['access_token' => 'llave-ig-2', 'expires_in' => 5184000]),
            str_ends_with($url, 'graph.instagram.com/v23.0/me') => Http::response(['user_id' => '1789', 'username' => 'estudio.cal']),
            str_ends_with($url, 'graph.instagram.com/v23.0/me/messages') => Http::response(['recipient_id' => '555', 'message_id' => 'm_salida']),
            str_ends_with($url, 'graph.instagram.com/v23.0/555') => Http::response(['name' => 'Ana Instagram']),
            // Los avisos push al equipo (FCM).
            str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3599]),
            str_contains($url, 'fcm.googleapis.com') => Http::response(['name' => 'projects/agendauno-prueba/messages/1']),
            default => Http::response(['error' => ['message' => 'no esperado: '.$url]], 500),
        };
    });
}

/**
 * Lo que Meta avisa a la plataforma, firmado con el secreto de la app.
 *
 * @param  array<string, mixed>  $aviso
 */
function avisoDeBandeja(string $ruta, array $aviso, string $secreto = 'secreto-meta'): TestResponse
{
    $cuerpo = (string) json_encode($aviso);

    return test()->call('POST', $ruta, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, $secreto),
    ], $cuerpo);
}

/**
 * Un mensaje de WhatsApp que llega al número del negocio.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function whatsappAlNegocio(string $de, string $id, string $texto, string $numero = '7654321', array $extra = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => 'waba-negocio', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $numero, 'display_phone_number' => '525512345678'],
            'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Lucía']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $texto]]],
            ...$extra,
        ]]],
    ]]];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function conectarWhatsAppDeBandeja(array $e): array
{
    return test()->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/whatsapp", [
        'codigo' => 'codigo-registro', 'waba_id' => '1234567', 'phone_number_id' => '7654321',
    ], conBearer($e['bearer']))->assertCreated()->json('data');
}

it('conecta el número de WhatsApp con el registro de Meta, sin mostrar su llave', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');

    $canales = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/canales", conBearer($e['bearer']))->assertOk()->json('data');
    expect($canales['whatsapp']['disponible'])->toBeTrue()
        ->and($canales['whatsapp']['registro'])->toBe(['app_id' => 'app-meta', 'config_id' => 'config-registro', 'version' => 'v23.0'])
        ->and($canales['whatsapp']['conexion'])->toBeNull();

    $canal = conectarWhatsAppDeBandeja($e);
    expect($canal['nombre'])->toBe('+52 55 1234 5678')->and($canal['estado'])->toBe('activo')->and($canal)->not->toHaveKey('token');
    // Se suscribió la app a su cuenta y registró el número con un PIN de 6 dígitos.
    $registro = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], '/7654321/register'));
    expect($registro['cuerpo']['pin'])->toMatch('/^\d{6}$/');
    expect(collect($llamadas)->contains(fn (array $l): bool => str_ends_with($l['url'], '/1234567/subscribed_apps')))->toBeTrue();
    // La llave queda cifrada.
    expect((string) DB::table('canales_mensajeria')->value('token'))->not->toContain('llave-wa');

    // El mismo número no se conecta a otro negocio.
    $otro = estudioConSesion('otro-bandeja', 'otro-bandeja@correo.mx');
    $this->postJson("/api/v1/app/{$otro['slug']}/bandeja/canales/whatsapp", ['codigo' => 'c', 'waba_id' => '1234567', 'phone_number_id' => '7654321'], conBearer($otro['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');
});

it('lo que escriben al número llega a su bandeja una vez, y se contesta en las 24 horas', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);

    $aviso = whatsappAlNegocio('5215511112222', 'wamid.entrada1', '¿Tienen clase mañana?');
    avisoDeBandeja('/api/v1/webhooks/whatsapp', $aviso)->assertOk()->assertJsonPath('data.bandeja', 1)->assertJsonPath('data.respuestas', 0);
    // Meta reintenta: no se duplica.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', $aviso)->assertOk()->assertJsonPath('data.bandeja', 0);
    // Sin la firma de la app, se rechaza.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', $aviso, 'otro-secreto')->assertForbidden();

    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/resumen", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.sin_leer', 1);
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista)->toHaveCount(1)
        ->and($lista[0]['contacto'])->toBe('Lucía')
        ->and($lista[0]['telefono'])->toBe('+5215511112222')
        ->and($lista[0]['no_leidos'])->toBe(1)
        ->and($lista[0]['puede_contestar'])->toBeTrue();

    $conversacion = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$lista[0]['id']}", conBearer($e['bearer']))->assertOk()->json('data');
    expect($conversacion['mensajes'])->toHaveCount(1)
        ->and($conversacion['mensajes'][0]['texto'])->toBe('¿Tienen clase mañana?')
        ->and($conversacion['mensajes'][0]['direccion'])->toBe('entrante');
    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/resumen", conBearer($e['bearer']))->assertJsonPath('data.sin_leer', 0);

    // Contestar sale del número del negocio, con su llave.
    $respuesta = $this->postJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$lista[0]['id']}/mensajes", ['texto' => 'Sí, a las 7.'], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($respuesta['direccion'])->toBe('saliente')->and($respuesta['estado'])->toBe('enviado');
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), '/7654321/messages')
        && $p->hasHeader('Authorization', 'Bearer llave-wa') && $p['to'] === '5215511112222' && $p['text']['body'] === 'Sí, a las 7.');

    // Meta avisa que se entregó.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-negocio', 'changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => '7654321'],
        'statuses' => [['id' => 'wamid.salida1', 'status' => 'delivered', 'recipient_id' => '5215511112222']],
    ]]]]]])->assertOk()->assertJsonPath('data.bandeja', 1);
    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$lista[0]['id']}", conBearer($e['bearer']))->json('data.mensajes');
    expect($mensajes[1]['estado'])->toBe('entregado');

    // Pasadas las 24 horas, Meta no deja contestar.
    $this->travel(25)->hours();
    $this->postJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$lista[0]['id']}/mensajes", ['texto' => '¿Vienes?'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['texto'], 'meta.errors');
});

it('cada mensaje llega solo al negocio de ese número; recepción contesta, quien imparte no', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $a = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    $b = estudioConSesion('otro-bandeja', 'otro-bandeja@correo.mx');
    conectarWhatsAppDeBandeja($a);

    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.x1', 'Hola'))->assertJsonPath('data.bandeja', 1);
    // A un número que no es de ningún negocio no se guarda en ninguna bandeja.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.x2', 'Hola', '999999'))->assertJsonPath('data.bandeja', 0);

    $id = (string) $this->getJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones", conBearer($a['bearer']))->json('data.0.id');
    expect($this->getJson("/api/v1/app/{$b['slug']}/bandeja/conversaciones", conBearer($b['bearer']))->assertOk()->json('data'))->toBe([]);
    $this->getJson("/api/v1/app/{$b['slug']}/bandeja/conversaciones/{$id}", conBearer($b['bearer']))->assertNotFound();

    $recepcion = personalConSesion($a['slug'], $a['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $coach = personalConSesion($a['slug'], $a['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones", conBearer($recepcion))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$a['slug']}/bandeja/canales", conBearer($recepcion))->assertForbidden();
    $this->getJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones", conBearer($coach))->assertForbidden();

    // Se archiva y se vincula con la ficha de un cliente.
    $persona = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Lucía Pérez', 'email' => 'lucia@correo.mx'], conBearer($a['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones/{$id}", ['persona_id' => $persona, 'estado' => 'archivada'], conBearer($recepcion))
        ->assertOk()->assertJsonPath('data.persona.id', $persona)->assertJsonPath('data.estado', 'archivada');
    $this->getJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones?estado=archivada&q=Pérez", conBearer($a['bearer']))->assertJsonCount(1, 'data');
    // Si vuelve a escribir, la conversación se reabre.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.x3', 'Otra pregunta'));
    $this->getJson("/api/v1/app/{$a['slug']}/bandeja/conversaciones", conBearer($a['bearer']))->assertJsonCount(1, 'data');
});

it('conecta Instagram con su inicio de sesión, recibe sus mensajes y renueva la llave', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    $slug = $e['slug'];

    $url = (string) $this->postJson("/api/v1/app/{$slug}/bandeja/canales/instagram/conectar", [], conBearer($e['bearer']))->assertOk()->json('data.url');
    expect($url)->toStartWith('https://www.instagram.com/oauth/authorize?')->toContain('instagram_business_manage_messages');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

    $destino = (string) $this->get('/api/v1/bandeja/instagram/vuelta?'.http_build_query(['code' => 'codigo-ig#_', 'state' => $consulta['state']]))
        ->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($destino, PHP_URL_QUERY), $web);
    expect($destino)->toContain('/bandeja?')->and($web['instagram'])->toBe('conectar')->and($web['codigo'])->toBe('codigo-ig');

    // Solo quien pidió conectar confirma el código: no sirve en otro negocio.
    $otro = estudioConSesion('otro-bandeja', 'otro-bandeja@correo.mx');
    $this->postJson("/api/v1/app/{$otro['slug']}/bandeja/canales/instagram/confirmar", ['codigo' => $web['codigo'], 'estado' => $web['estado']], conBearer($otro['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['codigo'], 'meta.errors');
    $canal = $this->postJson("/api/v1/app/{$slug}/bandeja/canales/instagram/confirmar", ['codigo' => $web['codigo'], 'estado' => $web['estado']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($canal['nombre'])->toBe('@estudio.cal');
    expect(collect($llamadas)->contains(fn (array $l): bool => str_contains($l['url'], 'graph.instagram.com/v23.0/me/subscribed_apps')))->toBeTrue();

    $instante = now()->getTimestampMs();
    avisoDeBandeja('/api/v1/webhooks/instagram', ['object' => 'instagram', 'entry' => [['id' => '1789', 'time' => $instante, 'messaging' => [
        ['sender' => ['id' => '555'], 'recipient' => ['id' => '1789'], 'timestamp' => $instante, 'message' => ['mid' => 'm_entrada', 'text' => 'Precio de la mensualidad?']],
        // Lo que el negocio contestó desde la app de Instagram.
        ['sender' => ['id' => '1789'], 'recipient' => ['id' => '555'], 'timestamp' => $instante + 1000, 'message' => ['mid' => 'm_eco', 'text' => 'Te paso info', 'is_echo' => true]],
    ]]]], 'secreto-ig')->assertOk()->assertJsonPath('data.procesados', 2);

    $conversacion = $this->getJson("/api/v1/app/{$slug}/bandeja/conversaciones?canal=instagram", conBearer($e['bearer']))->json('data.0');
    expect($conversacion['contacto'])->toBe('Ana Instagram')->and($conversacion['telefono'])->toBeNull();
    $detalle = $this->getJson("/api/v1/app/{$slug}/bandeja/conversaciones/{$conversacion['id']}", conBearer($e['bearer']))->json('data.mensajes');
    expect(array_column($detalle, 'direccion'))->toBe(['entrante', 'saliente']);

    $this->postJson("/api/v1/app/{$slug}/bandeja/conversaciones/{$conversacion['id']}/mensajes", ['texto' => '$900 al mes'], conBearer($e['bearer']))->assertCreated();
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), 'graph.instagram.com/v23.0/me/messages')
        && $p->hasHeader('Authorization', 'Bearer llave-ig') && $p['recipient'] === ['id' => '555']);

    // A 50 días, la llave se renueva sola.
    $this->travel(50)->days();
    $this->artisan('agendauno:renovar-instagram')->assertSuccessful();
    expect(CanalMensajeria::query()->where('canal', 'instagram')->firstOrFail()->token)->toBe('llave-ig-2');

    // Desconectar la quita; lo que llegue después ya no entra.
    $this->deleteJson("/api/v1/app/{$slug}/bandeja/canales/instagram", [], conBearer($e['bearer']))->assertNoContent();
    avisoDeBandeja('/api/v1/webhooks/instagram', ['object' => 'instagram', 'entry' => [['id' => '1789', 'messaging' => [
        ['sender' => ['id' => '555'], 'recipient' => ['id' => '1789'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm_tarde', 'text' => 'Hola?']],
    ]]]], 'secreto-ig')->assertJsonPath('data.procesados', 0);
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), function (): void {
        expect(ConversacionTenant::query()->count())->toBe(1);
    });
});

it('sin la app de Meta configurada no se ofrece', function (): void {
    config(['agendauno.bandeja.meta_app_id' => '', 'agendauno.bandeja.instagram_app_id' => '']);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');

    $canales = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/canales", conBearer($e['bearer']))->assertOk()->json('data');
    expect($canales['whatsapp']['disponible'])->toBeFalse()->and($canales['whatsapp']['registro'])->toBeNull()->and($canales['instagram']['disponible'])->toBeFalse();
    $this->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/instagram/conectar", [], conBearer($e['bearer']))->assertUnprocessable();
    $this->get('/api/v1/webhooks/instagram?hub.mode=subscribe&hub.verify_token=verificar-bandeja&hub.challenge=reto')->assertOk()->assertSee('reto');
    $this->get('/api/v1/webhooks/instagram?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=reto')->assertForbidden();
});

/**
 * Los avisos push que salieron (relay del outbox y envío de mensajes).
 *
 * @param  list<array{metodo: string, url: string, cuerpo: array<mixed>}>  $llamadas
 * @return list<array<string, mixed>>
 */
function pushesDeBandeja(array &$llamadas): array
{
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    return collect($llamadas)->filter(fn (array $l): bool => str_contains($l['url'], 'fcm.googleapis.com'))
        ->map(fn (array $l): array => (array) $l['cuerpo']['message'])->values()->all();
}

// ADR 0200 (ajuste del 2026-10-21): quien atiende la bandeja se entera en su teléfono.
it('avisa por push a quien atiende la bandeja con el primer mensaje sin leer, sin su número ni lo que escribió', function (): void {
    cuentaDeServicioFcmDePrueba();
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/dispositivos", ['token' => 'token-duena', 'plataforma' => 'ios'], conBearer($e['bearer']))->assertCreated();
    // Quien imparte no atiende la bandeja: no le llega.
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/dispositivos", ['token' => 'token-coach', 'plataforma' => 'android'], conBearer($coach))->assertCreated();
    conectarWhatsAppDeBandeja($e);

    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.p1', 'Hola, ¿cuánto cuesta?'))->assertOk();
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.p2', '¿Sigues ahí?'))->assertOk();

    $avisos = pushesDeBandeja($llamadas);
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['token'])->toBe('token-duena')
        ->and($avisos[0]['notification'])->toBe(['title' => 'Mensaje nuevo por WhatsApp', 'body' => 'Lucía te escribió. Contéstale en Mensajes.']);
    expect((string) json_encode($avisos))->not->toContain('5511112222')->not->toContain('cuánto cuesta');

    // Leída la conversación, el siguiente mensaje vuelve a avisar.
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');
    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}", conBearer($e['bearer']))->assertOk();
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.p3', 'Gracias'))->assertOk();
    expect(pushesDeBandeja($llamadas))->toHaveCount(2);
});
