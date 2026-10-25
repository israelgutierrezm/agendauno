<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            str_ends_with($url, 'graph.instagram.com/v23.0/me/messages') => Http::response(['recipient_id' => '555', 'message_id' => 'm_salida'.(++$enviados)]),
            str_ends_with($url, 'graph.instagram.com/v23.0/555') => Http::response(['name' => 'Ana Instagram']),
            // Messenger: las páginas de quien entra con Facebook, el nombre de quien escribe
            // y lo que se manda desde la página.
            str_ends_with($url, 'graph.facebook.com/v23.0/me/accounts') => Http::response(['data' => $GLOBALS['paginas_facebook'] ?? [
                ['id' => '44556677', 'name' => 'Estudio Cal', 'access_token' => 'llave-pagina'],
            ]]),
            str_ends_with($url, 'graph.facebook.com/v23.0/9988') => Http::response(['first_name' => 'Pedro', 'last_name' => 'Páez']),
            (bool) preg_match('#graph\.facebook\.com/v23\.0/(44556677|11223344)/messages$#', $url) => Http::response(['recipient_id' => '9988', 'message_id' => 'mid.salida'.(++$enviados)]),
            // Archivos (medios de WhatsApp y adjuntos de Instagram).
            str_ends_with($url, 'graph.facebook.com/v23.0/media-foto') => Http::response(['url' => 'https://lookaside.fbsbx.com/wa/media-foto', 'mime_type' => 'image/jpeg', 'file_size' => 10]),
            str_ends_with($url, 'graph.facebook.com/v23.0/media-html') => Http::response(['url' => 'https://lookaside.fbsbx.com/wa/media-html', 'mime_type' => 'text/html', 'file_size' => 25]),
            str_ends_with($url, 'graph.facebook.com/v23.0/media-grande') => Http::response(['url' => 'https://lookaside.fbsbx.com/wa/media-grande', 'mime_type' => 'video/mp4', 'file_size' => 50 * 1024 * 1024]),
            str_ends_with($url, 'lookaside.fbsbx.com/wa/media-foto') => Http::response('FOTO-JPEG!', 200, ['Content-Type' => 'image/jpeg']),
            str_ends_with($url, 'lookaside.fbsbx.com/wa/media-html') => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'text/html']),
            str_ends_with($url, 'cdn.instagram.test/foto.jpg') => Http::response('FOTO-IG', 200, ['Content-Type' => 'image/jpeg']),
            str_ends_with($url, '/7654321/media') => Http::response(['id' => 'media-subido']),
            // Las plantillas aprobadas de la cuenta de WhatsApp del negocio.
            str_ends_with($url, '/1234567/message_templates') => Http::response(['data' => [
                ['name' => 'recordatorio_cita', 'language' => 'es_MX', 'status' => 'APPROVED', 'category' => 'UTILITY', 'parameter_format' => 'POSITIONAL', 'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Estudio Cal'],
                    ['type' => 'BODY', 'text' => 'Hola {{1}}, ¿seguimos con tu cita del {{2}}?'],
                    ['type' => 'FOOTER', 'text' => 'Contesta para hablar con nosotros'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Sí']]],
                ]],
                // Lo que el panel no pregunta: imagen en el encabezado, variables con
                // nombre y un enlace con variable.
                ['name' => 'promo_foto', 'language' => 'es_MX', 'category' => 'MARKETING', 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Promo']]],
                ['name' => 'con_nombre', 'language' => 'es_MX', 'category' => 'UTILITY', 'parameter_format' => 'NAMED', 'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}']]],
                ['name' => 'enlace', 'language' => 'es_MX', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Mira'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://estudio.mx/{{1}}']]]]],
            ]]),
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

/**
 * Un mensaje de WhatsApp con un archivo (su id de medio en Meta).
 *
 * @param  array<string, mixed>  $medio
 * @return array<string, mixed>
 */
function whatsappConArchivo(string $id, string $tipo, array $medio): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => 'waba-negocio', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '7654321'],
            'contacts' => [['wa_id' => '5215511112222', 'profile' => ['name' => 'Lucía']]],
            'messages' => [['from' => '5215511112222', 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => $tipo, $tipo => $medio]],
        ]]],
    ]]];
}

// ADR 0200 (ajuste del 2026-10-21): los archivos se guardan en el negocio y se ven
// desde el panel; uno que podría ejecutarse en el navegador solo se descarga.
it('los archivos que llegan se guardan en el negocio y se abren o se descargan desde el panel', function (): void {
    Storage::fake('local');
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);

    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappConArchivo('wamid.f1', 'image', ['id' => 'media-foto', 'mime_type' => 'image/jpeg', 'caption' => 'Mi comprobante']))->assertOk();
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappConArchivo('wamid.f2', 'document', ['id' => 'media-html', 'mime_type' => 'text/html', 'filename' => 'pagina.html']))->assertOk();
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappConArchivo('wamid.f3', 'video', ['id' => 'media-grande', 'mime_type' => 'video/mp4']))->assertOk();

    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');
    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}", conBearer($e['bearer']))->json('data.mensajes');
    expect($mensajes[0]['texto'])->toBe('Mi comprobante')
        ->and($mensajes[0]['archivo'])->toMatchArray(['mime' => 'image/jpeg', 'listo' => true, 'en_linea' => true, 'bytes' => 10])
        ->and($mensajes[1]['archivo'])->toMatchArray(['nombre' => 'pagina.html', 'listo' => true, 'en_linea' => false])
        // Más grande que el máximo de la plataforma (16 MB): no se guarda.
        ->and($mensajes[2]['archivo'])->toMatchArray(['listo' => false]);
    // Con la llave del número: el archivo de WhatsApp no es público.
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), 'lookaside.fbsbx.com/wa/media-foto') && $p->hasHeader('Authorization', 'Bearer llave-wa'));

    $foto = $this->get("/api/v1/app/{$e['slug']}/bandeja/mensajes/{$mensajes[0]['id']}/archivo", conBearer($e['bearer']))->assertOk();
    expect($foto->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and((string) $foto->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($foto->streamedContent())->toBe('FOTO-JPEG!');
    // Un HTML nunca se abre en el navegador: se descarga, sin tipo que lo ejecute.
    $html = $this->get("/api/v1/app/{$e['slug']}/bandeja/mensajes/{$mensajes[1]['id']}/archivo", conBearer($e['bearer']))->assertOk();
    expect($html->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and((string) $html->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($html->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $this->get("/api/v1/app/{$e['slug']}/bandeja/mensajes/{$mensajes[2]['id']}/archivo", conBearer($e['bearer']))->assertNotFound();

    // Quien imparte no atiende la bandeja; otro negocio no ve el archivo.
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->get("/api/v1/app/{$e['slug']}/bandeja/mensajes/{$mensajes[0]['id']}/archivo", conBearer($coach))->assertForbidden();
    $otro = estudioConSesion('otro-bandeja', 'otro-bandeja@correo.mx');
    $this->get("/api/v1/app/{$otro['slug']}/bandeja/mensajes/{$mensajes[0]['id']}/archivo", conBearer($otro['bearer']))->assertNotFound();
});

it('el equipo manda una imagen o un PDF por WhatsApp, y por Instagram solo imágenes', function (): void {
    Storage::fake('local');
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.a1', 'Mándame el temario'))->assertOk();
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');

    $pdf = UploadedFile::fake()->createWithContent('temario.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $enviado = $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}/archivos", ['archivo' => $pdf, 'texto' => 'Aquí está'], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($enviado)->toMatchArray(['direccion' => 'saliente', 'tipo' => 'document', 'texto' => 'Aquí está'])
        ->and($enviado['archivo'])->toMatchArray(['nombre' => 'temario.pdf', 'listo' => true]);
    // Se sube al número del negocio y se manda como documento con su nombre y pie.
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), '/7654321/messages') && ($p['type'] ?? null) === 'document'
        && $p['document'] === ['id' => 'media-subido', 'caption' => 'Aquí está', 'filename' => 'temario.pdf']);
    $this->get("/api/v1/app/{$e['slug']}/bandeja/mensajes/{$enviado['id']}/archivo", conBearer($e['bearer']))->assertOk();

    // Un archivo que no es imagen ni PDF no se manda.
    $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}/archivos", ['archivo' => UploadedFile::fake()->createWithContent('x.html', '<html></html>')], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertUnprocessable();

    // Instagram: solo imágenes; Meta la toma de una dirección firmada y temporal.
    $this->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/instagram/conectar", [], conBearer($e['bearer']))->assertOk();
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    CanalMensajeria::query()->create(['estudio_id' => $estudio->getKey(), 'canal' => 'instagram', 'cuenta_id' => '1789', 'nombre' => '@estudio.cal', 'token' => 'llave-ig', 'estado' => 'activo']);
    $instante = now()->getTimestampMs();
    avisoDeBandeja('/api/v1/webhooks/instagram', ['object' => 'instagram', 'entry' => [['id' => '1789', 'time' => $instante, 'messaging' => [
        ['sender' => ['id' => '555'], 'recipient' => ['id' => '1789'], 'timestamp' => $instante, 'message' => ['mid' => 'm_foto', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.instagram.test/foto.jpg']]]]],
    ]]]], 'secreto-ig')->assertOk();
    $ig = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones?canal=instagram", conBearer($e['bearer']))->json('data.0.id');
    $recibida = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$ig}", conBearer($e['bearer']))->json('data.mensajes.0');
    expect($recibida['tipo'])->toBe('image')->and($recibida['archivo']['listo'])->toBeTrue();

    $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$ig}/archivos", ['archivo' => UploadedFile::fake()->createWithContent('temario.pdf', "%PDF-1.4\n%%EOF")], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['archivo'], 'meta.errors');
    $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$ig}/archivos", ['archivo' => UploadedFile::fake()->image('foto.png', 20, 20), 'texto' => 'Así queda'], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertCreated();
    $envio = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], 'graph.instagram.com/v23.0/me/messages') && isset($l['cuerpo']['message']['attachment']));
    $url = (string) $envio['cuerpo']['message']['attachment']['payload']['url'];
    expect($url)->toContain('/api/v1/bandeja/archivos/'.$e['slug'].'/')->toContain('signature=');
    // El pie va aparte: una imagen de Instagram no lleva texto.
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), 'graph.instagram.com/v23.0/me/messages') && ($p['message']['text'] ?? null) === 'Así queda');

    // Meta toma la imagen sin sesión con la firma; alterada o vencida, no.
    $ruta = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(str_replace('signature=', 'signature=x', $ruta))->assertForbidden();
    $this->travel(20)->minutes();
    $this->get($ruta)->assertForbidden();

    // Pasadas las 24 horas, tampoco se manda un archivo.
    $this->travel(25)->hours();
    $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}/archivos", ['archivo' => UploadedFile::fake()->image('foto.jpg')], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['archivo'], 'meta.errors');
});

// La baja de datos (ARCO) borra también lo que escribió por la bandeja, si su
// conversación está ligada a su ficha, con sus archivos.
it('al atender la baja de datos se borran sus conversaciones ligadas y sus archivos', function (): void {
    Storage::fake('local');
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);
    $lucia = alumnoConSesion($e, 'Lucía', 'lucia@correo.mx');
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappConArchivo('wamid.b1', 'image', ['id' => 'media-foto', 'mime_type' => 'image/jpeg']))->assertOk();
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    $conversacion = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=lucia", conBearer($e['bearer']))->json('data.0.id');
    $this->putJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$conversacion}", ['persona_id' => $persona], conBearer($e['bearer']))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Ya no', 'password' => 'secreto123'], conBearer($lucia['bearer']))->assertCreated();
    $solicitud = (string) $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$solicitud}/atender", [], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$conversacion}", conBearer($e['bearer']))->assertNotFound();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

// Fuera de las 24 horas, Meta solo deja mandar una plantilla aprobada del número.
it('pasadas las 24 horas se contesta por WhatsApp con una plantilla aprobada del número', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.t1', 'Luego te confirmo'))->assertOk();
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');
    $this->travel(25)->hours();

    // Solo la que el panel puede llenar.
    $plantillas = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/plantillas", conBearer($e['bearer']))->assertOk()->json('data');
    expect($plantillas)->toBe([[
        'nombre' => 'recordatorio_cita', 'idioma' => 'es_MX', 'categoria' => 'utility', 'encabezado' => 'Estudio Cal',
        'cuerpo' => 'Hola {{1}}, ¿seguimos con tu cita del {{2}}?', 'pie' => 'Contesta para hablar con nosotros', 'variables' => 2,
    ]]);

    $ruta = "/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}/plantilla";
    $this->postJson($ruta, ['nombre' => 'recordatorio_cita', 'idioma' => 'es_MX', 'variables' => ['Lucía']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['variables'], 'meta.errors');
    $this->postJson($ruta, ['nombre' => 'recordatorio_cita', 'idioma' => 'es_MX', 'variables' => ["Lucía\nhola", 'martes']], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->postJson($ruta, ['nombre' => 'promo_foto', 'idioma' => 'es_MX', 'variables' => []], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['plantilla'], 'meta.errors');

    $mensaje = $this->postJson($ruta, ['nombre' => 'recordatorio_cita', 'idioma' => 'es_MX', 'variables' => ['Lucía', 'martes']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($mensaje['tipo'])->toBe('plantilla')
        ->and($mensaje['texto'])->toBe("Estudio Cal\n\nHola Lucía, ¿seguimos con tu cita del martes?\n\nContesta para hablar con nosotros");
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), '/7654321/messages') && ($p['type'] ?? null) === 'template'
        && $p['template'] === ['name' => 'recordatorio_cita', 'language' => ['code' => 'es_MX'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Lucía'], ['type' => 'text', 'text' => 'martes']]]]]);
});

/**
 * Entra con Facebook y confirma desde la web: devuelve la respuesta de confirmar.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function conectarMessengerDeBandeja(array $e): TestResponse
{
    $url = (string) test()->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/messenger/conectar", [], conBearer($e['bearer']))->assertOk()->json('data.url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
    $destino = (string) test()->get('/api/v1/bandeja/messenger/vuelta?'.http_build_query(['code' => 'codigo-fb', 'state' => $consulta['state']]))
        ->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($destino, PHP_URL_QUERY), $web);
    expect($web['messenger'])->toBe('conectar');

    return test()->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/messenger/confirmar", ['codigo' => $web['codigo'], 'estado' => $web['estado']], conBearer($e['bearer']));
}

// Facebook Messenger: la página del negocio, con el inicio de sesión de Facebook.
it('conecta la página de Facebook, recibe por Messenger y contesta con texto y archivos', function (): void {
    Storage::fake('local');
    config(['agendauno.bandeja.messenger' => true]);
    unset($GLOBALS['paginas_facebook']);
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');

    $canales = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/canales", conBearer($e['bearer']))->assertOk()->json('data');
    expect($canales['messenger'])->toBe(['disponible' => true, 'conexion' => null]);
    $url = (string) $this->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/messenger/conectar", [], conBearer($e['bearer']))->json('data.url');
    expect($url)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?')->toContain('pages_messaging');

    // Con una sola página, se conecta sin preguntar.
    $canal = conectarMessengerDeBandeja($e)->assertCreated()->json('data');
    expect($canal)->toMatchArray(['canal' => 'messenger', 'nombre' => 'Estudio Cal', 'estado' => 'activo'])->and($canal)->not->toHaveKey('token');
    Http::assertSent(fn (PeticionHttp $p): bool => str_contains($p->url(), '/44556677/subscribed_apps') && $p->hasHeader('Authorization', 'Bearer llave-pagina'));

    $instante = now()->getTimestampMs();
    avisoDeBandeja('/api/v1/webhooks/messenger', ['object' => 'page', 'entry' => [['id' => '44556677', 'time' => $instante, 'messaging' => [
        ['sender' => ['id' => '9988'], 'recipient' => ['id' => '44556677'], 'timestamp' => $instante, 'message' => ['mid' => 'mid.entrada', 'text' => '¿Hay lugar el sábado?']],
    ]]]])->assertOk()->assertJsonPath('data.procesados', 1);
    // Sin la firma de la app, se rechaza.
    avisoDeBandeja('/api/v1/webhooks/messenger', ['object' => 'page', 'entry' => []], 'otro-secreto')->assertForbidden();

    $conversacion = $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones?canal=messenger", conBearer($e['bearer']))->json('data.0');
    expect($conversacion)->toMatchArray(['canal' => 'messenger', 'contacto' => 'Pedro Páez', 'telefono' => null, 'puede_contestar' => true]);

    $this->postJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$conversacion['id']}/mensajes", ['texto' => 'Sí, a las 10.'], conBearer($e['bearer']))->assertCreated();
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), 'graph.facebook.com/v23.0/44556677/messages')
        && $p->hasHeader('Authorization', 'Bearer llave-pagina') && $p['recipient'] === ['id' => '9988']
        && $p['messaging_type'] === 'RESPONSE' && $p['message'] === ['text' => 'Sí, a las 10.']);

    // Un PDF sale como archivo que Meta toma de la dirección firmada.
    $this->post("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$conversacion['id']}/archivos", ['archivo' => UploadedFile::fake()->createWithContent('temario.pdf', "%PDF-1.4\n%%EOF")], [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertCreated();
    $envio = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], '/44556677/messages') && isset($l['cuerpo']['message']['attachment']));
    expect($envio['cuerpo']['message']['attachment']['type'])->toBe('file')
        ->and((string) $envio['cuerpo']['message']['attachment']['payload']['url'])->toContain('/api/v1/bandeja/archivos/');
    $archivo = (string) $envio['cuerpo']['message']['attachment']['payload']['url'];
    $this->get((string) parse_url($archivo, PHP_URL_PATH).'?'.parse_url($archivo, PHP_URL_QUERY))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->deleteJson("/api/v1/app/{$e['slug']}/bandeja/canales/messenger", [], conBearer($e['bearer']))->assertNoContent();
    Http::assertSent(fn (PeticionHttp $p): bool => $p->method() === 'DELETE' && str_contains($p->url(), '/44556677/subscribed_apps'));
});

it('fuera de las 24 horas, Messenger se contesta como persona del equipo hasta 7 días (HUMAN_AGENT)', function (): void {
    config(['agendauno.bandeja.messenger' => true]);
    unset($GLOBALS['paginas_facebook']);
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarMessengerDeBandeja($e)->assertCreated();
    $instante = now()->getTimestampMs();
    avisoDeBandeja('/api/v1/webhooks/messenger', ['object' => 'page', 'entry' => [['id' => '44556677', 'time' => $instante, 'messaging' => [
        ['sender' => ['id' => '9988'], 'recipient' => ['id' => '44556677'], 'timestamp' => $instante, 'message' => ['mid' => 'mid.entrada', 'text' => '¿Hay lugar el sábado?']],
    ]]]])->assertOk();
    $conversacion = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones?canal=messenger", conBearer($e['bearer']))->json('data.0.id');
    $contestar = fn () => $this->postJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$conversacion}/mensajes", ['texto' => 'Perdón la demora: sí hay.'], conBearer($e['bearer']));

    // A las 30 horas, sin el permiso de Meta, no se puede.
    $this->travel(30)->hours();
    $contestar()->assertUnprocessable()->assertJsonPath('meta.errors.texto.0', 'Pasaron más de 24 horas desde su último mensaje: Meta no deja contestarle hasta que vuelva a escribir.');

    // Con el permiso, sale con la etiqueta.
    config(['agendauno.bandeja.agente_humano' => true]);
    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones?canal=messenger", conBearer($e['bearer']))
        ->assertJsonPath('data.0.puede_contestar', true)->assertJsonPath('data.0.como_agente', true);
    $contestar()->assertCreated();
    Http::assertSent(fn (PeticionHttp $p): bool => str_ends_with($p->url(), '/44556677/messages')
        && $p['messaging_type'] === 'MESSAGE_TAG' && $p['tag'] === 'HUMAN_AGENT');

    // Pasados los 7 días, ya no.
    $this->travel(7)->days();
    $contestar()->assertUnprocessable()->assertJsonPath('meta.errors.texto.0', 'Pasaron más de 7 días desde su último mensaje: Meta no deja contestarle hasta que vuelva a escribir.');
});

it('con varias páginas se elige una; la de otro negocio no, ni la selección de otro', function (): void {
    config(['agendauno.bandeja.messenger' => true]);
    $GLOBALS['paginas_facebook'] = [
        ['id' => '44556677', 'name' => 'Estudio Cal', 'access_token' => 'llave-pagina'],
        ['id' => '11223344', 'name' => 'Estudio Cal Sur', 'access_token' => 'llave-sur'],
    ];
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $otro = estudioConSesion('otro-bandeja', 'otro-bandeja@correo.mx');
    CanalMensajeria::query()->create([
        'estudio_id' => Estudio::query()->where('slug', $otro['slug'])->value('id'), 'canal' => 'messenger', 'cuenta_id' => '44556677',
        'nombre' => 'Estudio Cal', 'token' => 'llave-otra', 'estado' => 'activo',
    ]);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');

    $eleccion = conectarMessengerDeBandeja($e)->assertOk()->json('data');
    expect($eleccion['paginas'])->toBe([
        ['id' => '44556677', 'nombre' => 'Estudio Cal', 'ocupada' => true],
        ['id' => '11223344', 'nombre' => 'Estudio Cal Sur', 'ocupada' => false],
    ]);
    $ruta = "/api/v1/app/{$e['slug']}/bandeja/canales/messenger/pagina";
    $this->postJson($ruta, ['seleccion' => $eleccion['seleccion'], 'pagina_id' => '44556677'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');
    // La selección es de este negocio: otro no la usa.
    $this->postJson("/api/v1/app/{$otro['slug']}/bandeja/canales/messenger/pagina", ['seleccion' => $eleccion['seleccion'], 'pagina_id' => '11223344'], conBearer($otro['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['pagina_id'], 'meta.errors');
    $this->postJson($ruta, ['seleccion' => $eleccion['seleccion'], 'pagina_id' => '11223344'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.nombre', 'Estudio Cal Sur');
    // Ya se usó: no sirve otra vez.
    $this->postJson($ruta, ['seleccion' => $eleccion['seleccion'], 'pagina_id' => '11223344'], conBearer($e['bearer']))->assertUnprocessable();
    unset($GLOBALS['paginas_facebook']);
});

it('sin encender Messenger en la plataforma no se ofrece', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/bandeja/canales", conBearer($e['bearer']))->assertJsonPath('data.messenger.disponible', false);
    $this->postJson("/api/v1/app/{$e['slug']}/bandeja/canales/messenger/conectar", [], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');
});

it('una conversación larga abre con sus mensajes más recientes y carga los anteriores por tandas', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    conectarWhatsAppDeBandeja($e);
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.larga0', 'Hola'))->assertOk();
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data.0.id');
    // 250 mensajes más; los últimos 20 en el mismo segundo (se desempatan por id).
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($id): void {
        $conversacion = ConversacionTenant::query()->where('ulid', $id)->sole();
        for ($i = 1; $i <= 250; $i++) {
            MensajeConversacionTenant::query()->create([
                'conversacion_id' => $conversacion->getKey(), 'direccion' => 'entrante', 'tipo' => 'texto', 'texto' => "Mensaje {$i}",
                'estado' => 'recibido', 'enviado_en' => now()->addMinutes(min($i, 230)),
            ]);
        }
    });
    $ruta = "/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$id}";

    $recientes = $this->getJson($ruta, conBearer($e['bearer']))->assertOk()->assertJsonPath('data.hay_anteriores', true)->json('data.mensajes');
    expect($recientes)->toHaveCount(100)
        ->and($recientes[0]['texto'])->toBe('Mensaje 151')
        ->and($recientes[99]['texto'])->toBe('Mensaje 250');

    $segunda = $this->getJson("{$ruta}/mensajes?antes={$recientes[0]['id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.hay_anteriores', true)->json('data');
    expect(array_column($segunda, 'texto'))->toBe(array_map(fn (int $i): string => "Mensaje {$i}", range(51, 150)));

    $ultima = $this->getJson("{$ruta}/mensajes?antes={$segunda[0]['id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.hay_anteriores', false)->json('data');
    expect($ultima)->toHaveCount(51)
        ->and($ultima[0]['texto'])->toBe('Hola')
        ->and($ultima[50]['texto'])->toBe('Mensaje 50');

    // Un mensaje de otra conversación no sirve de referencia, y sin la referencia no se adivina.
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215533334444', 'wamid.otra', 'Otra'))->assertOk();
    $otra = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones", conBearer($e['bearer']))->json('data'))->firstWhere('id', '!=', $id)['id'];
    $deOtra = (string) $this->getJson("/api/v1/app/{$e['slug']}/bandeja/conversaciones/{$otra}", conBearer($e['bearer']))->json('data.mensajes.0.id');
    $this->getJson("{$ruta}/mensajes?antes={$deOtra}", conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['antes'], 'meta.errors');
    $this->getJson("{$ruta}/mensajes", conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['antes'], 'meta.errors');
});

it('con varias sedes, quien está acotado no ve ni liga las conversaciones de clientes de otra; sin sede no ve ninguna', function (): void {
    $llamadas = [];
    fingirMetaBandeja($llamadas);
    $e = estudioConSesion('estudio-bandeja', 'bandeja@correo.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    conectarWhatsAppDeBandeja($e);
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215511112222', 'wamid.s1', 'Hola'))->assertOk();
    avisoDeBandeja('/api/v1/webhooks/whatsapp', whatsappAlNegocio('5215533334444', 'wamid.s2', 'Buenas'))->assertOk();
    $base = "/api/v1/app/{$e['slug']}/bandeja/conversaciones";
    $porTelefono = collect($this->getJson($base, conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('telefono');
    $sinLigar = (string) $porTelefono['+5215511112222']['id'];
    $deBeto = (string) $porTelefono['+5215533334444']['id'];
    $anaA = crearMiembroEnSucursal($e, 'AnaA', $sedeA);
    $betoB = crearMiembroEnSucursal($e, 'BetoB', $sedeB);
    // El dueño liga una con Beto, cliente de la sede B.
    $this->putJson("{$base}/{$deBeto}", ['persona_id' => $betoB], conBearer($e['bearer']))->assertOk();

    // Recepción sin sede asignada (con varias): no ve ninguna (ADR 0098).
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $this->getJson($base, conBearer($recep))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$base}/{$sinLigar}", conBearer($recep))->assertForbidden();

    // De la sede A: ve la que no es de nadie, no la de Beto.
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA);
    expect(collect($this->getJson($base, conBearer($recep))->assertOk()->json('data'))->pluck('id')->all())->toBe([$sinLigar]);
    $this->getJson("{$base}/{$deBeto}", conBearer($recep))->assertForbidden();
    $this->postJson("{$base}/{$deBeto}/mensajes", ['texto' => 'Hola'], conBearer($recep))->assertForbidden();
    // No la liga con alguien de otra sede (ni se entera de su nombre); con alguien de la suya, sí.
    $this->putJson("{$base}/{$sinLigar}", ['persona_id' => $betoB], conBearer($recep))->assertForbidden();
    $this->putJson("{$base}/{$sinLigar}", ['persona_id' => $anaA], conBearer($recep))->assertOk()->assertJsonPath('data.persona.id', $anaA);
});
