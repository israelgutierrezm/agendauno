<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoDueno;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| Avisos de la plataforma a los dueños (ADR 0071): prueba por terminar, renta lista,
| renta vencida y pago recibido. Siempre por correo; por WhatsApp además si la
| plataforma lo encendió con los dueños y el dueño lo aceptó al registrarse. Nunca
| se repiten.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Config::set('agendauno.url_app', 'https://agendauno.mx');
    Mail::fake();
    $this->meta = (object) ['envios' => [], 'falla' => false];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graph.facebook.com')) {
            $this->meta->envios[] = $request->data();

            return $this->meta->falla
                ? Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 401)
                : Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.prueba']]]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function whatsAppParaDuenos(bool $duenos = true): void
{
    test()->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => $duenos, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
}

/**
 * El dueño de ese negocio aceptó avisos por WhatsApp al registrarse (su número es el
 * del registro de prueba: 55 1234 5678).
 */
function duenoAceptoWhatsApp(string $slug): void
{
    Estudio::query()->where('slug', $slug)->update(['contacto_whatsapp_verificado_en' => now(), 'contacto_whatsapp_aceptado_en' => now()]);
}

/**
 * @return list<string>
 */
function asuntosA(string $correo): array
{
    return Mail::sent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo($correo))
        ->map(fn (MensajeMailable $m): string => $m->asuntoMensaje)->values()->all();
}

it('avisa que la prueba termina: por correo siempre y por WhatsApp a quien lo aceptó', function (): void {
    $this->travelTo('2026-10-10 17:00:00');
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    estudioConSesion('estudio-c', 'c@correo.mx');
    Estudio::query()->whereIn('slug', ['estudio-a', 'estudio-b'])->update(['trial_termina_en' => '2026-10-12']);
    // A esta aún le faltan semanas.
    Estudio::query()->where('slug', 'estudio-c')->update(['trial_termina_en' => '2026-10-30']);
    whatsAppParaDuenos();
    duenoAceptoWhatsApp('estudio-b');

    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();

    expect(asuntosA('a@correo.mx'))->toBe(['Tu prueba gratis de AgendaUno termina el 12 de octubre'])
        ->and(asuntosA('b@correo.mx'))->toHaveCount(1)
        ->and(asuntosA('c@correo.mx'))->toBe([]);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('a@correo.mx')
        && str_contains($m->cuerpoMensaje, 'la prueba gratis de Estudio estudio-a en AgendaUno termina el 12 de octubre')
        && str_contains($m->cuerpoMensaje, 'https://agendauno.mx/entrar?estudio=estudio-a&volver=%2Frenta'));

    // Solo el dueño que lo aceptó recibe WhatsApp.
    expect($this->meta->envios)->toHaveCount(1);
    $envio = $this->meta->envios[0];
    expect($envio['to'])->toBe('525512345678')
        ->and($envio['template']['name'])->toBe('agendauno_prueba_por_terminar')
        ->and(array_column($envio['template']['components'][0]['parameters'], 'text'))
        ->toBe(['Dueño', 'Estudio estudio-b', '12 de octubre', 'https://agendauno.mx/entrar?estudio=estudio-b&volver=%2Frenta']);

    // Correrlo otra vez no repite nada.
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toHaveCount(1)->and($this->meta->envios)->toHaveCount(1);

    // Si le extienden la prueba, se avisa de la nueva fecha.
    Estudio::query()->where('slug', 'estudio-a')->update(['trial_termina_en' => '2026-10-13']);
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toHaveCount(2);
});

it('avisa la renta lista, la vencida y el pago recibido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    whatsAppParaDuenos();
    duenoAceptoWhatsApp('estudio-a');
    $cargo = cargoRentaPendiente($e);

    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    $renta = CargoRenta::query()->where('ulid', $cargo)->firstOrFail();
    $periodo = now()->subMonthNoOverflow()->locale('es')->isoFormat('MMMM [de] YYYY');
    expect(asuntosA('a@correo.mx'))->toBe(["Tu renta de {$periodo} está lista"]);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => str_contains($m->cuerpoMensaje, '$1,499.00 MXN'));
    expect(end($this->meta->envios)['template']['name'])->toBe('agendauno_renta_emitida');

    // Pasó el vencimiento y sigue sin pagarse.
    $this->travelTo($renta->vence_en->copy()->addDays(2)->setTime(18, 0));
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toContain("Tu renta de {$periodo} venció")
        ->and(end($this->meta->envios)['template']['name'])->toBe('agendauno_renta_vencida');

    // La paga.
    $renta->forceFill(['estado' => 'pagado', 'pagado_en' => now()])->save();
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toContain("Recibimos tu pago de {$periodo}")
        ->and(end($this->meta->envios)['template']['name'])->toBe('agendauno_pago_recibido');

    // Cada uno una sola vez, por correo y por WhatsApp.
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toHaveCount(3)
        ->and($this->meta->envios)->toHaveCount(3);

    // El superadmin los ve en la ficha del negocio, lo último primero.
    $avisos = $this->getJson('/api/v1/plataforma/estudios/estudio-a', conPlataforma())->assertOk()->json('data.avisos');
    expect($avisos)->toHaveCount(6)
        ->and($avisos[0])->toMatchArray(['tipo' => 'pago_recibido', 'estado' => 'enviado']);
});

it('sin WhatsApp con los dueños solo va el correo; si se apaga con avisos en cola, se descartan', function (): void {
    $this->travelTo('2026-10-10 17:00:00');
    estudioConSesion('estudio-a', 'a@correo.mx');
    Estudio::query()->where('slug', 'estudio-a')->update(['trial_termina_en' => '2026-10-12']);
    duenoAceptoWhatsApp('estudio-a');

    // Apagado: solo correo.
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosA('a@correo.mx'))->toHaveCount(1)->and($this->meta->envios)->toBe([]);

    // Encendido pero Meta no lo acepta: queda fallido; al apagarlo, se descarta.
    Estudio::query()->where('slug', 'estudio-a')->update(['trial_termina_en' => '2026-10-13']);
    whatsAppParaDuenos();
    $this->meta->falla = true;
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    $whatsapp = fn (): AvisoDueno => AvisoDueno::query()->where('canal', 'whatsapp')->firstOrFail();
    expect($whatsapp()->estado->value)->toBe('fallido');

    whatsAppParaDuenos(false);
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect($whatsapp()->estado->value)->toBe('descartado');
});

it('si un aviso no sale tras varios intentos, el superadmin recibe una alerta', function (): void {
    $this->travelTo('2026-10-10 17:00:00');
    estudioConSesion('estudio-a', 'a@correo.mx');
    Estudio::query()->where('slug', 'estudio-a')->update(['trial_termina_en' => '2026-10-12']);
    duenoAceptoWhatsApp('estudio-a');
    whatsAppParaDuenos();
    $this->meta->falla = true;

    foreach (range(1, 4) as $vez) {
        $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    }

    // Tres intentos y ya no se reintenta.
    expect($this->meta->envios)->toHaveCount(3)
        ->and(AlertaPlataforma::query()->where('tipo', 'whatsapp_fallido')->value('mensaje'))->toContain('prueba_por_terminar');
});

it('una renta vencida le llega al superadmin por correo y la puede suspender desde Cobros', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // El correo que el superadmin capturó en su panel (gana sobre ALERTAS_CORREO).
    Config::set('agendauno.alertas.correo', 'env@agendauno.mx');
    $this->putJson('/api/v1/plataforma/configuracion', ['correo_alertas' => 'super@agendauno.mx'], conPlataforma())
        ->assertOk()->assertJsonPath('data.correo_alertas', 'super@agendauno.mx');
    $cargo = cargoRentaPendiente($e);
    $renta = CargoRenta::query()->where('ulid', $cargo)->firstOrFail();

    // Aún no vence: no hay alerta ni aparece en «Vencidos».
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(AlertaPlataforma::query()->where('tipo', 'renta_vencida')->exists())->toBeFalse();
    $this->getJson('/api/v1/plataforma/cobros?vencidos=1', conPlataforma())->assertOk()->assertJsonCount(0, 'data');

    $this->travelTo($renta->vence_en->copy()->addDays(2)->setTime(18, 0));
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    $alerta = AlertaPlataforma::query()->where('tipo', 'renta_vencida')->sole();
    expect($alerta->mensaje)->toContain('Estudio estudio-a')->toContain('$1,499.00 MXN')->toContain('Cobros')
        ->and($alerta->veces)->toBe(1);

    $this->artisan('agendauno:enviar-alertas')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('super@agendauno.mx')
        && str_contains($m->cuerpoMensaje, 'venció'));

    // En Cobros → Vencidos, con el estado del negocio para suspenderlo.
    $vencidos = $this->getJson('/api/v1/plataforma/cobros?vencidos=1', conPlataforma())->assertOk()->json('data');
    expect($vencidos)->toHaveCount(1)
        ->and($vencidos[0])->toMatchArray(['id' => $cargo, 'estudio_slug' => 'estudio-a', 'vencido' => true]);
    $this->postJson('/api/v1/plataforma/estudios/estudio-a/suspender', [], conPlataforma())->assertOk();
    expect($this->getJson('/api/v1/plataforma/cobros?vencidos=1', conPlataforma())->json('data.0.estudio_estado'))->toBe('suspended');
});

it('guardar el correo del superadmin no borra la llave de FacturAPI', function (): void {
    $this->putJson('/api/v1/plataforma/configuracion', ['facturapi_llave' => 'sk_test_llave'], conPlataforma())
        ->assertOk()->assertJsonPath('data.facturapi_configurada', true);
    $this->putJson('/api/v1/plataforma/configuracion', ['correo_alertas' => 'super@agendauno.mx'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.facturapi_configurada', true)
        ->assertJsonPath('data.correo_alertas', 'super@agendauno.mx');
    // Vacío vuelve al de ALERTAS_CORREO.
    Config::set('agendauno.alertas.correo', 'env@agendauno.mx');
    $this->putJson('/api/v1/plataforma/configuracion', ['correo_alertas' => null], conPlataforma())
        ->assertOk()->assertJsonPath('data.correo_alertas', 'env@agendauno.mx');
});

it('el superadmin ve si el dueño recibió y leyó el aviso por WhatsApp', function (): void {
    $this->travelTo('2026-10-10 17:00:00');
    estudioConSesion('estudio-a', 'a@correo.mx');
    Estudio::query()->where('slug', 'estudio-a')->update(['trial_termina_en' => '2026-10-12']);
    duenoAceptoWhatsApp('estudio-a');
    whatsAppParaDuenos();
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();

    // Sin App Secret, fuera de producción se acepta sin firma.
    $this->postJson('/api/v1/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
        'changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.prueba', 'status' => 'read', 'timestamp' => '1791000000']]]]],
    ]]])->assertOk()->assertJsonPath('data.aplicados', 1);

    $aviso = collect($this->getJson('/api/v1/plataforma/estudios/estudio-a', conPlataforma())->json('data.avisos'))->firstWhere('canal', 'whatsapp');
    expect($aviso)->toMatchArray(['estado' => 'enviado', 'entregado' => true, 'leido' => true]);
});
