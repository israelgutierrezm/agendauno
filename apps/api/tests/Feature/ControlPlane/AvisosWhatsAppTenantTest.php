<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Avisos por WhatsApp (Meta Cloud API, ADR 0069). El superadministrador lo enciende o
| lo apaga para todos y lo activa negocio por negocio (ADR 0083): apagado, el negocio
| no lo ve ni se manda nada (los avisos siguen por correo y push). Activado, el negocio
| enciende avisos con el texto fijo de la plantilla aprobada, y le llegan a quien
| aceptó recibirlos y tiene celular. A quien contesta se le responde solo (ADR 0083).
| La clase de prueba es el jueves 1 de octubre a las 08:00 de CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->meta = (object) ['envios' => []];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graph.facebook.com')) {
            $this->meta->envios[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.prueba']]]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Conecta WhatsApp y enciende (o apaga) los avisos de los negocios a sus clientes.
 */
function encenderWhatsApp(bool $negocios = true, bool $duenos = false): void
{
    test()->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => $negocios, 'duenos' => $duenos, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
        'app_secret' => 'secreto-de-la-app',
    ], conPlataforma())->assertOk();
}

/**
 * El superadministrador activa (o desactiva) WhatsApp en el negocio (ADR 0083).
 *
 * @param  array{slug: string}  $e
 */
function habilitarWhatsApp(array $e, bool $habilitado = true): void
{
    test()->putJson("/api/v1/plataforma/estudios/{$e['slug']}/whatsapp", ['habilitado' => $habilitado], conPlataforma())
        ->assertOk()->assertJsonPath('data.habilitado', $habilitado);
}

/**
 * Alguien le escribe al número de AgendaUno (ADR 0083), firmado como lo manda Meta.
 *
 * @param  array<string, mixed>  $mensaje
 */
function mensajeDeMeta(string $de, array $mensaje): TestResponse
{
    $cuerpo = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Alguien']]],
            'messages' => [['from' => $de, 'timestamp' => '1790000000', ...$mensaje]],
        ]]],
    ]]]);

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, 'secreto-de-la-app'),
    ], $cuerpo);
}

/**
 * Los textos libres que salieron (las respuestas automáticas), en orden.
 *
 * @return list<array{to: string, body: string}>
 */
function respuestasEnviadas(): array
{
    return array_values(array_map(
        static fn (array $envio): array => ['to' => (string) $envio['to'], 'body' => (string) $envio['text']['body']],
        array_filter(test()->meta->envios, static fn (array $envio): bool => ($envio['type'] ?? '') === 'text'),
    ));
}

/**
 * Aviso de estados de Meta, firmado con el App Secret (ADR 0074).
 *
 * @param  list<array<string, mixed>>  $estados
 */
function avisoDeMeta(array $estados, string $secreto = 'secreto-de-la-app'): TestResponse
{
    $cuerpo = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'statuses' => $estados]]],
    ]]]);

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, $secreto),
    ], $cuerpo);
}

/**
 * @param  array{slug: string}  $e
 */
function enNegocioWhatsApp(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function avisoPorWhatsApp(array $e, string $clave = 'reserva.confirmada', bool $activo = true): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", ['clave' => $clave, 'canal' => 'whatsapp', 'activo' => $activo], conBearer($e['bearer']))
        ->assertCreated();
}

/**
 * Negocio con una clase el 1 de octubre y dos alumnas con paquete y celular: Vale
 * (con cuenta) y Caro.
 *
 * @return array{slug: string, bearer: string, alumna: string, vale: string, caro: string, sesion: string}
 */
function negocioConAlumnas(): array
{
    test()->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e);
    $vale = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $caro = crearMiembroTenant($e, 'Caro');
    foreach ([$vale => '55 1234 5678', $caro => '55 8765 4321'] as $persona => $celular) {
        test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
            ->assertCreated();
        enNegocioWhatsApp($e, fn () => PersonaTenant::query()->where('ulid', $persona)->update(['celular' => $celular]));
    }
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 08:00:00');
    habilitarWhatsApp($e);

    return [...$e, 'alumna' => $alumna['bearer'], 'vale' => $vale, 'caro' => $caro, 'sesion' => $sesion];
}

/**
 * @param  array{slug: string}  $e
 * @return list<MensajeTenant>
 */
function avisosWhatsApp(array $e): array
{
    return enNegocioWhatsApp($e, fn (): array => MensajeTenant::query()->where('canal', 'whatsapp')->orderBy('id')->get()->all());
}

it('el superadmin lo conecta y enciende cada uso por separado; el token nunca se devuelve', function (): void {
    $r = $this->getJson('/api/v1/plataforma/whatsapp', conPlataforma())->assertOk();
    expect($r->json('data'))->toMatchArray(['negocios' => false, 'duenos' => false, 'conectado' => false, 'token_configurado' => false])
        // Qué registrar en Meta: nombre y texto con {{1}}, {{2}}, …
        ->and($r->json('data.plantillas.duenos.0'))->toMatchArray(['nombre' => 'agendauno_codigo_verificacion', 'categoria' => 'AUTHENTICATION'])
        ->and($r->json('data.plantillas.negocios.0'))->toMatchArray([
            'evento' => 'reserva.confirmada',
            'nombre' => 'agendauno_reserva_confirmada',
            'idioma' => 'es_MX',
            'categoria' => 'UTILITY',
            'texto' => 'Hola {{1}}, {{2}} confirmó tu lugar en {{3}} el {{4}} a las {{5}} en {{6}}. Si no puedes asistir, cancela con tiempo desde tu cuenta.',
        ]);

    // Sin token no se puede encender.
    $this->putJson('/api/v1/plataforma/whatsapp', ['negocios' => true, 'duenos' => false, 'phone_number_id' => '109876543210'], conPlataforma())
        ->assertUnprocessable()->assertJsonValidationErrors(['phone_number_id'], 'meta.errors');

    $r = $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => true, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
    expect($r->json('data'))->toMatchArray(['negocios' => false, 'duenos' => true, 'conectado' => true, 'phone_number_id' => '109876543210', 'token_configurado' => true])
        ->and(json_encode($r->json()))->not->toContain('EAAG-token-de-prueba');
    // Con los dueños encendido, los negocios siguen sin verlo.
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    expect($this->getJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", conBearer($e['bearer']))->json('canales'))->not->toContain('whatsapp');

    // Apagarlo conserva la conexión para volver a encenderlo.
    $this->putJson('/api/v1/plataforma/whatsapp', ['negocios' => false, 'duenos' => false, 'phone_number_id' => '109876543210'], conPlataforma())
        ->assertOk()->assertJsonPath('data.duenos', false)->assertJsonPath('data.conectado', true)->assertJsonPath('data.token_configurado', true);

    // Sin el token de plataforma no se entra.
    $this->getJson('/api/v1/plataforma/whatsapp')->assertUnauthorized();
});

it('la prueba manda la plantilla de muestra al número indicado', function (): void {
    encenderWhatsApp();

    $this->postJson('/api/v1/plataforma/whatsapp/prueba', ['telefono' => '55 1234 5678'], conPlataforma())
        ->assertOk()->assertJsonPath('data.enviado', true);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.facebook.com/v23.0/109876543210/messages'
        && $r->hasHeader('Authorization', 'Bearer EAAG-token-de-prueba')
        && $r['to'] === '525512345678'
        && $r['template']['name'] === 'hello_world');
});

it('apagado, el negocio no ve WhatsApp; encendido, solo enciende avisos con el texto fijo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    habilitarWhatsApp($e);
    $url = "/api/v1/app/{$e['slug']}/plantillas-mensaje";

    $r = $this->getJson($url, conBearer($e['bearer']))->assertOk();
    expect($r->json('canales'))->not->toContain('whatsapp')
        ->and($r->json('whatsapp'))->toBeNull();
    $this->putJson($url, ['clave' => 'reserva.confirmada', 'canal' => 'whatsapp'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');

    encenderWhatsApp();
    $r = $this->getJson($url, conBearer($e['bearer']))->assertOk();
    expect($r->json('canales'))->toContain('whatsapp')
        ->and($r->json('whatsapp')['reserva.confirmada'])->toStartWith('Hola {{persona_nombre}}, {{negocio}} confirmó');

    // El texto es el de la plantilla de Meta, aunque se mande otro.
    $this->putJson($url, ['clave' => 'reserva.confirmada', 'canal' => 'whatsapp', 'asunto' => 'Otro', 'cuerpo' => 'Otro texto'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.asunto', 'Reserva confirmada')
        ->assertJsonPath('data.cuerpo', fn (string $cuerpo): bool => str_starts_with($cuerpo, 'Hola {{persona_nombre}}'));
    // Solo los avisos con plantilla aprobada, y solo al cliente.
    $this->putJson($url, ['clave' => 'orden.pagada', 'canal' => 'whatsapp'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['clave'], 'meta.errors');
    $this->putJson($url, ['clave' => 'reserva.confirmada', 'canal' => 'whatsapp', 'destinatario' => 'profesional'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');
    // Los envíos masivos no van por WhatsApp.
    expect($this->getJson("/api/v1/app/{$e['slug']}/comunicaciones/segmentos", conBearer($e['bearer']))->json('canales'))
        ->not->toContain('whatsapp');

    // Al apagarlo desaparecen también los avisos que el negocio ya tenía.
    encenderWhatsApp(false);
    expect(collect($this->getJson($url, conBearer($e['bearer']))->json('data'))->pluck('canal'))->not->toContain('whatsapp');
});

it('la confirmación llega por WhatsApp a quien lo aceptó, con la plantilla y sus valores', function (): void {
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);

    // Vale lo acepta desde su cuenta; Caro no.
    $this->getJson("/api/v1/app/{$m['slug']}/mi/privacidad", conBearer($m['alumna']))
        ->assertOk()->assertJsonPath('data.whatsapp_disponible', true)->assertJsonPath('data.acepta_whatsapp', false);
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))
        ->assertOk()->assertJsonPath('data.acepta_whatsapp', true);

    foreach ([$m['vale'], $m['caro']] as $persona) {
        $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $persona], conBearer($m['bearer']))->assertCreated();
    }
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    $avisos = avisosWhatsApp($m);
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]->estado->value)->toBe('enviado')
        ->and($avisos[0]->destinatario)->toBe('525512345678')
        ->and($avisos[0]->cuerpo)->toStartWith('Hola Vale, ')
        ->and($avisos[0]->cuerpo)->toContain('en Nivel 1 el jueves 1 de octubre a las 08:00');

    expect($this->meta->envios)->toHaveCount(1);
    $envio = $this->meta->envios[0];
    $valores = array_column($envio['template']['components'][0]['parameters'], 'text');
    expect($envio)->toMatchArray(['messaging_product' => 'whatsapp', 'to' => '525512345678', 'type' => 'template'])
        ->and($envio['template']['name'])->toBe('agendauno_reserva_confirmada')
        ->and($envio['template']['language'])->toBe(['code' => 'es_MX'])
        ->and($valores)->toHaveCount(6)
        ->and([$valores[0], $valores[2], $valores[3], $valores[4]])->toBe(['Vale', 'Nivel 1', 'jueves 1 de octubre', '08:00']);

    // Retirarlo en su cuenta: ya no le llegan.
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => false], conBearer($m['alumna']))
        ->assertOk()->assertJsonPath('data.acepta_whatsapp', false);
});

it('si la plataforma lo apaga con avisos en cola, se descartan sin enviarse', function (): void {
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))->assertOk();
    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['vale']], conBearer($m['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    encenderWhatsApp(false);
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    $avisos = avisosWhatsApp($m);
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]->estado->value)->toBe('descartado')
        ->and($this->meta->envios)->toBe([]);
    // Y ya no se ofrece al cliente.
    $this->getJson("/api/v1/app/{$m['slug']}/mi/privacidad", conBearer($m['alumna']))
        ->assertOk()->assertJsonPath('data.whatsapp_disponible', false);
});

it('con cuenta, al agendar se puede aceptar WhatsApp si tiene celular', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $cliente = alumnoConSesion($e);
    habilitarWhatsApp($e);
    encenderWhatsApp();
    avisoPorWhatsApp($e);

    // Sin celular no se le ofrece; con celular, sí.
    $privacidad = fn (): array => $this->getJson("/api/v1/app/{$e['slug']}/mi/privacidad", conBearer($cliente['bearer']))->assertOk()->json('data');
    expect($privacidad())->toMatchArray(['whatsapp_disponible' => true, 'acepta_whatsapp' => false, 'whatsapp_con_celular' => false]);
    enNegocioWhatsApp($e, fn () => PersonaTenant::query()->where('email', 'vale@correo.mx')->update(['celular' => '55 1234 5678']));
    expect($privacidad()['whatsapp_con_celular'])->toBeTrue();

    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => now('America/Mexico_City')->addDays(3)->format('Y-m-d').' 10:00:00', 'duracion_minutos' => 30,
        'acepta_whatsapp' => true,
    ], conBearer($cliente['bearer']))->assertCreated();

    expect($privacidad()['acepta_whatsapp'])->toBeTrue();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(array_map(fn (MensajeTenant $a): ?string => $a->destinatario, avisosWhatsApp($e)))->toBe(['525512345678']);
});

it('al agendar en la página pública se puede aceptar recibir los avisos por WhatsApp', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    // Solo se ofrece con la plataforma encendida, el negocio activado y algún aviso
    // por WhatsApp encendido.
    expect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.whatsapp'))->toBeFalse();
    habilitarWhatsApp($e);
    encenderWhatsApp();
    expect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.whatsapp'))->toBeFalse();
    avisoPorWhatsApp($e);
    expect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->json('data.whatsapp'))->toBeTrue();

    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx', 'lada' => '+52', 'celular' => '55 1234 5678', 'acepta_whatsapp' => true,
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => now('America/Mexico_City')->addDays(3)->format('Y-m-d').' 10:00:00', 'duracion_minutos' => 30,
    ])->assertCreated()->assertJsonPath('data.estado', 'confirmada');

    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    $avisos = avisosWhatsApp($e);
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]->destinatario)->toBe('525512345678')
        ->and($avisos[0]->estado->value)->toBe('enviado')
        ->and(enNegocioWhatsApp($e, fn () => PersonaTenant::query()->where('email', 'beto@correo.mx')->value('whatsapp_aceptado_en')))->not->toBeNull();
});

it('recepción marca que el cliente pidió los avisos por WhatsApp y queda en la bitácora', function (): void {
    $m = negocioConAlumnas();
    $ficha = fn (): array => $this->getJson("/api/v1/app/{$m['slug']}/miembros/{$m['caro']}/resumen", conBearer($m['bearer']))
        ->assertOk()->json('data.whatsapp');

    // Sin WhatsApp en el negocio no se ofrece ni se puede marcar.
    expect($ficha()['disponible'])->toBeFalse()
        ->and($this->getJson("/api/v1/app/{$m['slug']}/yo", conBearer($m['bearer']))->json('data.estudio.whatsapp_clientes'))->toBeFalse();
    $this->putJson("/api/v1/app/{$m['slug']}/miembros/{$m['caro']}", ['acepta_whatsapp' => true], conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.acepta_whatsapp', false);

    encenderWhatsApp();
    avisoPorWhatsApp($m);
    expect($ficha())->toBe(['disponible' => true, 'acepta' => false, 'con_celular' => true])
        ->and($this->getJson("/api/v1/app/{$m['slug']}/yo", conBearer($m['bearer']))->json('data.estudio.whatsapp_clientes'))->toBeTrue();

    $this->putJson("/api/v1/app/{$m['slug']}/miembros/{$m['caro']}", ['acepta_whatsapp' => true], conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.acepta_whatsapp', true);
    expect($ficha()['acepta'])->toBeTrue();

    // Ya le llegan: la confirmación de su reserva sale por WhatsApp.
    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['caro']], conBearer($m['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(array_map(fn (MensajeTenant $a): ?string => $a->destinatario, avisosWhatsApp($m)))->toBe(['525587654321']);

    // Quién lo marcó y cuándo; retirarlo también queda.
    $this->putJson("/api/v1/app/{$m['slug']}/miembros/{$m['caro']}", ['acepta_whatsapp' => false], conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.acepta_whatsapp', false);
    $bitacora = collect($this->getJson("/api/v1/app/{$m['slug']}/auditorias", conBearer($m['bearer']))->json('data'))
        ->whereIn('accion', ['miembro.whatsapp_aceptado', 'miembro.whatsapp_retirado'])->pluck('accion')->sort()->values()->all();
    expect($bitacora)->toBe(['miembro.whatsapp_aceptado', 'miembro.whatsapp_retirado']);
});

it('al dar de alta un cliente con su celular, recepción puede registrar que acepta WhatsApp', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    habilitarWhatsApp($e);
    encenderWhatsApp();
    avisoPorWhatsApp($e);

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Luis', 'celular' => '55 2222 3333', 'acepta_whatsapp' => true,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.acepta_whatsapp', true);
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Toño', 'celular' => '55 4444 5555',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.acepta_whatsapp', false);
});

it('Meta verifica el webhook con el token que muestra el superadmin', function (): void {
    encenderWhatsApp();
    $webhook = $this->getJson('/api/v1/plataforma/whatsapp', conPlataforma())->assertOk()->json('data.webhook');
    expect($webhook['url'])->toEndWith('/api/v1/webhooks/whatsapp')
        ->and($webhook['token_verificacion'])->toHaveLength(40)
        ->and($webhook['app_secret_configurado'])->toBeTrue()
        ->and(json_encode($webhook))->not->toContain('secreto-de-la-app');

    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.$webhook['token_verificacion'].'&hub.challenge=12345')
        ->assertOk()->assertSee('12345');
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=12345')->assertForbidden();
});

it('Meta avisa que le llegó y que lo leyó; un aviso con otra firma se rechaza', function (): void {
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))->assertOk();
    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['vale']], conBearer($m['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    avisoDeMeta([['id' => 'wamid.prueba', 'status' => 'delivered', 'timestamp' => '1790000000']], 'otro-secreto')->assertForbidden();
    avisoDeMeta([['id' => 'wamid.prueba', 'status' => 'delivered', 'timestamp' => '1790000000']])->assertOk();
    // Meta no garantiza el orden: un «enviado» tardío no borra nada.
    avisoDeMeta([['id' => 'wamid.prueba', 'status' => 'sent', 'timestamp' => '1789999990']])->assertOk();
    avisoDeMeta([['id' => 'wamid.prueba', 'status' => 'read', 'timestamp' => '1790000100']])->assertOk();
    // Un wamid que no es nuestro se ignora.
    avisoDeMeta([['id' => 'wamid.ajeno', 'status' => 'read', 'timestamp' => '1790000100']])->assertOk()->assertJsonPath('data.aplicados', 0);

    $salida = collect($this->getJson("/api/v1/app/{$m['slug']}/mensajes", conBearer($m['bearer']))->assertOk()->json('data'))
        ->firstWhere('canal', 'whatsapp');
    expect($salida['estado'])->toBe('enviado')
        ->and($salida['entregado_en'])->not->toBeNull()
        ->and($salida['leido_en'])->not->toBeNull();
});

it('si Meta no lo pudo entregar queda fallido con el motivo y no se reintenta', function (): void {
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))->assertOk();
    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['vale']], conBearer($m['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    avisoDeMeta([['id' => 'wamid.prueba', 'status' => 'failed', 'timestamp' => '1790000000', 'errors' => [[
        'code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'El número no tiene WhatsApp.'],
    ]]]])->assertOk();

    $aviso = avisosWhatsApp($m)[0];
    expect($aviso->estado->value)->toBe('fallido')
        ->and($aviso->ultimo_error)->toContain('131026')->toContain('no tiene WhatsApp');
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    expect($this->meta->envios)->toHaveCount(1);
});

it('el superadmin lo activa negocio por negocio: apagado por omisión y el negocio no puede activarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    encenderWhatsApp();
    $url = "/api/v1/app/{$e['slug']}/plantillas-mensaje";

    // Con la plataforma encendida, un negocio nuevo no lo tiene ni lo puede usar.
    expect($this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())->assertOk()->json('data.whatsapp_clientes'))
        ->toBe(['habilitado' => false, 'plataforma' => true])
        ->and($this->getJson($url, conBearer($e['bearer']))->json('canales'))->not->toContain('whatsapp');
    $this->putJson($url, ['clave' => 'reserva.confirmada', 'canal' => 'whatsapp'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['canal'], 'meta.errors');
    // Solo con el token de la plataforma.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/whatsapp", ['habilitado' => true], conBearer($e['bearer']))->assertUnauthorized();

    habilitarWhatsApp($e);
    expect($this->getJson($url, conBearer($e['bearer']))->json('canales'))->toContain('whatsapp')
        ->and($this->getJson('/api/v1/plataforma/whatsapp', conPlataforma())->json('data.negocios_habilitados'))->toBe(1);
    avisoPorWhatsApp($e);

    habilitarWhatsApp($e, false);
    expect($this->getJson($url, conBearer($e['bearer']))->json('canales'))->not->toContain('whatsapp')
        ->and($this->getJson('/api/v1/plataforma/whatsapp', conPlataforma())->json('data.negocios_habilitados'))->toBe(0);
});

it('si el superadmin lo desactiva en el negocio, sus avisos en cola se descartan', function (): void {
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);
    $this->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))->assertOk();
    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['vale']], conBearer($m['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    habilitarWhatsApp($m, false);
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    $avisos = avisosWhatsApp($m);
    expect($avisos[0]->estado->value)->toBe('descartado')
        ->and($avisos[0]->ultimo_error)->toBe('WhatsApp no está activo en este negocio.')
        ->and($this->meta->envios)->toBe([]);
    $this->getJson("/api/v1/app/{$m['slug']}/mi/privacidad", conBearer($m['alumna']))
        ->assertOk()->assertJsonPath('data.whatsapp_disponible', false);
});

/**
 * Vale recibe por WhatsApp la confirmación de su reserva (wamid.prueba).
 *
 * @return array{slug: string, bearer: string, alumna: string, vale: string, caro: string, sesion: string, reserva: string}
 */
function valeRecibioSuAviso(): array
{
    $m = negocioConAlumnas();
    encenderWhatsApp();
    avisoPorWhatsApp($m);
    avisoPorWhatsApp($m, 'reserva.cancelada');
    test()->putJson("/api/v1/app/{$m['slug']}/mi/privacidad", ['acepta_whatsapp' => true], conBearer($m['alumna']))->assertOk();
    $reserva = (string) test()->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['vale']], conBearer($m['bearer']))
        ->assertCreated()->json('data.id');
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    return [...$m, 'reserva' => $reserva];
}

it('a quien contesta se le responde una vez con el WhatsApp del negocio y el enlace a su cuenta', function (): void {
    $m = valeRecibioSuAviso();

    // Meta manda el celular mexicano con el 1 de antes (521…); es el mismo número.
    mensajeDeMeta('5215512345678', ['id' => 'wamid.entrante.1', 'type' => 'text', 'text' => ['body' => '¿Puedo cambiar mi clase?']])
        ->assertOk()->assertJsonPath('data.respuestas', 1);

    $respuestas = respuestasEnviadas();
    expect($respuestas)->toHaveCount(1)
        ->and($respuestas[0]['to'])->toBe('5215512345678')
        ->and($respuestas[0]['body'])->toStartWith('Hola. Este WhatsApp solo envía los avisos de Estudio estudio-a y no recibe mensajes.')
        ->toContain('escríbele a Estudio estudio-a al +52 5512345678')
        ->toContain('/entrar?estudio=estudio-a')
        ->toContain('responde BAJA');

    // Si vuelve a escribir, o Meta reintenta el mismo aviso, no se le contesta otra vez.
    mensajeDeMeta('5215512345678', ['id' => 'wamid.entrante.1', 'type' => 'text', 'text' => ['body' => '¿Puedo cambiar mi clase?']])
        ->assertOk()->assertJsonPath('data.respuestas', 0);
    mensajeDeMeta('5215512345678', ['id' => 'wamid.entrante.2', 'type' => 'image', 'image' => ['id' => 'media']])
        ->assertOk()->assertJsonPath('data.respuestas', 0);
    // Una reacción no pide respuesta.
    mensajeDeMeta('5215587654321', ['id' => 'wamid.entrante.3', 'type' => 'reaction', 'reaction' => ['message_id' => 'wamid.prueba', 'emoji' => 'ok']])
        ->assertOk()->assertJsonPath('data.respuestas', 0);
    expect(respuestasEnviadas())->toHaveCount(1);

    // Pasado el plazo (12 horas por omisión) se le vuelve a contestar.
    $this->travel(13)->hours();
    mensajeDeMeta('5215512345678', ['id' => 'wamid.entrante.4', 'type' => 'text', 'text' => ['body' => 'Hola']])
        ->assertOk()->assertJsonPath('data.respuestas', 1);
    // Y quien nunca recibió un aviso sabe que debe ir con el negocio.
    mensajeDeMeta('5215599990000', ['id' => 'wamid.entrante.5', 'type' => 'text', 'text' => ['body' => 'Hola']])
        ->assertOk()->assertJsonPath('data.respuestas', 1);
    expect(respuestasEnviadas()[2]['body'])->toContain('comunícate directamente con el negocio');
});

it('BAJA retira el consentimiento, queda en la bitácora y descarta lo que iba a salir', function (): void {
    $m = valeRecibioSuAviso();
    // Su cancelación queda en cola por WhatsApp.
    $this->postJson("/api/v1/app/{$m['slug']}/reservas/{$m['reserva']}/cancelar", ['por' => 'cliente'], conBearer($m['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    mensajeDeMeta('5215512345678', ['id' => 'wamid.baja', 'type' => 'text', 'text' => ['body' => ' Baja. ']])
        ->assertOk()->assertJsonPath('data.respuestas', 1);

    expect(respuestasEnviadas()[0]['body'])
        ->toBe('Listo. Ya no te enviaremos por WhatsApp los avisos de Estudio estudio-a. Te seguirán llegando por correo o en la app.')
        ->and(enNegocioWhatsApp($m, fn () => PersonaTenant::query()->where('ulid', $m['vale'])->value('whatsapp_aceptado_en')))->toBeNull()
        ->and(array_map(fn (MensajeTenant $a): string => $a->estado->value, avisosWhatsApp($m)))->toBe(['enviado', 'descartado']);
    $this->getJson("/api/v1/app/{$m['slug']}/mi/privacidad", conBearer($m['alumna']))->assertOk()->assertJsonPath('data.acepta_whatsapp', false);

    $baja = collect($this->getJson("/api/v1/app/{$m['slug']}/auditorias", conBearer($m['bearer']))->json('data'))
        ->firstWhere('accion', 'miembro.whatsapp_retirado');
    expect($baja['motivo'])->toBe('Lo pidió contestando BAJA por WhatsApp.');
});

it('al dueño que contesta se le manda a su panel, y con BAJA deja de recibir los avisos de la plataforma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    encenderWhatsApp(false, true);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $estudio->forceFill(['contacto_whatsapp_aceptado_en' => now()])->save();
    WhatsAppEnvio::query()->create([
        'wamid' => 'wamid.renta', 'estudio_id' => $estudio->getKey(), 'origen' => WhatsAppEnvio::ORIGEN_AVISO_DUENO,
        'referencia_id' => 1, 'telefono_huella' => TelefonoWhatsApp::huella('525512345678'),
    ]);

    mensajeDeMeta('5215512345678', ['id' => 'wamid.dueno.1', 'type' => 'text', 'text' => ['body' => '¿Cuánto debo?']])->assertOk();
    expect(respuestasEnviadas()[0]['body'])->toStartWith('Hola. Este WhatsApp solo envía los avisos de AgendaUno')
        ->toContain('/entrar?estudio=estudio-a&volver=%2Frenta');

    mensajeDeMeta('5215512345678', ['id' => 'wamid.dueno.2', 'type' => 'text', 'text' => ['body' => 'STOP']])->assertOk();
    expect(respuestasEnviadas()[1]['body'])->toStartWith('Listo. Ya no te enviaremos por WhatsApp los avisos de AgendaUno.')
        ->and($estudio->refresh()->contacto_whatsapp_aceptado_en)->toBeNull();
});
