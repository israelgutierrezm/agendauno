<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ContextoMedicionPlataforma;
use App\Modules\Tenancy\Models\DestinoMedicionPlataforma;
use App\Modules\Tenancy\Models\EnvioMedicionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as SolicitudHttp;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| GA4 y Meta del SaaS (ADR 0143): cada producto con su propiedad y su píxel; el embudo
| sale de lo que de verdad pasa en el sistema (negocio creado, prueba iniciada,
| activación operativa, primera suscripción pagada); se manda aparte, una sola vez, con
| un identificador estable y solo con lo que la persona autorizó.
*/

const VISITANTE_MEDICION = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    // En las pruebas, como en producción: los envíos salen (a los proveedores simulados).
    Config::set('agendauno.medicion.enviar_fuera_de_produccion', true);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Enciende GA4 y Meta de AgendaUno, como lo haría el superadmin. */
function encenderMedicionAgendaUno(): void
{
    test()->putJson('/api/v1/plataforma/medicion/agendauno/ga4', [
        'identificador' => 'G-AGENDA123', 'secreto' => 'secreto-ga4-agenda', 'activo' => true, 'aviso_confirmado' => true,
    ], conPlataforma())->assertOk();
    test()->putJson('/api/v1/plataforma/medicion/agendauno/meta', [
        'identificador' => '1234567890', 'secreto' => str_repeat('EAAtoken', 5), 'activo' => true, 'aviso_confirmado' => true,
    ], conPlataforma())->assertOk();
}

/**
 * Registra un negocio de clases (AgendaUno) con las preferencias de su navegador.
 *
 * @param  array<string, mixed>  $medicion
 * @return array<string, mixed>
 */
function registrarConMedicion(string $slug, array $medicion): array
{
    return test()->withHeaders(['User-Agent' => 'Mozilla/5.0 (Prueba)'])->postJson('/api/v1/registro', [
        'nombre' => 'Negocio '.$slug, 'slug' => $slug, 'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => "{$slug}@correo.mx", 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
        'medicion' => ['visitante' => VISITANTE_MEDICION, 'url' => 'https://agendauno.mx/registro?email=no@se.guarda', ...$medicion],
    ])->assertCreated()->json('data');
}

/** Un cargo de la suscripción ya resuelto. */
function cargoMedicion(Estudio $estudio, string $clave, string $estado, int $monto, string $concepto = 'renta'): CargoRenta
{
    return CargoRenta::query()->create([
        'estudio_id' => $estudio->getKey(), 'periodo' => '2026-10', 'clave' => $clave, 'concepto' => $concepto, 'modo_cobro' => 'fijo',
        'monto_minor' => $monto, 'moneda' => 'MXN', 'estado' => $estado, 'pagado_en' => $estado === 'pagado' ? now() : null,
    ]);
}

it('el superadmin configura GA4 y Meta por producto; el secreto no vuelve a salir', function (): void {
    $this->putJson('/api/v1/plataforma/medicion/agendauno/ga4', ['identificador' => 'UA-123', 'activo' => false], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['identificador'], 'meta.errors');
    $this->putJson('/api/v1/plataforma/medicion/agendauno/ga4', ['identificador' => 'G-AGENDA123', 'secreto' => 'secreto-ga4-agenda', 'activo' => true], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['aviso_confirmado'], 'meta.errors');
    encenderMedicionAgendaUno();

    $respuesta = $this->getJson('/api/v1/plataforma/medicion', conPlataforma())->assertOk();
    $ga4 = collect($respuesta->json('data.destinos'))->firstWhere(fn (array $d): bool => $d['producto'] === 'agendauno' && $d['proveedor'] === 'ga4');
    expect($ga4)->toMatchArray(['identificador' => 'G-AGENDA123', 'secreto_configurado' => true, 'activo' => true, 'estado' => 'activo'])
        ->and($respuesta->getContent())->not->toContain('secreto-ga4-agenda')
        ->and(DB::table('destinos_medicion_plataforma')->where('proveedor', 'ga4')->value('secreto'))->not->toContain('secreto-ga4-agenda');

    // Lo público: solo los IDs, y cada producto los suyos.
    $this->getJson('/api/v1/medicion/agendauno')->assertOk()->assertExactJson(['data' => ['ga4' => 'G-AGENDA123', 'meta' => '1234567890']]);
    $this->getJson('/api/v1/medicion/turnouno')->assertOk()->assertExactJson(['data' => ['ga4' => null, 'meta' => null]]);
    // Cambiar el ID sin escribir el secreto lo conserva.
    $this->putJson('/api/v1/plataforma/medicion/agendauno/ga4', ['identificador' => 'G-OTRO456', 'activo' => true], conPlataforma())
        ->assertOk()->assertJsonPath('data.secreto_configurado', true);
});

it('el registro guarda las preferencias y solo los identificadores que se permitieron', function (): void {
    $registro = registrarConMedicion('sol-analitica', [
        'analitica' => true, 'publicidad' => false,
        'ga_client_id' => '123456789.1700000000', 'ga_session_id' => '1700000000', 'fbp' => 'fb.1.1700000000000.123456789',
    ]);

    $estudio = Estudio::query()->where('slug', 'sol-analitica')->sole();
    expect($registro['medicion']['eventos'])->toBe([
        'negocio_creado' => "negocio_creado-{$estudio->ulid}",
        'prueba_iniciada' => "prueba_iniciada-{$estudio->ulid}",
    ]);
    $contexto = ContextoMedicionPlataforma::query()->sole();
    expect($contexto->only(['visitante', 'analitica', 'publicidad', 'ga_client_id', 'ga_session_id', 'fbp', 'agente', 'ip', 'url']))->toBe([
        'visitante' => VISITANTE_MEDICION, 'analitica' => true, 'publicidad' => false,
        'ga_client_id' => '123456789.1700000000', 'ga_session_id' => '1700000000',
        // Sin publicidad: ni cookie de Meta, ni agente, ni IP.
        'fbp' => null, 'agente' => null, 'ip' => null,
        // La página, sin su query.
        'url' => 'https://agendauno.mx/registro',
    ]);
});

it('manda el embudo una sola vez, con su identificador y sin datos personales; una prueba no es una venta', function (): void {
    Http::fake([
        'www.google-analytics.com/*' => Http::response('', 204),
        'graph.facebook.com/*' => Http::response(['events_received' => 1]),
    ]);
    encenderMedicionAgendaUno();
    registrarConMedicion('sol-completo', [
        'analitica' => true, 'publicidad' => true,
        'ga_client_id' => '123456789.1700000000', 'ga_session_id' => '1700000000', 'fbp' => 'fb.1.1700000000000.123456789',
    ]);
    // Registrar no esperó a Google ni a Meta.
    Http::assertNothingSent();

    $estudio = Estudio::query()->where('slug', 'sol-completo')->sole();
    $estudio->forceFill(['primera_operacion_en' => now()])->save();
    cargoMedicion($estudio, 'timbres-1', 'pagado', 50000, 'timbres');
    cargoMedicion($estudio, 'cortesia', 'sin_cargo', 0);
    $cargo = cargoMedicion($estudio, 'renta-1', 'pagado', 149900);

    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    // 4 resultados × 2 destinos, una sola vez cada uno.
    expect(EnvioMedicionPlataforma::query()->count())->toBe(8)
        ->and(EnvioMedicionPlataforma::query()->where('estado', 'enviado')->count())->toBe(8)
        ->and(EnvioMedicionPlataforma::query()->where('evento', 'primera_suscripcion')->pluck('transaccion')->unique()->all())->toBe([$cargo->ulid]);
    Http::assertSentCount(8);

    $enviados = collect(Http::recorded())->map(fn (array $par): SolicitudHttp => $par[0]);
    $ga4 = $enviados->filter(fn (SolicitudHttp $r): bool => str_contains($r->url(), 'google-analytics'))->mapWithKeys(fn (SolicitudHttp $r): array => [$r['events'][0]['name'] => $r->data()]);
    expect($ga4->keys()->sort()->values()->all())->toBe(['negocio_activado', 'prueba_iniciada', 'purchase', 'sign_up'])
        ->and($ga4['sign_up']['client_id'])->toBe('123456789.1700000000')
        ->and($ga4['sign_up']['events'][0]['params']['session_id'])->toBe('1700000000')
        ->and($ga4['purchase']['events'][0]['params'])->toMatchArray(['transaction_id' => $cargo->ulid, 'value' => 1499.0, 'currency' => 'MXN', 'customer_type' => 'new'])
        // Una prueba gratuita no lleva monto ni es una compra.
        ->and($ga4['prueba_iniciada']['events'][0]['params'])->not->toHaveKeys(['value', 'transaction_id']);

    $meta = $enviados->filter(fn (SolicitudHttp $r): bool => str_contains($r->url(), 'graph.facebook.com'))->mapWithKeys(fn (SolicitudHttp $r): array => [$r['data'][0]['event_name'] => $r['data'][0]]);
    expect($meta->keys()->sort()->values()->all())->toBe(['ActivacionOperativa', 'CompleteRegistration', 'Purchase', 'StartTrial'])
        ->and($meta['CompleteRegistration']['event_id'])->toBe("negocio_creado-{$estudio->ulid}")
        ->and($meta['CompleteRegistration']['user_data'])->toMatchArray([
            'client_user_agent' => 'Mozilla/5.0 (Prueba)', 'fbp' => 'fb.1.1700000000000.123456789',
            'external_id' => [hash('sha256', VISITANTE_MEDICION)],
        ])
        ->and($meta['CompleteRegistration']['user_data'])->not->toHaveKeys(['em', 'ph', 'fn', 'ln'])
        ->and($meta['Purchase']['custom_data'])->toMatchArray(['value' => 1499.0, 'currency' => 'MXN', 'order_id' => $cargo->ulid])
        ->and($meta['StartTrial'])->not->toHaveKey('custom_data');
    // Ni el correo ni el nombre del negocio salen; el token va en el cuerpo, no en la URL.
    $enviados->each(function (SolicitudHttp $r): void {
        expect($r->body())->not->toContain('sol-completo@correo.mx')->not->toContain('Negocio sol-completo');
        expect($r->url())->not->toContain('EAAtoken');
    });
});

it('las demos no se mandan y lo que pasó antes de encender el destino tampoco', function (): void {
    Http::fake();
    registrarConMedicion('sol-antes', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2']);
    $this->travel(1)->minutes();
    encenderMedicionAgendaUno();
    registrarConMedicion('sol-demo', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2']);
    Estudio::query()->where('slug', 'sol-demo')->update(['es_demo' => true]);

    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    expect(EnvioMedicionPlataforma::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sin la autorización o sin el identificador, se omite con su motivo; retirarla borra lo que se había guardado', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    encenderMedicionAgendaUno();
    // Solo analítica, sin client_id (la etiqueta no cargó): GA4 sin identificador y Meta por preferencias.
    registrarConMedicion('sol-sin-id', ['analitica' => true, 'publicidad' => false]);
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    $motivos = EnvioMedicionPlataforma::query()->where('evento', 'negocio_creado')->with('destino')->get()
        ->mapWithKeys(fn (EnvioMedicionPlataforma $e): array => [$e->destino?->proveedor->value => [$e->estado->value, $e->motivo]]);
    expect($motivos->all())->toBe(['ga4' => ['omitido', 'sin_identificador'], 'meta' => ['omitido', 'preferencias']]);
    Http::assertNothingSent();

    // Otro registro con todo permitido; antes de mandarse, la persona lo retira.
    ContextoMedicionPlataforma::query()->delete();
    EnvioMedicionPlataforma::query()->delete();
    registrarConMedicion('sol-retira', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2', 'fbp' => 'fb.1.1700000000000.1']);
    $this->postJson('/api/v1/medicion/consentimiento', ['visitante' => VISITANTE_MEDICION, 'analitica' => true, 'publicidad' => false])->assertNoContent();
    expect(ContextoMedicionPlataforma::query()->sole()->only(['publicidad', 'fbp', 'agente', 'ip', 'ga_client_id']))
        ->toBe(['publicidad' => false, 'fbp' => null, 'agente' => null, 'ip' => null, 'ga_client_id' => '1.2']);

    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    $deSuNegocio = fn (string $proveedor) => EnvioMedicionPlataforma::query()->whereHas('estudio', fn ($q) => $q->where('slug', 'sol-retira'))
        ->whereHas('destino', fn ($q) => $q->where('proveedor', $proveedor));
    expect($deSuNegocio('meta')->pluck('motivo')->unique()->all())->toBe(['preferencias'])
        ->and($deSuNegocio('ga4')->get()->map(fn (EnvioMedicionPlataforma $e): string => $e->estado->value)->unique()->all())->toBe(['enviado']);
});

it('reintenta solo lo que no se puede duplicar; el token vencido avisa al superadmin; ningún secreto queda escrito', function (): void {
    $ga4 = 0;
    Http::fake([
        'www.google-analytics.com/*' => function (SolicitudHttp $r) use (&$ga4) {
            $ga4++;
            // La compra se corta después de mandarse; lo demás, el servidor de GA4 falla una vez.
            if (($r['events'][0]['name'] ?? '') === 'purchase' && $ga4 < 6) {
                throw new ConnectionException('cURL error 28: Operation timed out for https://www.google-analytics.com/mp/collect?measurement_id=G-AGENDA123&api_secret=secreto-ga4-agenda');
            }

            return $ga4 <= 3 ? Http::response('', 500) : Http::response('', 204);
        },
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 400),
    ]);
    encenderMedicionAgendaUno();
    registrarConMedicion('sol-falla', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2', 'fbp' => 'fb.1.1700000000000.1']);
    cargoMedicion(Estudio::query()->where('slug', 'sol-falla')->sole(), 'renta-1', 'pagado', 149900);

    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    $estado = fn (string $proveedor, string $evento): array => EnvioMedicionPlataforma::query()->where('evento', $evento)
        ->whereHas('destino', fn ($q) => $q->where('proveedor', $proveedor))->sole()
        ->only(['motivo', 'proximo_intento_en', 'ultimo_error']) + ['estado' => EnvioMedicionPlataforma::query()->where('evento', $evento)
            ->whereHas('destino', fn ($q) => $q->where('proveedor', $proveedor))->sole()->estado->value];

    // Meta: el token no sirve; no se reintenta y el superadmin se entera.
    expect($estado('meta', 'negocio_creado'))->toMatchArray(['estado' => 'error', 'motivo' => 'credenciales', 'proximo_intento_en' => null])
        ->and(DB::table('alertas_plataforma')->where('tipo', 'medicion_fallida')->exists())->toBeTrue();
    // GA4: el 500 y la compra cortada se reintentan (la compra se deduplica por transacción).
    expect($estado('ga4', 'negocio_creado')['proximo_intento_en'])->not->toBeNull()
        ->and($estado('ga4', 'primera_suscripcion'))->toMatchArray(['estado' => 'error', 'motivo' => 'sin_conexion'])
        ->and($estado('ga4', 'primera_suscripcion')['ultimo_error'])->not->toContain('secreto-ga4-agenda')->toContain('[oculto]');

    $this->travel(2)->minutes();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    expect($estado('ga4', 'negocio_creado')['estado'])->toBe('enviado');
    expect(json_encode(DB::table('envios_medicion_plataforma')->get()))->not->toContain('secreto-ga4-agenda')->not->toContain('EAAtoken');
});

it('un corte después de mandar un evento que no se deduplica no se reintenta', function (): void {
    Http::fake([
        'www.google-analytics.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        'graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);
    encenderMedicionAgendaUno();
    registrarConMedicion('sol-corte', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2']);

    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    $creado = EnvioMedicionPlataforma::query()->where('evento', 'negocio_creado')->get();
    expect($creado->pluck('motivo')->unique()->all())->toBe(['incierto'])
        ->and($creado->pluck('proximo_intento_en')->filter()->all())->toBe([]);
});

it('fuera de producción no sale nada; desconectar borra ID y secreto y deja lo pendiente sin mandar', function (): void {
    Config::set('agendauno.medicion.enviar_fuera_de_produccion', false);
    Http::fake();
    encenderMedicionAgendaUno();
    registrarConMedicion('sol-local', ['analitica' => true, 'publicidad' => true, 'ga_client_id' => '1.2']);
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    expect(EnvioMedicionPlataforma::query()->pluck('motivo')->unique()->all())->toBe(['entorno_de_pruebas']);
    Http::assertNothingSent();

    EnvioMedicionPlataforma::query()->update(['estado' => 'pendiente', 'motivo' => null]);
    $this->deleteJson('/api/v1/plataforma/medicion/agendauno/meta', [], conPlataforma())->assertOk()
        ->assertJsonPath('data.identificador', null)->assertJsonPath('data.secreto_configurado', false)->assertJsonPath('data.activo', false);
    expect(DestinoMedicionPlataforma::query()->where('proveedor', 'meta')->sole()->secreto)->toBeNull()
        ->and(EnvioMedicionPlataforma::query()->whereHas('destino', fn ($q) => $q->where('proveedor', 'meta'))->pluck('motivo')->unique()->all())->toBe(['destino_desconectado']);
    $this->getJson('/api/v1/medicion/agendauno')->assertOk()->assertJsonPath('data.meta', null);
});
