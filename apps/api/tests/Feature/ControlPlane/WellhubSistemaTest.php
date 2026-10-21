<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\SolicitudWellhub;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| AgendaUno como sistema integrado de Wellhub (ADR 0202): cuando un gimnasio elige a
| AgendaUno, Wellhub avisa a la plataforma con su Gym ID y los campos que se le piden.
| Con el código del negocio, el Gym ID se le pone solo; sin él, lo asigna el superadmin.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config(['agendauno.integraciones.wellhub_webhook_secret' => 'secreto-del-sistema', 'agendauno.plataforma.token' => 'token-plataforma']);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El aviso de alta, firmado como firma Wellhub.
 *
 * @param  list<array{key: string, value: string}>  $campos
 */
function avisoDeAltaWellhub(string $evento, string $gym, array $campos, string $secreto = 'secreto-del-sistema'): TestResponse
{
    $cuerpo = (string) json_encode([
        'event_type' => 'system-integration-requested',
        'event_data' => [
            'gym_id' => (int) $gym, 'gym_name' => 'Fluo Roma', 'partner_id' => '2cff9739-5cd2-4299-8753-ba56aafb9c92',
            'custom_fields' => $campos, 'event_id' => $evento, 'timestamp' => now()->getTimestampMs(),
        ],
    ]);

    return test()->call('POST', '/api/v1/webhooks/wellhub/sistema', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GYMPASS_SIGNATURE' => strtoupper(hash_hmac('sha1', $cuerpo, $secreto)),
    ], $cuerpo);
}

/** El Gym ID de Wellhub que tiene el negocio. */
function gymIdDelNegocio(string $slug): ?string
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(),
        fn (): ?string => IntegracionTenant::query()->where('proveedor', 'wellhub')->first()?->llaves()['gym_id'] ?? null);
}

it('con el código del negocio en sus campos, el Gym ID se le pone solo (y el mismo aviso otra vez no repite nada)', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');

    avisoDeAltaWellhub('ev-1', '445566', [['key' => 'cnpj', 'value' => 'X'], ['key' => 'cms_id', 'value' => 'FLUO']])->assertOk();
    avisoDeAltaWellhub('ev-1', '445566', [['key' => 'cms_id', 'value' => 'fluo']])->assertOk();

    expect(gymIdDelNegocio($e['slug']))->toBe('445566')
        ->and(SolicitudWellhub::query()->count())->toBe(1)
        ->and(SolicitudWellhub::query()->first()?->estado)->toBe('asignada');
    // El dueño ve el código que se le da a Wellhub y su Gym ID ya capturado (apagada).
    $wellhub = collect($this->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))->json('data'))->firstWhere('proveedor', 'wellhub');
    expect($wellhub)->toMatchArray(['activa' => false, 'codigo_para_plataforma' => 'fluo'])
        ->and($wellhub['llaves_configuradas'])->toBe(['gym_id']);
});

it('sin la firma de Wellhub no se acepta', function (): void {
    avisoDeAltaWellhub('ev-2', '445566', [], 'otro-secreto')->assertUnauthorized();
    expect(SolicitudWellhub::query()->count())->toBe(0);
});

it('sin saber de qué negocio es, el superadmin lo asigna; si el negocio ya tiene otro Gym ID, se dice', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    avisoDeAltaWellhub('ev-3', '778899', [['key' => 'cms_id', 'value' => 'no-existe']])->assertOk();

    $pendientes = $this->getJson('/api/v1/plataforma/wellhub/solicitudes', conPlataforma())->assertOk()->json('data');
    expect($pendientes)->toHaveCount(1)->and($pendientes[0])->toMatchArray(['gym_id' => '778899', 'gimnasio' => 'Fluo Roma', 'estado' => 'pendiente']);

    $this->postJson("/api/v1/plataforma/wellhub/solicitudes/{$pendientes[0]['id']}/asignar", ['estudio' => 'fluo'], conPlataforma())
        ->assertOk()->assertJsonPath('data.estado', 'asignada')->assertJsonPath('data.negocio.slug', 'fluo');
    expect(gymIdDelNegocio($e['slug']))->toBe('778899');

    // Otro gimnasio para el mismo negocio: ya tiene uno.
    avisoDeAltaWellhub('ev-4', '111222', [])->assertOk();
    $otra = (string) $this->getJson('/api/v1/plataforma/wellhub/solicitudes', conPlataforma())->json('data.0.id');
    $this->postJson("/api/v1/plataforma/wellhub/solicitudes/{$otra}/asignar", ['estudio' => 'fluo'], conPlataforma())
        ->assertUnprocessable()->assertJsonPath('meta.errors.estudio.0', 'El negocio ya tiene otro Gym ID de Wellhub (778899): revísalo con el dueño.');
    $this->postJson("/api/v1/plataforma/wellhub/solicitudes/{$otra}/descartar", [], conPlataforma())->assertOk()->assertJsonPath('data.estado', 'descartada');
});
