<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Los avisos de AgendaUno desde el panel del dueño (ADR 0072): ve a qué correo le
| llegan y, si la plataforma tiene WhatsApp con los dueños, verifica su número con un
| código (si no lo hizo al registrarse) y decide si también le llegan por WhatsApp.
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

it('sin WhatsApp con los dueños solo muestra el correo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/avisos-plataforma", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.correo', 'a@correo.mx')
        ->assertJsonPath('data.whatsapp', null);
});

it('el dueño verifica su WhatsApp desde su panel y puede dejar de recibirlos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => true, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
    $url = "/api/v1/app/{$e['slug']}/avisos-plataforma";

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.whatsapp', ['numero' => '+52 5512345678', 'verificado' => false, 'acepta' => false]);
    // Sin verificar no se puede aceptar.
    $this->putJson($url, ['acepta_whatsapp' => true], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['acepta_whatsapp'], 'meta.errors');

    $this->postJson("{$url}/whatsapp/codigo", [], conBearer($e['bearer']))->assertCreated();
    $envio = $this->meta->envios[0];
    expect($envio['to'])->toBe('525512345678')
        ->and($envio['template']['name'])->toBe('agendauno_codigo_verificacion');
    $codigo = (string) $envio['template']['components'][0]['parameters'][0]['text'];

    $this->postJson("{$url}/whatsapp/verificar", ['codigo' => $codigo === '000000' ? '111111' : '000000'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.codigo.0', 'El código no es correcto.');
    $this->postJson("{$url}/whatsapp/verificar", ['codigo' => $codigo], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.whatsapp.verificado', true)
        ->assertJsonPath('data.whatsapp.acepta', true);
    expect(Estudio::query()->where('slug', 'estudio-a')->value('contacto_whatsapp_verificado_en'))->not->toBeNull();

    // Ya no los quiere por WhatsApp: le siguen llegando por correo.
    $this->putJson($url, ['acepta_whatsapp' => false], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.whatsapp.acepta', false)
        ->assertJsonPath('data.whatsapp.verificado', true);
    expect(Estudio::query()->where('slug', 'estudio-a')->value('contacto_whatsapp_aceptado_en'))->toBeNull();

    // Queda en la bitácora del negocio.
    $acciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($e['bearer']))->json('data'))->pluck('accion');
    expect($acciones)->toContain('negocio.whatsapp_verificado')->toContain('negocio.whatsapp_retirado');
});

it('un alumno no ve ni cambia los avisos del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e);

    $this->getJson("/api/v1/app/{$e['slug']}/avisos-plataforma", conBearer($alumna['bearer']))->assertForbidden();
    $this->putJson("/api/v1/app/{$e['slug']}/avisos-plataforma", ['acepta_whatsapp' => false], conBearer($alumna['bearer']))->assertForbidden();
});

it('el dueño cambia su WhatsApp con el código que llega al número nuevo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => true, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
    $url = "/api/v1/app/{$e['slug']}/avisos-plataforma";
    $nuevo = ['contacto_whatsapp_pais' => '+52', 'contacto_telefono' => '55 8765 4321'];

    $this->postJson("{$url}/whatsapp/cambio/codigo", $nuevo, conBearer($e['bearer']))->assertCreated();
    $envio = $this->meta->envios[0];
    expect($envio['to'])->toBe('525587654321');
    $codigo = (string) $envio['template']['components'][0]['parameters'][0]['text'];

    // Con un código equivocado el número no cambia.
    $this->putJson("{$url}/whatsapp", [...$nuevo, 'codigo' => $codigo === '000000' ? '111111' : '000000'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.codigo.0', 'El código no es correcto.');
    expect(Estudio::query()->where('slug', 'estudio-a')->value('contacto_telefono'))->toBe('5512345678');

    $this->putJson("{$url}/whatsapp", [...$nuevo, 'codigo' => $codigo], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.numero', '+52 55 8765 4321')
        ->assertJsonPath('data.whatsapp', ['numero' => '+52 55 8765 4321', 'verificado' => true, 'acepta' => true]);
    // Es el mismo que aparece en su página.
    expect(Estudio::query()->where('slug', 'estudio-a')->firstOrFail()->whatsappCompleto())->toBe('+52 55 8765 4321');

    // Ya es el suyo: no se manda otro código.
    $this->postJson("{$url}/whatsapp/cambio/codigo", ['contacto_telefono' => '5587654321'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.contacto_telefono.0', 'Ese ya es el WhatsApp de tu negocio.');
    expect($this->meta->envios)->toHaveCount(1);

    $acciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($e['bearer']))->json('data'))->pluck('accion');
    expect($acciones)->toContain('negocio.whatsapp_cambiado');
});

it('sin WhatsApp con los dueños el número se cambia sin código y queda sin verificar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Estudio::query()->where('slug', 'estudio-a')->update(['contacto_whatsapp_verificado_en' => now(), 'contacto_whatsapp_aceptado_en' => now()]);

    $this->putJson("/api/v1/app/{$e['slug']}/avisos-plataforma/whatsapp", ['contacto_whatsapp_pais' => '57', 'contacto_telefono' => '300 123 4567'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.numero', '+57 300 123 4567')
        ->assertJsonPath('data.pais', '57')
        ->assertJsonPath('data.whatsapp', null);
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    expect($estudio->contacto_whatsapp_verificado_en)->toBeNull()
        ->and($estudio->contacto_whatsapp_aceptado_en)->toBeNull()
        ->and($this->meta->envios)->toBe([]);
});

it('cambiar el WhatsApp del negocio pide gestionar el negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/avisos-plataforma/whatsapp", ['contacto_telefono' => '5587654321'], conBearer($recepcion))
        ->assertForbidden();
    expect(Estudio::query()->where('slug', 'estudio-a')->value('contacto_telefono'))->toBe('5512345678');
});
