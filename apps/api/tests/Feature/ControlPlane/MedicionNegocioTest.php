<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\EnvioMedicionPlataforma;
use App\Modules\Tenancy\Models\EnvioMedicionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Http\Client\Request as SolicitudHttp;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| GA4 y Meta de cada negocio (ADR 0143): Configuración → Integraciones → Medición.
| Sus conversiones salen de sus eventos de dominio después de confirmarse (solicitud,
| cuenta activada, compra realmente pagada, devolución aplicada, primera reserva) y van
| solo a sus destinos, una vez cada una, con lo que la persona autorizó.
*/

const VISITANTE_NEGOCIO = 'fedcba9876543210fedcba9876543210';

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.medicion.enviar_fuera_de_produccion', true);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El negocio enciende su GA4 y su Meta.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function encenderMedicionNegocio(array $e, array $extra = []): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/ga4", [
        'identificador' => 'G-NEGOCIO123', 'secreto' => 'secreto-ga4-negocio', 'activo' => true, 'aviso_confirmado' => true, ...$extra,
    ], conBearer($e['bearer']))->assertOk();
    test()->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/meta", [
        'identificador' => '9876543210', 'secreto' => str_repeat('EAAnegocio', 3), 'activo' => true, 'aviso_confirmado' => true, ...$extra,
    ], conBearer($e['bearer']))->assertOk();
}

/** Lo que manda el navegador que autorizó todo. */
function medicionPermitida(): array
{
    return [
        'visitante' => VISITANTE_NEGOCIO, 'analitica' => true, 'publicidad' => true,
        'ga_client_id' => '555666777.1700000000', 'url' => 'https://grecon.agendauno.mx/estudio/grecon',
    ];
}

/** Corre en la base de un negocio de estas pruebas. */
function enNegocioMedicion(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), $hacer);
}

/**
 * @return array<string, array<string, mixed>> por nombre de evento
 */
function enviadosA(string $proveedor): array
{
    return collect(Http::recorded())->map(fn (array $par): SolicitudHttp => $par[0])
        ->filter(fn (SolicitudHttp $r): bool => str_contains($r->url(), $proveedor === 'ga4' ? 'google-analytics' : 'graph.facebook.com'))
        ->mapWithKeys(fn (SolicitudHttp $r): array => $proveedor === 'ga4'
            ? [$r['events'][0]['name'] => $r->data()]
            : [$r['data'][0]['event_name'] => $r['data'][0]])
        ->all();
}

it('el negocio configura su GA4 y su Meta; el secreto no sale ni en la auditoría; su página solo recibe su GA4', function (): void {
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    $otro = estudioConSesion('estudio-otro', 'otro@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/ga4", ['identificador' => 'G-NEGOCIO123', 'activo' => true], conBearer($recepcion))
        ->assertForbidden();
    $this->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/ga4", ['identificador' => 'G-NEGOCIO123', 'secreto' => 'secreto-ga4-negocio', 'activo' => true], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['aviso_confirmado'], 'meta.errors');
    encenderMedicionNegocio($e, ['eventos_extra' => ['asistencia']]);

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/medicion/destinos", conBearer($e['bearer']))->assertOk();
    expect($lista->getContent())->not->toContain('secreto-ga4-negocio')->not->toContain('EAAnegocio')
        ->and(collect($lista->json('data.destinos'))->firstWhere('proveedor', 'ga4'))
        ->toMatchArray(['identificador' => 'G-NEGOCIO123', 'secreto_configurado' => true, 'estado' => 'activo', 'eventos_extra' => ['asistencia']]);
    $auditoria = enNegocioMedicion($e['slug'], fn () => AuditoriaTenant::query()->where('accion', 'medicion.destino_guardado')->get());
    expect($auditoria->pluck('motivo')->unique()->all())->toBe(['Se cambió el secreto.'])
        ->and($auditoria->toJson())->not->toContain('secreto-ga4-negocio')->not->toContain('EAAnegocio');

    // Su página: solo el ID de su GA4 (el píxel de Meta no se carga en un negocio).
    $this->getJson("/api/v1/app/{$e['slug']}/medicion")->assertOk()->assertExactJson(['data' => ['ga4' => 'G-NEGOCIO123', 'meta' => null]]);
    $this->getJson("/api/v1/app/{$otro['slug']}/medicion")->assertOk()->assertExactJson(['data' => ['ga4' => null, 'meta' => null]]);
});

it('la solicitud se manda una vez como lead, sin datos de la persona; un reenvío no es otra', function (): void {
    Http::fake(['*' => Http::response(['events_received' => 1])]);
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    encenderMedicionNegocio($e);
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->json('data.id');
    $envio = ['nombre' => 'Lucía Prospecto', 'email' => 'lucia@correo.mx', 'telefono' => '5512345678', 'acepta_aviso' => true, 'medicion' => medicionPermitida()];

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Prueba)'])->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", $envio)->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", $envio)->assertCreated();
    // Solicitar no esperó a Google ni a Meta.
    Http::assertNothingSent();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    $prospecto = (string) $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($e['bearer']))->json('data.0.id');
    expect(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->pluck('estado', 'nombre')->map->value->all()))
        ->toBe(['generate_lead' => 'enviado', 'Lead' => 'enviado']);
    Http::assertSentCount(2);
    $ga4 = enviadosA('ga4')['generate_lead'];
    $meta = enviadosA('meta')['Lead'];
    expect($ga4['client_id'])->toBe('555666777.1700000000')
        ->and($ga4['events'][0]['params'])->toMatchArray(['lead_source' => 'formulario'])
        ->and($meta['event_id'])->toBe("solicitud-{$prospecto}")
        ->and($meta['user_data'])->toMatchArray(['client_user_agent' => 'Mozilla/5.0 (Prueba)', 'external_id' => [hash('sha256', VISITANTE_NEGOCIO)]])
        ->and($meta['user_data'])->not->toHaveKeys(['em', 'ph']);
    collect(Http::recorded())->each(fn (array $par) => expect($par[0]->body())
        ->not->toContain('lucia@correo.mx')->not->toContain('Lucía')->not->toContain('5512345678'));
    // Ni a los destinos de la plataforma.
    expect(EnvioMedicionPlataforma::query()->count())->toBe(0);
});

it('la compra solo cuando de verdad se paga: nueva y luego recurrente; su devolución, solo a donde se mandó', function (): void {
    Http::fake(['*' => Http::response(['events_received' => 1])]);
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    encenderMedicionNegocio($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $pack = crearPackTenant($e);
    $comprar = fn (): string => (string) $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Prueba)'])->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]], 'medicion' => medicionPermitida(),
    ], conBearer($vale['bearer']))->assertCreated()->json('data.id');

    $orden = $comprar();
    // Pendiente de pago: ninguna compra (su cuenta activada, sí).
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->pluck('evento')->unique()->all()))->toBe(['cuenta_activada']);

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $segunda = $comprar();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$segunda}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    $compras = collect(Http::recorded())->map(fn (array $par): SolicitudHttp => $par[0])
        ->filter(fn (SolicitudHttp $r): bool => str_contains($r->url(), 'google-analytics') && $r['events'][0]['name'] === 'purchase')
        ->map(fn (SolicitudHttp $r): array => $r['events'][0]['params'])->values();
    expect($compras->pluck('transaction_id')->all())->toBe([$orden, $segunda])
        ->and($compras->pluck('customer_type')->all())->toBe(['new', 'returning'])
        ->and($compras[0])->toMatchArray(['value' => 899.0, 'currency' => 'MXN'])
        ->and($compras[0]['items'][0])->toMatchArray(['item_id' => $pack, 'item_name' => 'Pack 8 clases', 'quantity' => 1]);
    // Pagada en el mostrador: así la recibe Meta.
    expect(enviadosA('meta')['Purchase'])->toMatchArray(['action_source' => 'physical_store', 'event_id' => "compra-{$segunda}"]);

    // Se devuelve la primera: GA4 recibe la devolución de esa transacción; Meta no tiene.
    $pago = (string) enNegocioMedicion($e['slug'], fn () => PagoTenant::query()
        ->whereHas('orden', fn ($q) => $q->where('ulid', $orden))->value('ulid'));
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Se mudó'], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    expect(enviadosA('ga4')['refund']['events'][0]['params'])->toMatchArray(['transaction_id' => $orden, 'value' => 899.0, 'currency' => 'MXN'])
        ->and(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->where('evento', 'devolucion')->count()))->toBe(1);
});

it('sin la autorización se omite; lo que llega con el negocio en demostración, también', function (): void {
    Http::fake();
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    encenderMedicionNegocio($e);
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx', 'acepta_aviso' => true,
        'medicion' => ['visitante' => VISITANTE_NEGOCIO, 'analitica' => false, 'publicidad' => false],
    ])->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    expect(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->pluck('motivo')->unique()->all()))->toBe(['preferencias']);
    Http::assertNothingSent();

    Estudio::query()->where('slug', $e['slug'])->update(['es_demo' => true]);
    $this->getJson("/api/v1/app/{$e['slug']}/medicion")->assertOk()->assertJsonPath('data.ga4', null);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx', 'acepta_aviso' => true, 'medicion' => medicionPermitida(),
    ])->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();
    expect(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->where('estado', 'omitido')->where('motivo', 'negocio_demo')->count()))->toBe(2);
    Http::assertNothingSent();
});

it('retirar la autorización en su página borra lo de su navegador en ese negocio y no en otro', function (): void {
    $a = estudioConSesion('estudio-mide', 'mide@correo.mx');
    $b = estudioConSesion('estudio-otro', 'otro@correo.mx');
    foreach ([$a, $b] as $e) {
        $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->json('data.id');
        $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
            'nombre' => 'Lucía', 'email' => 'lucia@correo.mx', 'acepta_aviso' => true,
            'atribucion' => ['ultimo_toque' => ['fuente' => 'ig', 'campana' => 'x', 'en' => now()->toIso8601String()]],
            'medicion' => medicionPermitida(),
        ])->assertCreated();
    }

    $this->postJson("/api/v1/app/{$a['slug']}/medicion/consentimiento", ['visitante' => VISITANTE_NEGOCIO, 'analitica' => false, 'publicidad' => false])->assertNoContent();

    $fila = fn (string $slug) => enNegocioMedicion($slug, fn () => ContextoMedicionTenant::query()->sole()
        ->only(['analitica', 'publicidad', 'ga_client_id', 'campana']));
    expect($fila($a['slug']))->toBe(['analitica' => false, 'publicidad' => false, 'ga_client_id' => null, 'campana' => 'x'])
        ->and($fila($b['slug']))->toMatchArray(['analitica' => true, 'ga_client_id' => '555666777.1700000000']);
});

it('los eventos adicionales solo si el negocio los enciende; despachar dos veces no duplica', function (): void {
    Http::fake(['*' => Http::response(['events_received' => 1])]);
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    encenderMedicionNegocio($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->value('id');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]], 'medicion' => medicionPermitida(),
    ], conBearer($vale['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-02 10:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    // Despachar el mismo evento otra vez (el relay es at-least-once) no crea otra.
    enNegocioMedicion($e['slug'], fn () => EventoOutboxTenant::query()->update(['publicado_en' => null]));
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $eventos = enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->where('evento', 'reserva')->pluck('nombre')->sort()->values()->all());
    // Una sola vez cada una.
    expect($eventos)->toBe(['Schedule', 'reserva_confirmada'])
        ->and(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->count()))->toBe(6);

    // La asistencia no está encendida: no se anota. Encendida, la primera sí.
    $reserva = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('data'))->value('id');
    $this->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/ga4", ['activo' => true, 'eventos_extra' => ['asistencia']], conBearer($e['bearer']))->assertOk();
    $this->travelTo('2026-10-02 10:05:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->where('evento', 'primera_asistencia')->pluck('nombre')->all()))
        ->toBe(['primera_asistencia']);
});

it('una cita pagada con llaves de prueba no cuenta como venta; el webhook repetido no la duplica', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'cs_medicion_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_medicion_1']),
        '*' => Http::response(['events_received' => 1]),
    ]);
    $e = estudioConSesion('barberia-mide', 'barberia@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", ['activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x']], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    encenderMedicionNegocio($e);

    $cita = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Prueba)'])->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente', 'email' => 'cliente@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $coach, 'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60, 'medicion' => medicionPermitida(),
    ])->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", ['orden_id' => $cita['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta'])->assertCreated();
    $webhook = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_medicion_1', 'payment_status' => 'paid']]];
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", $webhook)->assertOk();
    $this->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", $webhook)->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-conversiones')->assertSuccessful();

    $envios = enNegocioMedicion($e['slug'], fn () => EnvioMedicionTenant::query()->get()
        ->map(fn (EnvioMedicionTenant $x): string => "{$x->nombre}:{$x->estado->value}:{$x->motivo}")->sort()->values()->all());
    expect($envios)->toBe([
        // La compra existe una sola vez por destino y no sale: se pagó con llaves de prueba.
        'Purchase:omitido:pago_de_prueba',
        // La cita confirmada sí es real para el negocio.
        'Schedule:enviado:',
        'purchase:omitido:pago_de_prueba',
        'reserva_confirmada:enviado:',
    ]);
});
