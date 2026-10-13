<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Check-in automático de Wellhub y TotalPass y sus visitantes (ADR 0148): la plataforma
| avisa del check-in y la visita se valida sola. Quien viene así es visitante de la
| plataforma, nunca persona ni alumno del negocio; su visita ocupa lugar en la clase y
| se ve en el apartado de Reportes.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

const SECRETO_WELLHUB_PRUEBA = 'secreto-de-wellhub';

/**
 * Activa Wellhub con su llave secreta y devuelve la dirección de sus avisos.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function avisosDeWellhub(array $e): string
{
    return (string) test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true,
        'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566', 'webhook_secret' => SECRETO_WELLHUB_PRUEBA],
    ], conBearer($e['bearer']))->assertOk()->json('data.automatico.aviso_url');
}

/**
 * El aviso `checkin` de Wellhub, firmado como lo firma Wellhub.
 *
 * @param  array<string, mixed>  $usuario
 */
function avisoWellhub(string $url, array $usuario = [], ?string $firma = null, int $gimnasio = 445566): TestResponse
{
    $cuerpo = (string) json_encode([
        'event_type' => 'checkin',
        'event_data' => [
            'user' => [
                'unique_token' => '1234567890123',
                'first_name' => 'Ana',
                'last_name' => 'Pérez',
                'email' => 'ana@correo.mx',
                'phone_number' => '525512345678',
                ...$usuario,
            ],
            'location' => ['lat' => 19.4, 'lon' => -99.1],
            'gym' => ['id' => $gimnasio, 'title' => 'Fluo', 'product' => ['id' => 1, 'description' => 'Plan Gold']],
            'timestamp' => now()->timestamp,
        ],
    ]);

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GYMPASS_SIGNATURE' => $firma ?? strtoupper(hash_hmac('sha1', $cuerpo, SECRETO_WELLHUB_PRUEBA)),
    ], $cuerpo);
}

/**
 * Lugares ocupados de la clase en la agenda del equipo.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ocupadosEnAgenda(array $e, string $sesion): int
{
    $fila = collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $sesion);

    return (int) ($fila['ocupados'] ?? -1);
}

/**
 * Cuántas personas tiene el negocio (los visitantes no lo son).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function personasDelNegocio(array $e): int
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    return app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): int => PersonaTenant::query()->count());
}

it('Wellhub avisa del check-in y la visita se valida sola, en su clase y sin ser persona del negocio', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-01 06:10:00');
    Http::fake(['*/access/v1/validate' => Http::response(['metadata' => ['total' => 1]], 200)]);
    $url = avisosDeWellhub($e);
    $personas = personasDelNegocio($e);

    expect($url)->toContain("/api/v1/webhooks/bienestar/{$e['slug']}/wellhub/");
    $this->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))
        ->assertJsonPath('data.0.automatico.listo', true);

    avisoWellhub($url)->assertOk();

    // Se validó con Wellhub (Access Control), como a mano: eso genera el pago.
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/access/v1/validate')
        && $r['gympass_id'] === '1234567890123' && $r->hasHeader('X-Gym-Id', '445566'));

    $visitas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->assertOk()->json('data');
    expect($visitas)->toHaveCount(1)
        ->and($visitas[0]['estado'])->toBe('validado')
        ->and($visitas[0]['origen'])->toBe('automatico')
        ->and($visitas[0]['usuario'])->toBe('Ana Pérez')
        ->and(ocupadosEnAgenda($e, $sesion))->toBe(1)
        // No es persona ni alumno del negocio.
        ->and(personasDelNegocio($e))->toBe($personas);

    // El mismo aviso otra vez (Wellhub reintenta): la misma visita.
    avisoWellhub($url)->assertOk();
    expect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->json('data'))->toHaveCount(1);
});

it('no acepta avisos sin el token del negocio, con otra firma, de otro gimnasio o con la integración apagada', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    Http::fake(['*/access/v1/validate' => Http::response([], 200)]);
    $url = avisosDeWellhub($e);

    avisoWellhub(preg_replace('#/[^/]+$#', '/token-falso', $url) ?? '')->assertNotFound();
    avisoWellhub($url, [], 'FIRMAFALSA')->assertUnauthorized();
    // De otro gimnasio: se recibe pero no se valida.
    avisoWellhub($url, ['unique_token' => '9999999999999'], null, 111111)->assertOk();
    Http::assertNothingSent();

    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", ['activa' => false], conBearer($e['bearer']))->assertOk();
    avisoWellhub($url)->assertNotFound();
});

it('si Wellhub no responde se reintenta; vencido el check-in, la visita queda sin verificar', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-01 06:10:00');
    Http::fake(['*/access/v1/validate' => Http::response([], 503)]);
    $url = avisosDeWellhub($e);

    avisoWellhub($url)->assertOk();
    // Aún vigente: queda pendiente para reintentar, sin visita aprobada.
    expect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->json('data'))->toBe([]);

    $this->travel(25)->minutes();
    $this->artisan('agendauno:procesar-plataformas')->assertSuccessful();

    $visitas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->json('data');
    expect($visitas)->toHaveCount(1)
        ->and($visitas[0]['estado'])->toBe('no_verificable')
        ->and($visitas[0]['origen'])->toBe('automatico');
});

it('con varias clases a esa hora la visita queda sin clase y recepción la asigna al registrarla', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    $semilla = agendaSemilla($e);
    $primera = crearSesionTenant($e, $semilla, 10, '2026-10-01 06:10:00');
    crearSesionTenant($e, $semilla, 10, '2026-10-01 06:20:00');
    Http::fake(['*/access/v1/validate' => Http::response([], 200)]);
    $url = avisosDeWellhub($e);

    avisoWellhub($url)->assertOk();
    expect($this->getJson("/api/v1/app/{$e['slug']}/reportes/plataformas?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data.resumen.sin_clase'))->toBe(1);

    // Recepción la registra en su clase con el Wellhub ID: es la misma visita.
    $this->postJson("/api/v1/app/{$e['slug']}/checkins", [
        'proveedor' => 'wellhub', 'sesion_id' => $primera, 'codigo' => '1234567890123',
    ], conBearer($e['bearer']))->assertSuccessful();
    expect($this->getJson("/api/v1/app/{$e['slug']}/reportes/plataformas?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->json('data.resumen'))->toMatchArray(['visitas' => 1, 'sin_clase' => 0])
        ->and(ocupadosEnAgenda($e, $primera))->toBe(1);
});

it('TotalPass: con la llave de la sede registra su aviso y confirma cada check-in', function (): void {
    config(['agendauno.integraciones.totalpass_partner_api_key' => 'llave-de-agendauno']);
    $e = estudioConSesion('fluo-tp', 'f@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-01 06:10:00');
    Http::fake([
        '*/partner/auth' => Http::response(['token' => 'jwt-de-la-sede'], 201),
        '*/partner/webhook/create' => Http::response(['status' => 'success', 'code' => 201], 201),
        // Un check-in vencido no se confirma.
        '*/api/v1/webhook_confirmations/*' => fn (Request $r) => str_ends_with($r->url(), 'tok_vencido')
            ? Http::response(['errors' => [['label' => 'check_in_expired_alert']]], 422)
            : Http::response('1', 200),
    ]);

    $integracion = $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", [
        'activa' => true,
        'credenciales' => ['api_key' => 'tp_llave', 'codigo_gimnasio' => 'G1', 'place_api_key' => str_repeat('a', 36)],
    ], conBearer($e['bearer']))->assertOk();
    expect($integracion->json('data.automatico.listo'))->toBeTrue();
    $url = (string) $integracion->json('data.automatico.aviso_url');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/partner/webhook/create')
        && $r['webhook_url'] === $url && $r['webhook_type'] === 'CHECKIN' && $r->hasHeader('Authorization', 'Bearer jwt-de-la-sede'));

    $this->postJson($url, [
        'event' => 'CHECK_IN_CREATED',
        'check_in' => ['started_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(30)->toIso8601String(), 'plan_code' => 'TP3'],
        'user' => ['name' => 'Luis Gómez', 'email' => 'luis@correo.mx', 'phone' => '5511112222', 'code' => 'TP-00042'],
        // La confirmación se arma con el host de TotalPass, no con el que venga aquí.
        'endpoint' => 'https://otro-sitio.example/api/v1/webhook_confirmations/tok_abc123',
    ])->assertOk();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://admin.totalpass.com/api/v1/webhook_confirmations/tok_abc123');
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'otro-sitio.example'));
    $visitas = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->json('data');
    expect($visitas)->toHaveCount(1)
        ->and($visitas[0]['estado'])->toBe('validado')
        ->and($visitas[0]['usuario'])->toBe('Luis Gómez');

    // Un check-in vencido no se registra.
    $this->postJson($url, ['event' => 'CHECK_IN_CREATED', 'user' => ['code' => 'TP-7'], 'endpoint' => 'https://x/api/v1/webhook_confirmations/tok_vencido'])->assertOk();
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $resultado = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => EventoPlataformaTenant::query()->latest('id')->first());
    expect($resultado?->estado)->toBe('descartado')
        ->and((string) $resultado?->resultado)->toContain('venció');
});

it('los visitantes se ven en Reportes, aparte de los alumnos; si el negocio lo registra, se dice que ya es alumno', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-01 06:10:00');
    Http::fake(['*/access/v1/validate' => Http::response([], 200)]);
    avisoWellhub(avisosDeWellhub($e))->assertOk();

    $resumen = $this->getJson("/api/v1/app/{$e['slug']}/reportes/plataformas?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($resumen['resumen'])->toMatchArray(['visitas' => 1, 'validadas' => 1, 'automaticas' => 1, 'visitantes' => 1, 'nuevos' => 1])
        ->and($resumen['por_proveedor'][0])->toBe(['proveedor' => 'wellhub', 'visitas' => 1, 'visitantes' => 1])
        ->and($resumen['por_clase'][0]['visitas'])->toBe(1);

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma", conBearer($e['bearer']))->assertOk();
    expect($lista->json('data.0'))->toMatchArray([
        'proveedor' => 'wellhub', 'nombre' => 'Ana Pérez', 'email' => 'ana@correo.mx', 'producto' => 'Plan Gold',
        'id_externo' => '1234567890123', 'visitas' => 1, 'es_alumno' => false,
    ]);
    $id = (string) $lista->json('data.0.id');
    expect($this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma/{$id}", conBearer($e['bearer']))->json('data.historial'))->toHaveCount(1);

    // No está en el directorio de alumnos.
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->pluck('email'))
        ->not->toContain('ana@correo.mx');

    // El negocio la registra por la vía de siempre: ya es alumna.
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ANA@correo.mx'], conBearer($e['bearer']))->assertCreated();
    expect($this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma?q=ana", conBearer($e['bearer']))->json('data.0.es_alumno'))->toBeTrue();

    // Solo quien ve los reportes.
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->getJson("/api/v1/app/{$e['slug']}/visitantes-plataforma", conBearer($recepcion))->assertForbidden();
});

it('la visita de la plataforma ocupa lugar: con cupo de uno, la clase queda sin lugares libres', function (): void {
    $e = estudioConSesion('fluo-wh', 'f@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 1, '2026-10-01 06:10:00');
    Http::fake(['*/access/v1/validate' => Http::response([], 200)]);
    avisoWellhub(avisosDeWellhub($e))->assertOk();

    $fila = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))->json('data'))
        ->firstWhere('id', $sesion);
    expect($fila['ocupados'])->toBe(1)
        ->and($fila['clase']['libres'] ?? $fila['libres'] ?? null)->toBe(0);
});
