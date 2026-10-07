<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| WhatsApp con los dueños (ADR 0070). Si el superadmin lo encendió, al registrar su
| negocio el dueño puede confirmar su WhatsApp con un código que le llega con la
| plantilla de autenticación; con el comprobante, el negocio queda con su WhatsApp
| verificado y la aceptación de avisos. Es opcional y tiene topes (cada código cuesta).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->meta = (object) ['envios' => [], 'falla' => false];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graph.facebook.com')) {
            $this->meta->envios[] = $request->data();
            if ($this->meta->falla) {
                return Http::response(['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']], 404);
            }

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.prueba']]]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function whatsAppConDuenos(bool $duenos = true): void
{
    test()->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => $duenos, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
}

function pedirCodigo(string $telefono = '55 1234 5678'): TestResponse
{
    return test()->postJson('/api/v1/registro/whatsapp/codigo', ['contacto_whatsapp_pais' => '+52', 'contacto_telefono' => $telefono]);
}

function confirmarCodigo(string $codigo, string $telefono = '55 1234 5678'): TestResponse
{
    return test()->postJson('/api/v1/registro/whatsapp/verificar', ['contacto_whatsapp_pais' => '52', 'contacto_telefono' => $telefono, 'codigo' => $codigo]);
}

/**
 * El último código que le llegó por WhatsApp.
 */
function ultimoCodigo(): string
{
    $envio = end(test()->meta->envios);

    return (string) $envio['template']['components'][0]['parameters'][0]['text'];
}

function registrarConWhatsApp(string $slug, ?string $comprobante, string $telefono = '55 1234 5678'): TestResponse
{
    return test()->postJson('/api/v1/registro', [
        'nombre' => 'Barbería '.$slug, 'slug' => $slug,
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $slug.'@correo.mx',
        'contacto_whatsapp_pais' => '52', 'contacto_telefono' => $telefono,
        'whatsapp_verificacion' => $comprobante,
        'pais' => 'MX', 'acepta_terminos' => true,
    ]);
}

it('apagado no se ofrece ni se manda nada', function (): void {
    $this->getJson('/api/v1/registro/whatsapp')->assertOk()->assertJsonPath('data.disponible', false);
    // Con WhatsApp solo para los negocios, tampoco.
    $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => true, 'duenos' => false, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
    $this->getJson('/api/v1/registro/whatsapp')->assertOk()->assertJsonPath('data.disponible', false);

    pedirCodigo()->assertUnprocessable()->assertJsonValidationErrors(['contacto_telefono'], 'meta.errors');
    expect($this->meta->envios)->toBe([]);
});

it('el dueño confirma su WhatsApp con el código y su negocio queda verificado', function (): void {
    whatsAppConDuenos();
    $this->getJson('/api/v1/registro/whatsapp')->assertOk()->assertJsonPath('data.disponible', true);

    pedirCodigo()->assertCreated();
    $envio = $this->meta->envios[0];
    $codigo = ultimoCodigo();
    expect($envio['to'])->toBe('525512345678')
        ->and($envio['template']['name'])->toBe('agendauno_codigo_verificacion')
        ->and($envio['template']['language'])->toBe(['code' => 'es_MX'])
        ->and($codigo)->toMatch('/^\d{6}$/')
        // El botón «Copiar código» también lleva el código.
        ->and($envio['template']['components'][1])->toMatchArray(['type' => 'button', 'sub_type' => 'url', 'index' => '0'])
        ->and($envio['template']['components'][1]['parameters'][0]['text'])->toBe($codigo);

    $otro = $codigo === '000000' ? '111111' : '000000';
    confirmarCodigo($otro)->assertUnprocessable()->assertJsonPath('meta.errors.codigo.0', 'El código no es correcto.');
    $comprobante = (string) confirmarCodigo($codigo)->assertOk()->json('data.verificacion');

    // El comprobante es de ese número: con otro no sirve.
    registrarConWhatsApp('otra', $comprobante, '55 9999 8888')
        ->assertUnprocessable()->assertJsonValidationErrors(['whatsapp_verificacion'], 'meta.errors');

    registrarConWhatsApp('barberia-a', $comprobante)->assertCreated();
    $estudio = Estudio::query()->where('slug', 'barberia-a')->firstOrFail();
    expect($estudio->contacto_whatsapp_verificado_en)->not->toBeNull()
        ->and($estudio->contacto_whatsapp_aceptado_en)->not->toBeNull();
    $this->getJson("/api/v1/plataforma/estudios/{$estudio->slug}", conPlataforma())
        ->assertOk()->assertJsonPath('data.contacto.whatsapp_verificado', true);

    // Un solo uso.
    registrarConWhatsApp('barberia-b', $comprobante)
        ->assertUnprocessable()->assertJsonValidationErrors(['whatsapp_verificacion'], 'meta.errors');
    // Sin verificar, el registro sigue igual.
    registrarConWhatsApp('barberia-c', null)->assertCreated();
    expect(Estudio::query()->where('slug', 'barberia-c')->value('contacto_whatsapp_verificado_en'))->toBeNull();
});

it('cada código cuesta: espera entre envíos y tope por número', function (): void {
    whatsAppConDuenos();
    $this->travelTo('2026-09-29 10:00:00');

    pedirCodigo()->assertCreated();
    pedirCodigo()->assertUnprocessable()->assertJsonPath('meta.errors.contacto_telefono.0', 'Espera un minuto antes de pedir otro código.');
    $this->travel(61)->seconds();
    pedirCodigo()->assertCreated();
    $this->travel(61)->seconds();
    pedirCodigo()->assertCreated();
    // Tope de la plataforma: 3 por número en una hora.
    $this->travel(61)->seconds();
    pedirCodigo()->assertUnprocessable()->assertJsonValidationErrors(['contacto_telefono'], 'meta.errors');
    expect($this->meta->envios)->toHaveCount(3);
});

it('el código vence y aguanta pocos intentos', function (): void {
    whatsAppConDuenos();

    pedirCodigo()->assertCreated();
    $codigo = ultimoCodigo();
    $otro = $codigo === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $intento) {
        confirmarCodigo($otro)->assertUnprocessable();
    }
    // Agotó los intentos: ni el correcto sirve.
    confirmarCodigo($codigo)->assertUnprocessable()->assertJsonPath('meta.errors.codigo.0', 'Demasiados intentos. Pide un código nuevo.');

    $this->travel(61)->seconds();
    pedirCodigo()->assertCreated();
    $this->travel(11)->minutes();
    confirmarCodigo(ultimoCodigo())->assertUnprocessable()->assertJsonPath('meta.errors.codigo.0', 'El código venció. Pide uno nuevo.');
});

it('si Meta no lo manda, el dueño lo sabe y el superadmin recibe una alerta', function (): void {
    whatsAppConDuenos();
    $this->meta->falla = true;

    pedirCodigo()->assertUnprocessable()->assertJsonValidationErrors(['contacto_telefono'], 'meta.errors');
    expect(AlertaPlataforma::query()->where('tipo', 'whatsapp_fallido')->value('mensaje'))->toContain('132001');
});
