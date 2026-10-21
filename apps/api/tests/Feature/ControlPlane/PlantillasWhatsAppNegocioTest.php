<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\MensajesWhatsAppTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Las plantillas de WhatsApp de cada negocio (ADR 0190): el negocio las escribe, la
| plataforma las manda a aprobar a Meta en su cuenta de WhatsApp Business y su estado
| sigue al de Meta. Aprobadas, sirven para campañas por WhatsApp, que solo llegan a
| quien aceptó sus avisos y gastan del saldo según su categoría.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->meta = (object) ['plantillas' => [], 'mensajes' => [], 'estado' => 'PENDING', 'motivo' => null, 'borradas' => []];

    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_contains($url, '/message_templates')) {
            if ($request->method() === 'POST') {
                $this->meta->plantillas[] = $request->data();

                return Http::response(['id' => '9001', 'status' => 'PENDING', 'category' => 'MARKETING']);
            }
            if ($request->method() === 'DELETE') {
                $this->meta->borradas[] = $url;

                return Http::response(['success' => true]);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response(['data' => [[
                'name' => $query['name'] ?? '',
                'status' => $this->meta->estado,
                'rejected_reason' => $this->meta->motivo ?? 'NONE',
            ]]]);
        }
        if (str_contains($url, 'graph.facebook.com')) {
            $this->meta->mensajes[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.count($this->meta->mensajes)]]]);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * La plataforma conecta WhatsApp con su cuenta de WhatsApp Business y lo activa en el
 * negocio.
 *
 * @param  array{slug: string}  $e
 */
function whatsappConPlantillasPropias(array $e, bool $conCuenta = true): void
{
    test()->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => true, 'duenos' => false, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
        'waba_id' => $conCuenta ? '5550001112' : '',
    ], conPlataforma())->assertOk()->assertJsonPath('data.waba_id', $conCuenta ? '5550001112' : '');
    test()->putJson("/api/v1/plataforma/estudios/{$e['slug']}/whatsapp", ['habilitado' => true], conPlataforma())->assertOk();
}

/**
 * Escribe la promo de octubre (marketing) y devuelve su id.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function plantillaDePromo(array $e): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/comunicaciones/whatsapp/plantillas", [
        'titulo' => 'Promo de octubre',
        'categoria' => 'marketing',
        'cuerpo' => 'Hola {{persona_nombre}}, en {{negocio}} tenemos 2x1 en clases este mes. Reserva desde tu cuenta.',
        'pie' => 'Responde BAJA para no recibir más',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.estado', 'borrador')->json('data.id');
}

/**
 * @param  array{slug: string}  $e
 */
function enNegocioPlantillas(array $e, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $hacer);
}

it('el negocio escribe su plantilla, la manda a Meta y queda aprobada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/comunicaciones/whatsapp/plantillas";

    // Sin la cuenta de WhatsApp Business de la plataforma, no se puede.
    whatsappConPlantillasPropias($e, false);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.disponible', false);
    whatsappConPlantillasPropias($e);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.disponible', true)
        ->assertJsonPath('meta.categorias.0.clave', 'utilidad')
        ->assertJsonPath('meta.categorias.0.mensajes', 1)
        ->assertJsonPath('meta.categorias.1.mensajes', 5);

    // Lo que Meta no acepta, o lo que no dice quién escribe, no se guarda.
    foreach ([
        'Hola {{persona_nombre}}, tenemos 2x1 en clases este mes.',
        'Hola {{persona_nombre}}, en {{negocio}} tu {{codigo}} vence pronto.',
        '{{persona_nombre}}, en {{negocio}} tenemos 2x1 este mes.',
        'Hola, en {{negocio}} tenemos 2x1 y en {{negocio}} más.',
    ] as $malo) {
        $this->postJson($url, ['titulo' => 'Promo', 'categoria' => 'marketing', 'cuerpo' => $malo], conBearer($e['bearer']))
            ->assertUnprocessable()->assertJsonValidationErrors(['cuerpo'], 'meta.errors');
    }

    $id = plantillaDePromo($e);
    $this->postJson("{$url}/{$id}/enviar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'en_revision');

    // Meta la recibió como marketing, en español de México, con {{1}}, {{2}} y ejemplos.
    $enviada = $this->meta->plantillas[0];
    expect($enviada['category'])->toBe('MARKETING')
        ->and($enviada['language'])->toBe('es_MX')
        ->and($enviada['name'])->toStartWith('n')
        ->and($enviada['components'][0]['text'])->toBe('Hola {{1}}, en {{2}} tenemos 2x1 en clases este mes. Reserva desde tu cuenta.')
        ->and($enviada['components'][0]['example']['body_text'][0][0])->toBe('Ana')
        ->and($enviada['components'][1])->toBe(['type' => 'FOOTER', 'text' => 'Responde BAJA para no recibir más']);

    // En revisión ya no se cambia.
    $this->putJson("{$url}/{$id}", ['titulo' => 'Otra', 'categoria' => 'utilidad', 'cuerpo' => 'Hola, en {{negocio}} cambió el horario de hoy.'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['estado'], 'meta.errors');

    // Meta la aprueba.
    $this->meta->estado = 'APPROVED';
    $this->postJson("{$url}/{$id}/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');
});

it('rechazada muestra el motivo y se corrige para mandarla otra vez con otro nombre; archivada se borra de Meta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    whatsappConPlantillasPropias($e);
    $url = "/api/v1/app/{$e['slug']}/comunicaciones/whatsapp/plantillas";
    $id = plantillaDePromo($e);
    $this->postJson("{$url}/{$id}/enviar", [], conBearer($e['bearer']))->assertOk();
    $nombre = PlantillaWhatsAppNegocio::query()->where('ulid', $id)->value('nombre_meta');

    // La revisión de la plataforma trae el rechazo.
    $this->meta->estado = 'REJECTED';
    $this->meta->motivo = 'INVALID_FORMAT';
    $this->artisan('agendauno:revisar-plantillas-whatsapp')->assertSuccessful();
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.estado', 'rechazada')
        ->assertJsonPath('data.0.motivo', 'INVALID_FORMAT')
        ->assertJsonPath('data.0.editable', true);

    $this->putJson("{$url}/{$id}", ['titulo' => 'Promo de octubre', 'categoria' => 'marketing', 'cuerpo' => 'Hola {{persona_nombre}}, este mes en {{negocio}} hay 2x1 en clases. Te esperamos.'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'borrador');
    expect(PlantillaWhatsAppNegocio::query()->where('ulid', $id)->value('nombre_meta'))->not->toBe($nombre);

    $this->postJson("{$url}/{$id}/enviar", [], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("{$url}/{$id}", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'archivada');
    expect($this->meta->borradas)->toHaveCount(1);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('una campaña por WhatsApp va con la plantilla aprobada, solo a quien aceptó, y gasta según su categoría', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    whatsappConPlantillasPropias($e);
    $url = "/api/v1/app/{$e['slug']}/comunicaciones/whatsapp/plantillas";
    $id = plantillaDePromo($e);
    $this->postJson("{$url}/{$id}/enviar", [], conBearer($e['bearer']))->assertOk();

    // Ana aceptó sus avisos por WhatsApp; Beto tiene celular pero no aceptó.
    foreach (['Ana' => '55 1234 5678', 'Beto' => '55 8765 4321'] as $nombre => $celular) {
        $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => $nombre, 'celular' => $celular, 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    }
    enNegocioPlantillas($e, fn () => PersonaTenant::query()->where('nombre', 'Ana')->update(['whatsapp_aceptado_en' => now(), 'recibe_promociones' => true]));
    enNegocioPlantillas($e, fn () => PersonaTenant::query()->where('nombre', 'Beto')->update(['recibe_promociones' => true]));

    // La plataforma cobra los mensajes; el negocio tiene saldo.
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['whatsapp.cobro_por_mensaje' => 1]], conPlataforma())->assertOk();
    enNegocioPlantillas($e, fn () => app(MensajesWhatsAppTenant::class)->acreditar(100, 'cargo:prueba', 'Paquete de 100 mensajes'));

    $campanas = "/api/v1/app/{$e['slug']}/comunicaciones";
    expect($this->getJson("{$campanas}/segmentos", conBearer($e['bearer']))->json('canales'))->toContain('whatsapp');

    // Sin aprobar no se manda.
    $this->postJson("{$campanas}/difusiones", ['segmento' => 'todos', 'canal' => 'whatsapp', 'plantilla_whatsapp' => $id], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['plantilla_whatsapp'], 'meta.errors');

    $this->meta->estado = 'APPROVED';
    $this->postJson("{$url}/{$id}/revisar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'aprobada');

    // Antes de enviar: a quién llega y cuánto gasta (marketing: 5 mensajes cada uno).
    $this->getJson("{$campanas}/alcance?segmento=todos&canal=whatsapp&plantilla_whatsapp={$id}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.elegibles', 1)
        ->assertJsonPath('data.mensajes', 5)
        ->assertJsonPath('data.costo_minor', 5 * 45);

    $this->postJson("{$campanas}/difusiones", ['segmento' => 'todos', 'canal' => 'whatsapp', 'plantilla_whatsapp' => $id], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.total', 1);
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    // Le llegó a Ana con la plantilla del negocio y sus valores.
    $mensaje = collect($this->meta->mensajes)->firstWhere('type', 'template');
    $nombre = PlantillaWhatsAppNegocio::query()->where('ulid', $id)->value('nombre_meta');
    expect($mensaje['to'])->toBe('525512345678')
        ->and($mensaje['template']['name'])->toBe($nombre)
        ->and($mensaje['template']['components'][0]['parameters'][0]['text'])->toBe('Ana');
    expect(enNegocioPlantillas($e, fn (): int => app(MensajesWhatsAppTenant::class)->disponibles()))->toBe(95);
});
